<?php
declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCA\OpenCase\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IDBConnection;
use OCP\Security\ICrypto;
use RuntimeException;

/**
 * Stores system certificates.
 *
 * - The PKCS#12 file is kept in the app's own data folder (IAppData), never at an
 *   arbitrary path. Rows with storage = 'filesystem' are legacy rows whose file
 *   could not be copied during upgrade; they keep working until re-registered.
 * - The PKCS#12 password is encrypted with ICrypto (key derived from the
 *   instance secret in config.php) and only decrypted when the certificate is loaded.
 */
class CertificateRepository {
	public const TABLE = 'opencase_certificate';
	public const STORAGE_APPDATA = 'appdata';
	public const STORAGE_FILESYSTEM = 'filesystem';
	public const APPDATA_FOLDER = 'certificates';

	private IAppData $appData;

	public function __construct(
		private IDBConnection $db,
		private ICrypto $crypto,
		IAppDataFactory $appDataFactory,
	) {
		$this->appData = $appDataFactory->get(Application::APP_ID);
	}

	/**
	 * Returns the certificate record with the password decrypted, or null if
	 * no certificate is registered for the code.
	 *
	 * @return array{code: string, filepath: string, storage: string, password: ?string}|null
	 */
	public function find(string $code): ?array {
		$row = $this->findRow($code);
		if ($row === null) {
			return null;
		}

		$row['password'] = $this->decryptPassword($row['password'] ?? null);
		return $row;
	}

	public function exists(string $code): bool {
		return $this->findRow($code) !== null;
	}

	/**
	 * Reads the raw PKCS#12 content for a record returned by find().
	 */
	public function readContent(array $record): string {
		if (($record['storage'] ?? self::STORAGE_FILESYSTEM) === self::STORAGE_APPDATA) {
			try {
				return $this->getFolder()->getFile($record['filepath'])->getContent();
			} catch (NotFoundException $e) {
				throw new RuntimeException('Certificate file not found in app data: ' . $record['filepath'], 0, $e);
			}
		}

		$filepath = $record['filepath'];
		if (!file_exists($filepath)) {
			throw new RuntimeException('Certificate file not found: ' . $filepath);
		}
		$content = file_get_contents($filepath);
		if ($content === false) {
			throw new RuntimeException('Failed to read certificate file: ' . $filepath);
		}
		return $content;
	}

	/**
	 * Stores the PKCS#12 content in app data and the encrypted password in the database.
	 */
	public function save(string $code, string $content, ?string $password): void {
		$fileName = self::fileNameFor($code);
		$this->writeAppDataFile($fileName, $content);

		$values = [
			'filepath' => $fileName,
			'storage' => self::STORAGE_APPDATA,
			'password' => $this->encryptPassword($password),
		];

		$qb = $this->db->getQueryBuilder();
		if ($this->exists($code)) {
			$qb->update(self::TABLE);
			foreach ($values as $column => $value) {
				$qb->set($column, $qb->createNamedParameter($value));
			}
			$qb->where($qb->expr()->eq('code', $qb->createNamedParameter($code)))
				->executeStatement();
		} else {
			$values['code'] = $code;
			$qb->insert(self::TABLE)
				->values(array_map(fn ($v) => $qb->createNamedParameter($v), $values))
				->executeStatement();
		}
	}

	public function delete(string $code): void {
		$row = $this->findRow($code);
		if ($row !== null && ($row['storage'] ?? '') === self::STORAGE_APPDATA) {
			try {
				$this->getFolder()->getFile($row['filepath'])->delete();
			} catch (NotFoundException) {
			}
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('code', $qb->createNamedParameter($code)))
			->executeStatement();
	}

	/**
	 * Raw rows (password still encrypted).
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE);
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	public function encryptPassword(?string $password): ?string {
		if ($password === null || $password === '') {
			return $password;
		}
		return $this->crypto->encrypt($password);
	}

	public function decryptPassword(?string $stored): ?string {
		if ($stored === null || $stored === '') {
			return $stored;
		}
		try {
			return $this->crypto->decrypt($stored);
		} catch (\Exception $e) {
			throw new RuntimeException(
				'Failed to decrypt certificate password. If the instance secret in config.php has changed, register the certificate again.',
				0,
				$e,
			);
		}
	}

	public function writeAppDataFile(string $fileName, string $content): void {
		$folder = $this->getFolder();
		try {
			$folder->getFile($fileName)->putContent($content);
		} catch (NotFoundException) {
			$folder->newFile($fileName, $content);
		}
	}

	public static function fileNameFor(string $code): string {
		return preg_replace('/[^A-Za-z0-9_-]/', '_', $code) . '.p12';
	}

	private function getFolder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::APPDATA_FOLDER);
		} catch (NotFoundException) {
			return $this->appData->newFolder(self::APPDATA_FOLDER);
		}
	}

	private function findRow(string $code): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('code', $qb->createNamedParameter($code)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row ?: null;
	}
}
