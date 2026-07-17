<?php
declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use DateTime;

class OrgSyncLogRepository {
	public function __construct(
		private IDBConnection $db,
	) {}

	public function insert(DateTime $syncTime, int $countReceived, int $created, int $updated, int $deactivated): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('opencase_org_log')
			->values([
				'sync_time' => $qb->createNamedParameter($syncTime->format('Y-m-d H:i:s')),
				'count_received' => $qb->createNamedParameter($countReceived, IQueryBuilder::PARAM_INT),
				'created' => $qb->createNamedParameter($created, IQueryBuilder::PARAM_INT),
				'updated' => $qb->createNamedParameter($updated, IQueryBuilder::PARAM_INT),
				'deactivated' => $qb->createNamedParameter($deactivated, IQueryBuilder::PARAM_INT),
			])->executeStatement();
	}

	public function findLatest(int $limit = 10): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('opencase_org_log')
			->orderBy('sync_time', 'DESC')
			->setMaxResults($limit);

		return $qb->executeQuery()->fetchAll();
	}
}

