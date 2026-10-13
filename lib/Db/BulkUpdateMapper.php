<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<BulkUpdate>
 */
class BulkUpdateMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_bulkupdate', BulkUpdate::class);
    }

    public function findById(int $id): ?BulkUpdate {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * All bulk-update jobs of a given entity type created by a user, newest first.
     *
     * @return BulkUpdate[]
     */
    public function findByUserAndEntity(string $userId, string $entity): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('created_by', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('entity', $qb->createNamedParameter($entity)))
            ->orderBy('created_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * All bulk-update jobs at a given status, oldest first — used by
     * ProcessBulkUpdate to pick up pending jobs in submission order.
     *
     * @return BulkUpdate[]
     */
    public function findByStatus(string $status): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter($status)))
            ->orderBy('created_at', 'ASC');

        return $this->findEntities($qb);
    }
}
