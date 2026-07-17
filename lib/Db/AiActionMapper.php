<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for the opencase_aiactions table.
 *
 * @extends QBMapper<AiAction>
 */
class AiActionMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_aiactions', AiAction::class);
    }

    /**
     * @return AiAction[]
     */
    public function findByPromptId(int $promptId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('prompt_id', $qb->createNamedParameter($promptId, IQueryBuilder::PARAM_INT)))
            ->orderBy('sequence_index', 'ASC');

        return $this->findEntities($qb);
    }

    public function deleteByPromptId(int $promptId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('prompt_id', $qb->createNamedParameter($promptId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
