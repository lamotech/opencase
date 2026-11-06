<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ProcessMigration>
 */
class ProcessMigrationMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_process_migration', ProcessMigration::class);
    }

    public function find(int $id): ?ProcessMigration {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Latest migrations of a process, newest first.
     *
     * @return ProcessMigration[]
     */
    public function findByDefId(int $defId, int $limit = 10): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('process_def_id', $qb->createNamedParameter($defId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'DESC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }

    public function hasUnfinished(int $defId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from($this->getTableName())
            ->where($qb->expr()->eq('process_def_id', $qb->createNamedParameter($defId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->neq('status', $qb->createNamedParameter(ProcessMigration::STATUS_DONE)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $found = $result->fetchOne() !== false;
        $result->closeCursor();
        return $found;
    }
}
