<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<TemplatePhrase>
 */
class TemplatePhraseMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_template_phrases', TemplatePhrase::class);
    }

    /**
     * @return TemplatePhrase[]
     */
    public function findByTemplateId(int $templateId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('template_id', $qb->createNamedParameter($templateId, IQueryBuilder::PARAM_INT)))
            ->orderBy('sort_order', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Number of attached phrases per template id (archived phrases not counted).
     *
     * @return array<int, int>
     */
    public function countByTemplate(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('tp.template_id')
            ->selectAlias($qb->func()->count('*'), 'cnt')
            ->from($this->getTableName(), 'tp')
            ->innerJoin('tp', 'opencase_phrases', 'p', $qb->expr()->eq('p.id', 'tp.phrase_id'))
            ->where($qb->expr()->neq('p.status', $qb->createNamedParameter('archived')))
            ->groupBy('tp.template_id');
        $result = $qb->executeQuery();
        $counts = [];
        while ($row = $result->fetch()) {
            $counts[(int)$row['template_id']] = (int)$row['cnt'];
        }
        $result->closeCursor();
        return $counts;
    }

    /**
     * @return TemplatePhrase[] the templates a phrase is attached to
     */
    public function findByPhraseId(int $phraseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('phrase_id', $qb->createNamedParameter($phraseId, IQueryBuilder::PARAM_INT)));
        return $this->findEntities($qb);
    }

    public function deleteByTemplateId(int $templateId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('template_id', $qb->createNamedParameter($templateId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    public function deleteByPhraseId(int $phraseId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('phrase_id', $qb->createNamedParameter($phraseId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
