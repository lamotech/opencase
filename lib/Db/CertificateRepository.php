<?php
declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\IDBConnection;

class CertificateRepository {
	public function __construct(
		private IDBConnection $db,
	) {}

	public function find(string $code): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('opencase_certificate')
			->where($qb->expr()->eq('code', $qb->createNamedParameter($code)));
		$row = $qb->executeQuery()->fetch();
		return $row ?: null;
	}

	public function insert(string $code, string $filepath, ?string $password = null): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('opencase_certificate')
			->values([
				'code' => $qb->createNamedParameter($code),
				'filepath' => $qb->createNamedParameter($filepath),
				'password' => $qb->createNamedParameter($password),
			])->executeStatement();
	}

	public function update(string $code, string $filepath, ?string $password = null): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('opencase_certificate')
			->set('filepath', $qb->createNamedParameter($filepath))
			->set('password', $qb->createNamedParameter($password))
			->where($qb->expr()->eq('code', $qb->createNamedParameter($code)))
			->executeStatement();
	}

	public function delete(string $code): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('opencase_certificate')
			->where($qb->expr()->eq('code', $qb->createNamedParameter($code)))
			->executeStatement();
	}

	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('opencase_certificate');
		return $qb->executeQuery()->fetchAll();
	}
}
