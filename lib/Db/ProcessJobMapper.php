<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ProcessJob>
 */
class ProcessJobMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_process_job', ProcessJob::class);
    }

    public function findByFlowableJobId(string $flowableJobId): ?ProcessJob {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('flowable_job_id', $qb->createNamedParameter($flowableJobId)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Steps of the given processes, grouped by case_process_id, oldest first.
     *
     * @param int[] $caseProcessIds
     * @return array<int, ProcessJob[]>
     */
    public function findGroupedByCaseProcessIds(array $caseProcessIds): array {
        if (empty($caseProcessIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->in('case_process_id', $qb->createNamedParameter($caseProcessIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC');
        $grouped = [];
        foreach ($this->findEntities($qb) as $job) {
            $grouped[$job->getCaseProcessId()][] = $job;
        }
        return $grouped;
    }
}
