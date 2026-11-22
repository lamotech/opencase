<?php
declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use Doctrine\DBAL\Types\TextType;
use Doctrine\DBAL\Types\Type;
use OCA\OpenCase\Service\SecretCrypto;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Encrypts stored secrets:
 * - opencase_importlocations.mailbox_password (column widened to hold ICrypto ciphertext)
 * - the secret opencase_config values listed in SecretCrypto::CONFIG_KEYS
 *
 * Values already in ICrypto format are left untouched, so the step is safe to re-run.
 */
class Version000021Date20261114120000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
		private SecretCrypto $secretCrypto,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('opencase_importlocations')) {
			return null;
		}

		$column = $schema->getTable('opencase_importlocations')->getColumn('mailbox_password');
		if ($column->getType() instanceof TextType) {
			return null;
		}

		$column->setType(Type::getType(Types::TEXT));
		$column->setLength(null);
		$column->setNotnull(false);
		$column->setDefault(null);

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->encryptMailboxPasswords($output);
		$this->encryptConfigSecrets($output);
	}

	private function encryptMailboxPasswords(IOutput $output): void {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('id', 'mailbox_password')
			->from('opencase_importlocations')
			->where($qb->expr()->isNotNull('mailbox_password'))
			->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		$count = 0;
		foreach ($rows as $row) {
			$password = (string)$row['mailbox_password'];
			if ($password === '' || SecretCrypto::looksEncrypted($password)) {
				continue;
			}
			$uqb = $this->db->getQueryBuilder();
			$uqb->update('opencase_importlocations')
				->set('mailbox_password', $uqb->createNamedParameter($this->secretCrypto->encrypt($password)))
				->where($uqb->expr()->eq('id', $uqb->createNamedParameter((int)$row['id'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$count++;
		}
		if ($count > 0) {
			$output->info("Encrypted {$count} stored mailbox password(s).");
		}
	}

	private function encryptConfigSecrets(IOutput $output): void {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('config_key', 'config_value')
			->from('opencase_config')
			->where($qb->expr()->in('config_key', $qb->createNamedParameter(SecretCrypto::CONFIG_KEYS, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		foreach ($rows as $row) {
			$value = (string)$row['config_value'];
			if ($value === '' || SecretCrypto::looksEncrypted($value)) {
				continue;
			}
			$uqb = $this->db->getQueryBuilder();
			$uqb->update('opencase_config')
				->set('config_value', $uqb->createNamedParameter($this->secretCrypto->encrypt($value)))
				->where($uqb->expr()->eq('config_key', $uqb->createNamedParameter($row['config_key'])))
				->executeStatement();
			$output->info("Encrypted stored config value {$row['config_key']}.");
		}
	}
}
