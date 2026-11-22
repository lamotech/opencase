<?php
declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use Doctrine\DBAL\Types\TextType;
use Doctrine\DBAL\Types\Type;
use OCA\OpenCase\Db\CertificateRepository;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Security\ICrypto;

/**
 * Secures stored system certificates:
 * - widens the password column so it can hold ICrypto ciphertext
 * - adds a storage column (appdata | filesystem)
 * - encrypts existing plaintext passwords
 * - copies existing certificate files into the app's own data folder
 */
class Version000020Date20261114000000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
		private ICrypto $crypto,
		private CertificateRepository $certificateRepository,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable(CertificateRepository::TABLE)) {
			return null;
		}

		$table = $schema->getTable(CertificateRepository::TABLE);

		$password = $table->getColumn('password');
		if (!($password->getType() instanceof TextType)) {
			$password->setType(Type::getType(Types::TEXT));
			$password->setLength(null);
			$password->setNotnull(false);
		}

		if (!$table->hasColumn('storage')) {
			$table->addColumn('storage', Types::STRING, [
				'length' => 20,
				'notnull' => true,
				'default' => CertificateRepository::STORAGE_FILESYSTEM,
			]);
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('*')->from(CertificateRepository::TABLE)->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		foreach ($rows as $row) {
			$code = $row['code'];
			$update = [];

			// 1. Encrypt plaintext passwords (skip values that already decrypt).
			$password = $row['password'];
			if ($password !== null && $password !== '' && !$this->isEncrypted($password)) {
				$update['password'] = $this->crypto->encrypt($password);
				$output->info("Encrypted stored password for certificate '$code'");
			}

			// 2. Copy the certificate file into app data.
			if (($row['storage'] ?? CertificateRepository::STORAGE_FILESYSTEM) !== CertificateRepository::STORAGE_APPDATA) {
				$filepath = $row['filepath'];
				$content = is_readable($filepath) ? file_get_contents($filepath) : false;
				if ($content !== false && $content !== '') {
					$fileName = CertificateRepository::fileNameFor($code);
					$this->certificateRepository->writeAppDataFile($fileName, $content);
					$update['filepath'] = $fileName;
					$update['storage'] = CertificateRepository::STORAGE_APPDATA;
					$output->info("Copied certificate '$code' into app data. The original file at $filepath can now be deleted.");
				} else {
					$output->warning("Could not read certificate file for '$code' at $filepath. Run occ opencase:register-certificate $code to register it again.");
				}
			}

			if ($update === []) {
				continue;
			}

			$uqb = $this->db->getQueryBuilder();
			$uqb->update(CertificateRepository::TABLE);
			foreach ($update as $column => $value) {
				$uqb->set($column, $uqb->createNamedParameter($value));
			}
			$uqb->where($uqb->expr()->eq('code', $uqb->createNamedParameter($code)))
				->executeStatement();
		}
	}

	private function isEncrypted(string $value): bool {
		try {
			$this->crypto->decrypt($value);
			return true;
		} catch (\Exception) {
			return false;
		}
	}
}
