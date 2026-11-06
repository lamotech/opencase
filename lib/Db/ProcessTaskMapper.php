<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ProcessTask>
 */
class ProcessTaskMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_process_task', ProcessTask::class);
    }

    public function find(int $id): ?ProcessTask {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Tasks of the given processes, grouped by case_process_id, oldest first.
     *
     * @param int[] $caseProcessIds
     * @return array<int, ProcessTask[]>
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
        foreach ($this->findEntities($qb) as $task) {
            $grouped[$task->getCaseProcessId()][] = $task;
        }
        return $grouped;
    }

    /**
     * @return ProcessTask[]
     */
    public function findOpenByCaseProcess(int $caseProcessId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('case_process_id', $qb->createNamedParameter($caseProcessId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(ProcessTask::STATUS_OPEN)));
        return $this->findEntities($qb);
    }

    /**
     * Open tasks assigned to the user.
     *
     * @return ProcessTask[]
     */
    public function findOpenByAssignee(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(ProcessTask::STATUS_OPEN)))
            ->andWhere($qb->expr()->eq('assignee', $qb->createNamedParameter($userId)));
        return $this->findEntities($qb);
    }

    /**
     * Open tasks with a due date before $before, for deadline notifications.
     *
     * @return ProcessTask[]
     */
    public function findOpenDueBefore(\DateTime $before): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(ProcessTask::STATUS_OPEN)))
            ->andWhere($qb->expr()->isNotNull('due_date'))
            ->andWhere($qb->expr()->lt('due_date', $qb->createNamedParameter($before, IQueryBuilder::PARAM_DATETIME_MUTABLE)));
        return $this->findEntities($qb);
    }

    /**
     * Open, unassigned tasks offered to one or more candidate groups.
     *
     * @return ProcessTask[]
     */
    public function findOpenUnassignedWithCandidates(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(ProcessTask::STATUS_OPEN)))
            ->andWhere($qb->expr()->isNull('assignee'))
            ->andWhere($qb->expr()->isNotNull('candidate_groups'));
        return $this->findEntities($qb);
    }
}
