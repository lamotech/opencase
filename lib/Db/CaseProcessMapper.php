<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<CaseProcess>
 */
class CaseProcessMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_case_process', CaseProcess::class);
    }

    public function find(int $id): ?CaseProcess {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    public function findByFlowableInstanceId(string $instanceId): ?CaseProcess {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('flowable_instance_id', $qb->createNamedParameter($instanceId)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Processes on a case, newest first.
     *
     * @return CaseProcess[]
     */
    public function findByCase(int $caseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
            ->orderBy('started_at', 'DESC')
            ->addOrderBy('id', 'DESC');
        return $this->findEntities($qb);
    }

    /**
     * Running processes not synced with the engine since $before, the
     * longest-waiting first.
     *
     * @return CaseProcess[]
     */
    public function findRunningSyncedBefore(\DateTime $before, int $limit): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(CaseProcess::STATUS_RUNNING)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('last_synced_at'),
                $qb->expr()->lt('last_synced_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_DATETIME_MUTABLE)),
            ))
            ->orderBy('last_synced_at', 'ASC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }

    /**
     * Running processes of a process that are not on the given version.
     *
     * @return CaseProcess[]
     */
    public function findRunningNotOnVersion(int $processDefId, int $versionId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('process_def_id', $qb->createNamedParameter($processDefId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(CaseProcess::STATUS_RUNNING)))
            ->andWhere($qb->expr()->neq('process_version_id', $qb->createNamedParameter($versionId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Number of running processes per process.
     *
     * @return array<int, int> process_def_id => count
     */
    public function countRunningByDef(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('process_def_id')
            ->selectAlias($qb->func()->count('*'), 'cnt')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(CaseProcess::STATUS_RUNNING)))
            ->groupBy('process_def_id');
        $result = $qb->executeQuery();
        $counts = [];
        while ($row = $result->fetch()) {
            $counts[(int)$row['process_def_id']] = (int)$row['cnt'];
        }
        $result->closeCursor();
        return $counts;
    }

    public function hasRunning(int $caseId, int $processDefId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from($this->getTableName())
            ->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('process_def_id', $qb->createNamedParameter($processDefId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(CaseProcess::STATUS_RUNNING)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $found = $result->fetchOne() !== false;
        $result->closeCursor();
        return $found;
    }

    public function countByProcessDef(int $processDefId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*'))->from($this->getTableName())
            ->where($qb->expr()->eq('process_def_id', $qb->createNamedParameter($processDefId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }
}
