<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<HistoryEntity>
 */
class HistoryMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_history', HistoryEntity::class);
    }

    /**
     * All history entries for a user, newest first.
     *
     * @return HistoryEntity[]
     */
    public function findByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('accessed_at', 'DESC')
            ->addOrderBy('id', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Find a specific history entry, or null if it doesn't exist.
     */
    public function findByUserAndEntity(string $userId, string $entity, int $key): ?HistoryEntity {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('entity', $qb->createNamedParameter($entity)))
            ->andWhere($qb->expr()->eq('entity_key', $qb->createNamedParameter($key, IQueryBuilder::PARAM_INT)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Delete the oldest entries for a user/entity beyond $keepCount.
     */
    public function trimOldest(string $userId, string $entity, int $keepCount): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('entity', $qb->createNamedParameter($entity)))
            ->orderBy('accessed_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setFirstResult($keepCount);

        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['id'];
        }
        $result->closeCursor();

        if (empty($ids)) {
            return;
        }

        $del = $this->db->getQueryBuilder();
        $del->delete($this->getTableName())
            ->where($del->expr()->in('id', $del->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        $del->executeStatement();
    }
}
