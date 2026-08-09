<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Reminder>
 */
class ReminderMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_reminders', Reminder::class);
    }

    public function findById(int $id): ?Reminder {
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
     * @return Reminder[]
     */
    public function findByEntity(string $entity, int $entityKey): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('entity', $qb->createNamedParameter($entity)))
            ->andWhere($qb->expr()->eq('entity_key', $qb->createNamedParameter($entityKey, IQueryBuilder::PARAM_INT)))
            ->orderBy('deadline', 'ASC')
            ->addOrderBy('id', 'DESC');
        return $this->findEntities($qb);
    }

    /**
     * @return Reminder[]
     */
    public function findActiveByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('responsible_user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')))
            ->orderBy('deadline', 'ASC')
            ->addOrderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Returns the active reminder with the earliest deadline for an entity.
     */
    public function findNextActive(string $entity, int $entityKey): ?Reminder {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('entity', $qb->createNamedParameter($entity)))
            ->andWhere($qb->expr()->eq('entity_key', $qb->createNamedParameter($entityKey, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')))
            ->orderBy('deadline', 'ASC')
            ->setMaxResults(1);
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }
}
