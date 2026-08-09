<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<AccessRequestItem> */
class AccessRequestItemMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_access_request_items', AccessRequestItem::class);
    }

    public function findById(int $id): AccessRequestItem {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /** @return AccessRequestItem[] */
    public function findByRequest(int $requestId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('request_id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)))
            ->orderBy('sort_order', 'ASC')
            ->addOrderBy('created_at', 'ASC');
        return $this->findEntities($qb);
    }

    public function findBySourceAndRequest(int $requestId, string $sourceType, int $sourceId): ?AccessRequestItem {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('request_id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('source_type', $qb->createNamedParameter($sourceType)))
            ->andWhere($qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)));
        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return null;
        }
    }
}
