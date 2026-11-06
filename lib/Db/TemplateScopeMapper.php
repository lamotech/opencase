<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<TemplateScope>
 */
class TemplateScopeMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_template_scope', TemplateScope::class);
    }

    /**
     * Scope rows of all templates, grouped by template_id.
     *
     * @return array<int, TemplateScope[]>
     */
    public function findAllGrouped(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName());
        $grouped = [];
        foreach ($this->findEntities($qb) as $scope) {
            $grouped[$scope->getTemplateId()][] = $scope;
        }
        return $grouped;
    }

    /**
     * @return TemplateScope[]
     */
    public function findByTemplateId(int $templateId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('template_id', $qb->createNamedParameter($templateId, IQueryBuilder::PARAM_INT)));
        return $this->findEntities($qb);
    }

    public function deleteByTemplateId(int $templateId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('template_id', $qb->createNamedParameter($templateId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
