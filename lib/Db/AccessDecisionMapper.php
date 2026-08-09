<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<AccessDecision> */
class AccessDecisionMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_access_decisions', AccessDecision::class);
    }

    public function findById(int $id): AccessDecision {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    public function findByRequest(int $requestId): ?AccessDecision {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('request_id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)));
        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return null;
        }
    }
}
