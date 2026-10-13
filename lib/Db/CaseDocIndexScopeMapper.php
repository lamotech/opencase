<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<CaseDocIndexScope>
 */
class CaseDocIndexScopeMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_casedocindex_scope', CaseDocIndexScope::class);
    }

    /**
     * All scope rows for the given rule ids, grouped by casedocindex_def_id.
     *
     * @param int[] $defIds
     * @return array<int, CaseDocIndexScope[]>
     */
    public function findGroupedByDefIds(array $defIds): array {
        if (empty($defIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->in('casedocindex_def_id', $qb->createNamedParameter($defIds, IQueryBuilder::PARAM_INT_ARRAY)));
        $grouped = [];
        foreach ($this->findEntities($qb) as $scope) {
            $grouped[$scope->getCasedocindexDefId()][] = $scope;
        }
        return $grouped;
    }

    public function deleteByDefId(int $defId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('casedocindex_def_id', $qb->createNamedParameter($defId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
