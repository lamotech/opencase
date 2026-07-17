<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<FavoriteEntity>
 */
class FavoriteMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_favorites', FavoriteEntity::class);
    }

    /**
     * All favorites for a user, newest first.
     *
     * @return FavoriteEntity[]
     */
    public function findByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('added_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Find a specific favorite, or null if it doesn't exist.
     */
    public function findByUserAndEntity(string $userId, string $entity, int $key): ?FavoriteEntity {
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
     * Remove a specific favorite. No-op if it doesn't exist.
     */
    public function deleteByUserAndEntity(string $userId, string $entity, int $key): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('entity', $qb->createNamedParameter($entity)))
            ->andWhere($qb->expr()->eq('entity_key', $qb->createNamedParameter($key, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }
}
