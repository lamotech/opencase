<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ProcessScope>
 */
class ProcessScopeMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_process_scope', ProcessScope::class);
    }

    /**
     * All scope rows for the given processes, grouped by process_def_id.
     *
     * @param int[] $defIds
     * @return array<int, ProcessScope[]>
     */
    public function findGroupedByDefIds(array $defIds): array {
        if (empty($defIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->in('process_def_id', $qb->createNamedParameter($defIds, IQueryBuilder::PARAM_INT_ARRAY)));
        $grouped = [];
        foreach ($this->findEntities($qb) as $scope) {
            $grouped[$scope->getProcessDefId()][] = $scope;
        }
        return $grouped;
    }

    public function deleteByDefId(int $defId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('process_def_id', $qb->createNamedParameter($defId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
