<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<BulkUpdateItem>
 */
class BulkUpdateItemMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_bulkupdate_items', BulkUpdateItem::class);
    }

    /**
     * All items of a bulk-update job, in insertion order.
     *
     * @return BulkUpdateItem[]
     */
    public function findByBulkUpdateId(int $bulkUpdateId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('bulkupdate_id', $qb->createNamedParameter($bulkUpdateId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Items of a bulk-update job at a given status, in insertion order.
     *
     * @return BulkUpdateItem[]
     */
    public function findByBulkUpdateIdAndStatus(int $bulkUpdateId, string $status): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('bulkupdate_id', $qb->createNamedParameter($bulkUpdateId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)))
            ->orderBy('id', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Per-status item counts for a set of bulk-update jobs, in one query.
     *
     * @param int[] $bulkUpdateIds
     * @return array<int, array<string, int>> bulkupdate_id => [status => count]
     */
    public function countsByBulkUpdateIds(array $bulkUpdateIds): array {
        if (empty($bulkUpdateIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('bulkupdate_id', 'status')
            ->selectAlias($qb->func()->count('*'), 'cnt')
            ->from($this->getTableName())
            ->where($qb->expr()->in('bulkupdate_id', $qb->createNamedParameter($bulkUpdateIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->groupBy('bulkupdate_id', 'status');

        $result = $qb->executeQuery();
        $counts = [];
        while ($row = $result->fetch()) {
            $counts[(int)$row['bulkupdate_id']][$row['status']] = (int)$row['cnt'];
        }
        $result->closeCursor();

        return $counts;
    }
}
