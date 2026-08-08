<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<AccessRedaction> */
class AccessRedactionMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_access_redactions', AccessRedaction::class);
    }

    public function findById(int $id): AccessRedaction {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /** @return AccessRedaction[] */
    public function findByItem(int $requestItemId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('request_item_id', $qb->createNamedParameter($requestItemId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'ASC');
        return $this->findEntities($qb);
    }

    public function deleteByItem(int $requestItemId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('request_item_id', $qb->createNamedParameter($requestItemId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
