<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<PhraseScope>
 */
class PhraseScopeMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_phrases_scope', PhraseScope::class);
    }

    /**
     * All scope rows for the given phrase ids, grouped by phrases_id.
     *
     * @param int[] $phraseIds
     * @return array<int, PhraseScope[]>
     */
    public function findGroupedByPhraseIds(array $phraseIds): array {
        if (empty($phraseIds)) {
            return [];
        }
        $grouped = [];
        foreach (array_chunk($phraseIds, 1000) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')->from($this->getTableName())
                ->where($qb->expr()->in('phrases_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
            foreach ($this->findEntities($qb) as $scope) {
                $grouped[$scope->getPhrasesId()][] = $scope;
            }
        }
        return $grouped;
    }

    public function deleteByPhraseId(int $phraseId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('phrases_id', $qb->createNamedParameter($phraseId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
