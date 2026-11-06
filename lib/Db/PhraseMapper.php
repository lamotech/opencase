<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Phrase>
 */
class PhraseMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_phrases', Phrase::class);
    }

    public function find(int $id): ?Phrase {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * @return Phrase[]
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->orderBy('title', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Number of phrases per category id (uncategorised phrases are left out).
     *
     * @return array<int, int>
     */
    public function countByCategory(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('category_id')
            ->selectAlias($qb->func()->count('*'), 'cnt')
            ->from($this->getTableName())
            ->where($qb->expr()->isNotNull('category_id'))
            ->groupBy('category_id');
        $result = $qb->executeQuery();
        $counts = [];
        while ($row = $result->fetch()) {
            $counts[(int)$row['category_id']] = (int)$row['cnt'];
        }
        $result->closeCursor();
        return $counts;
    }
}
