<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\Process;

use OCA\OpenCase\Db\CaseProcess;
use OCA\OpenCase\Db\ProcessJob;
use OCA\OpenCase\Db\ProcessTask;
use OCA\OpenCase\Db\ProcessVersionMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Figures for one process, from OpenCase's own copy of the processes run on
 * cases (no calls to the engine): how many run and have ended, how long
 * they take, and per step how much is waiting, overdue or failing.
 */
class ProcessStatisticsService {

    public function __construct(
        private IDBConnection $db,
        private ProcessVersionMapper $versionMapper,
    ) {
    }

    /**
     * @return array{
     *   running: int, completed: int, cancelled: int, failing: int,
     *   avg_duration_hours: ?float,
     *   running_by_version: array{version_no: int, count: int}[],
     *   tasks: array{activity_id: string, name: string, open: int, overdue: int, completed: int, avg_hours: ?float}[],
     *   steps: array{element_id: string, name: string, done: int, failed: int}[]
     * }
     */
    public function forProcess(int $defId): array {
        $stats = ['running' => 0, 'completed' => 0, 'cancelled' => 0, 'failing' => 0, 'avg_duration_hours' => null];
        $runningByVersion = [];
        $durations = [];

        $qb = $this->db->getQueryBuilder();
        $qb->select('status', 'process_version_id', 'started_at', 'ended_at', 'error_message')
            ->from('opencase_case_process')
            ->where($qb->expr()->eq('process_def_id', $qb->createNamedParameter($defId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $status = (string)$row['status'];
            if (isset($stats[$status])) {
                $stats[$status]++;
            }
            if ($status === CaseProcess::STATUS_RUNNING) {
                $runningByVersion[(int)$row['process_version_id']] = ($runningByVersion[(int)$row['process_version_id']] ?? 0) + 1;
                if ($row['error_message'] !== null) {
                    $stats['failing']++;
                }
            }
            if ($status === CaseProcess::STATUS_COMPLETED && $row['started_at'] && $row['ended_at']) {
                $durations[] = $this->hoursBetween($row['started_at'], $row['ended_at']);
            }
        }
        $result->closeCursor();
        $stats['avg_duration_hours'] = $this->average($durations);

        $stats['running_by_version'] = [];
        foreach ($runningByVersion as $versionId => $count) {
            $stats['running_by_version'][] = [
                'version_no' => $this->versionMapper->find($versionId)?->getVersionNo() ?? 0,
                'count' => $count,
            ];
        }
        usort($stats['running_by_version'], fn(array $a, array $b) => $a['version_no'] <=> $b['version_no']);

        $stats['tasks'] = $this->taskFigures($defId);
        $stats['steps'] = $this->stepFigures($defId);
        return $stats;
    }

    /**
     * @return array{activity_id: string, name: string, open: int, overdue: int, completed: int, avg_hours: ?float}[]
     */
    private function taskFigures(int $defId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('t.activity_id', 't.name', 't.status', 't.due_date', 't.created_at', 't.completed_at')
            ->from('opencase_process_task', 't')
            ->innerJoin('t', 'opencase_case_process', 'p', $qb->expr()->eq('p.id', 't.case_process_id'))
            ->where($qb->expr()->eq('p.process_def_id', $qb->createNamedParameter($defId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();

        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $byActivity = [];
        $durations = [];
        while ($row = $result->fetch()) {
            $id = (string)$row['activity_id'];
            $byActivity[$id] ??= ['activity_id' => $id, 'name' => (string)($row['name'] ?? $id), 'open' => 0, 'overdue' => 0, 'completed' => 0, 'avg_hours' => null];
            if ($row['status'] === ProcessTask::STATUS_OPEN) {
                $byActivity[$id]['open']++;
                if ($row['due_date'] && new \DateTime($row['due_date'], new \DateTimeZone('UTC')) < $now) {
                    $byActivity[$id]['overdue']++;
                }
            } elseif ($row['status'] === ProcessTask::STATUS_COMPLETED) {
                $byActivity[$id]['completed']++;
                if ($row['created_at'] && $row['completed_at']) {
                    $durations[$id][] = $this->hoursBetween($row['created_at'], $row['completed_at']);
                }
            }
        }
        $result->closeCursor();

        foreach ($byActivity as $id => &$figures) {
            $figures['avg_hours'] = $this->average($durations[$id] ?? []);
        }
        unset($figures);
        return array_values($byActivity);
    }

    /**
     * @return array{element_id: string, name: string, done: int, failed: int}[]
     */
    private function stepFigures(int $defId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('j.element_id', 'j.element_name', 'j.status')
            ->selectAlias($qb->func()->count('*'), 'cnt')
            ->from('opencase_process_job', 'j')
            ->innerJoin('j', 'opencase_case_process', 'p', $qb->expr()->eq('p.id', 'j.case_process_id'))
            ->where($qb->expr()->eq('p.process_def_id', $qb->createNamedParameter($defId, IQueryBuilder::PARAM_INT)))
            ->groupBy('j.element_id', 'j.element_name', 'j.status');
        $result = $qb->executeQuery();
        $byElement = [];
        while ($row = $result->fetch()) {
            $id = (string)$row['element_id'];
            $byElement[$id] ??= ['element_id' => $id, 'name' => (string)($row['element_name'] ?? $id), 'done' => 0, 'failed' => 0];
            if ($row['status'] === ProcessJob::STATUS_DONE) {
                $byElement[$id]['done'] += (int)$row['cnt'];
            } elseif ($row['status'] === ProcessJob::STATUS_FAILED) {
                $byElement[$id]['failed'] += (int)$row['cnt'];
            }
        }
        $result->closeCursor();
        return array_values($byElement);
    }

    private function hoursBetween(string $from, string $to): float {
        $utc = new \DateTimeZone('UTC');
        return ((new \DateTime($to, $utc))->getTimestamp() - (new \DateTime($from, $utc))->getTimestamp()) / 3600;
    }

    /**
     * @param float[] $values
     */
    private function average(array $values): ?float {
        return $values === [] ? null : round(array_sum($values) / count($values), 1);
    }
}
