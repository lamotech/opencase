<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<AccessMaskSuggestion> */
class AccessMaskSuggestionMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_access_mask_suggestions', AccessMaskSuggestion::class);
    }

    public function findById(int $id): AccessMaskSuggestion {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /** @return AccessMaskSuggestion[] */
    public function findByItemFile(int $requestItemId, int $fileId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('request_item_id', $qb->createNamedParameter($requestItemId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->orderBy('page', 'ASC')
            ->addOrderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    public function deleteByItemFileAndSource(int $requestItemId, int $fileId, string $source): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('request_item_id', $qb->createNamedParameter($requestItemId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('source', $qb->createNamedParameter($source)));
        $qb->executeStatement();
    }

    public function deleteByItem(int $requestItemId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('request_item_id', $qb->createNamedParameter($requestItemId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
