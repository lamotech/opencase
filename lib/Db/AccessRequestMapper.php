<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<AccessRequest> */
class AccessRequestMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_access_requests', AccessRequest::class);
    }

    public function findById(int $id): AccessRequest {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /** @return AccessRequest[] */
    public function findByCase(int $caseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'DESC');
        return $this->findEntities($qb);
    }

    /** @return AccessRequest[] */
    public function findByAssignedUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('assigned_user', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->notIn('status', $qb->createNamedParameter(['sent', 'rejected', 'partly_rejected', 'closed'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->orderBy('deadline_at', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Return open requests where effective deadline is within $withinDays days.
     * @return AccessRequest[]
     */
    public function findApproachingDeadlines(int $withinDays): array {
        $qb = $this->db->getQueryBuilder();
        $cutoff = new \DateTime("+{$withinDays} days");
        $openStatuses = ['received', 'collecting', 'reviewing', 'redacting', 'approval'];
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->in('status', $qb->createNamedParameter($openStatuses, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->lte('deadline_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATE)))
            ->orderBy('deadline_at', 'ASC');
        return $this->findEntities($qb);
    }
}
