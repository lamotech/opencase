<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<AiPrompt>
 */
class AiPromptMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_aiprompts', AiPrompt::class);
    }

    /** @return AiPrompt[] */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('title', 'ASC');
        return $this->findEntities($qb);
    }

    public function findById(int $id): ?AiPrompt {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $entities = $this->findEntities($qb);
        return $entities[0] ?? null;
    }

    /**
     * Return at most $limit recently used prompts whose scope matches one of the given scopes.
     * Only prompts that have been used at least once (last_used_at IS NOT NULL) are returned.
     *
     * @param string[] $scopes
     * @return AiPrompt[]
     */
    public function findRecent(array $scopes, int $limit = 5): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('scope', $qb->createNamedParameter($scopes, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->isNotNull('last_used_at'))
            ->orderBy('last_used_at', 'DESC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }
}
