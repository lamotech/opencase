<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<CustomFieldScope>
 */
class CustomFieldScopeMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_customfield_scope', CustomFieldScope::class);
    }

    /**
     * @return CustomFieldScope[]
     */
    public function findByFieldId(int $fieldId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('customfield_id', $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT)));
        return $this->findEntities($qb);
    }

    /**
     * All scope rows for the given field ids, grouped by customfield_id.
     *
     * @param int[] $fieldIds
     * @return array<int, CustomFieldScope[]>
     */
    public function findGroupedByFieldIds(array $fieldIds): array {
        if (empty($fieldIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->in('customfield_id', $qb->createNamedParameter($fieldIds, IQueryBuilder::PARAM_INT_ARRAY)));
        $grouped = [];
        foreach ($this->findEntities($qb) as $scope) {
            $grouped[$scope->getCustomfieldId()][] = $scope;
        }
        return $grouped;
    }

    public function deleteByFieldId(int $fieldId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('customfield_id', $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
