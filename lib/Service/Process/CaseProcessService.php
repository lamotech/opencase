<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\Process;

use OCA\OpenCase\Db\CaseEntity;
use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\CaseProcess;
use OCA\OpenCase\Db\CaseProcessMapper;
use OCA\OpenCase\Db\CaseStatusMapper;
use OCA\OpenCase\Db\ProcessDef;
use OCA\OpenCase\Db\ProcessDefMapper;
use OCA\OpenCase\Db\ProcessJob;
use OCA\OpenCase\Db\ProcessJobMapper;
use OCA\OpenCase\Db\ProcessScopeMapper;
use OCA\OpenCase\Db\ProcessTask;
use OCA\OpenCase\Db\ProcessTaskMapper;
use OCA\OpenCase\Db\ProcessVersion;
use OCA\OpenCase\Db\ProcessVersionMapper;
use OCA\OpenCase\Db\UserInfoMapper;
use OCA\OpenCase\Db\UserOrgMapper;
use OCA\OpenCase\Service\AuditService;
use OCA\OpenCase\Service\CaseScopeMatcher;
use OCA\OpenCase\Service\CaseService;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\PermissionService;
use OCP\DB\Exception as DBException;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Processes running on cases.
 *
 * Flowable runs the process; OpenCase keeps a copy of each instance's state
 * and user tasks (opencase_case_process, opencase_process_task), refreshed by
 * sync() after every action taken through OpenCase and by ProcessSyncJob for
 * changes made by the engine itself (e.g. timers).
 *
 * Process variables set at start:
 *   caseId, caseUuid, caseNumber, caseResponsible (Nextcloud user id or null),
 *   orgUuid, kle, startedBy
 * Task outcomes are stored in the process variable "udfald".
 */
class CaseProcessService {

    /** Form property (and process variable) holding a task's outcome. */
    public const OUTCOME_PROPERTY = 'udfald';

    /** Form property types offered as fields on a task (others are ignored). */
    private const FIELD_TYPES = ['string', 'long', 'date', 'boolean', 'enum'];
    /** Form field type shown as an employee picker; a string in the engine */
    private const USER_FIELD_TYPE = 'user';

    /** Candidate groups naming an organisation unit are "org:<uuid>". */
    private const ORG_GROUP_PREFIX = 'org:';

    /** End reason of processes cancelled because their case was closed. */
    public const CASE_CLOSED_REASON = 'Sagen er lukket';

    /** Automatic steps run right after an action in OpenCase, per kind of step. */
    private const INLINE_STEPS_PER_TOPIC = 10;

    /** list() refreshes running processes not synced for this long. */
    private const SYNC_ON_VIEW_SECONDS = 30;

    public function __construct(
        private CaseProcessMapper $caseProcessMapper,
        private ProcessTaskMapper $taskMapper,
        private ProcessJobMapper $jobMapper,
        private ProcessWorker $worker,
        private ProcessDefMapper $defMapper,
        private ProcessVersionMapper $versionMapper,
        private ProcessScopeMapper $scopeMapper,
        private CaseMapper $caseMapper,
        private CaseStatusMapper $caseStatusMapper,
        private CaseService $caseService,
        private CaseScopeMatcher $scopeMatcher,
        private PermissionService $permissionService,
        private UserInfoMapper $userInfoMapper,
        private UserOrgMapper $userOrgMapper,
        private FlowableClient $flowable,
        private AuditService $auditService,
        private INotificationManager $notificationManager,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {
    }

    public function isEnabled(): bool {
        return $this->flowable->isFeatureEnabled();
    }

    /**
     * Active processes with a published version whose scopes match the case.
     *
     * @return array{def: ProcessDef, version: ProcessVersion}[]
     */
    public function availableFor(CaseEntity $case): array {
        return $this->availableForMetadata(
            $case->getCasetypeId(),
            $case->getOrgUuid(),
            $this->caseService->getClassificationCodeForCase($case),
            $case->getClassPlanUuid(),
        );
    }

    /**
     * Active processes with a published version whose scopes match a case
     * with this case type, organisation, KLE code and Emneplan item — also
     * before the case exists (the "Ny sag" dialog).
     *
     * @return array{def: ProcessDef, version: ProcessVersion}[]
     */
    public function availableForMetadata(?int $casetypeId, ?string $orgUuid, ?string $kleCode, ?string $classPlanUuid = null): array {
        $defs = $this->defMapper->findAll(true);
        if ($defs === []) {
            return [];
        }
        $scopesByDef = $this->scopeMapper->findGroupedByDefIds(array_map(fn(ProcessDef $d) => $d->getId(), $defs));
        $orgAncestors = $orgUuid !== null && $orgUuid !== '' ? $this->scopeMatcher->resolveOrgAncestorUuids($orgUuid) : [];

        $available = [];
        foreach ($defs as $def) {
            if (!$this->scopeMatcher->matches($scopesByDef[$def->getId()] ?? [], $casetypeId, $orgAncestors, $kleCode, $classPlanUuid)) {
                continue;
            }
            $version = $this->versionMapper->findPublished($def->getId());
            if ($version !== null) {
                $available[] = ['def' => $def, 'version' => $version];
            }
        }
        return $available;
    }

    /**
     * Start the published version of a process on a case.
     *
     * @throws \InvalidArgumentException when the process can't be started on this case
     * @throws ProcessConflictException when it is already running on the case
     * @throws \RuntimeException when the engine can't be reached
     */
    public function start(int $caseId, int $processDefId, string $userId): CaseProcess {
        $case = $this->requireCase($caseId);
        if ($this->caseStatusMapper->isClosed($case->getStatusId())) {
            throw new \InvalidArgumentException('Der kan ikke startes processer på en afsluttet sag.');
        }

        $match = null;
        foreach ($this->availableFor($case) as $candidate) {
            if ($candidate['def']->getId() === $processDefId) {
                $match = $candidate;
            }
        }
        if ($match === null) {
            throw new \InvalidArgumentException('Processen kan ikke startes på denne sag.');
        }
        if ($this->caseProcessMapper->hasRunning($caseId, $processDefId)) {
            throw new ProcessConflictException('Processen kører allerede på sagen.');
        }

        /** @var ProcessVersion $version */
        $version = $match['version'];
        $instanceId = $this->flowable->startProcess(
            (string)$version->getFlowableProcDefId(),
            (string)($case->getUuid() ?? $case->getId()),
            [
                'caseId' => $case->getId(),
                'caseUuid' => $case->getUuid(),
                'caseNumber' => $case->getCaseNumber(),
                'caseResponsible' => $case->getResponsibleUserId() ?: null,
                'orgUuid' => $case->getOrgUuid(),
                'kle' => $this->caseService->getClassificationCodeForCase($case),
                'startedBy' => $userId,
            ],
        );

        $caseProcess = new CaseProcess();
        $caseProcess->setCaseId($caseId);
        $caseProcess->setProcessDefId($processDefId);
        $caseProcess->setProcessVersionId($version->getId());
        $caseProcess->setFlowableInstanceId($instanceId);
        $caseProcess->setStatus(CaseProcess::STATUS_RUNNING);
        $caseProcess->setStartedAt(new \DateTime());
        $caseProcess->setStartedBy($userId);
        $caseProcess = $this->caseProcessMapper->insert($caseProcess);

        $this->auditService->logProcessStarted($caseId, $userId, $caseProcess->getId(), $match['def']->getName(), $version->getVersionNo());
        $this->runStepsAndSync($caseProcess, $userId);

        return $this->caseProcessMapper->find($caseProcess->getId()) ?? $caseProcess;
    }

    /**
     * Processes on a case with their tasks, newest first. Running processes
     * not synced recently are refreshed first; if the engine is down, the
     * last known state is returned.
     *
     * @return array{process: CaseProcess, def: ?ProcessDef, version: ?ProcessVersion, tasks: ProcessTask[], jobs: ProcessJob[]}[]
     */
    public function listForCase(int $caseId): array {
        $processes = $this->caseProcessMapper->findByCase($caseId);
        $staleBefore = new \DateTime('-' . self::SYNC_ON_VIEW_SECONDS . ' seconds');
        foreach ($processes as $process) {
            if ($process->getStatus() === CaseProcess::STATUS_RUNNING
                && ($process->getLastSyncedAt() === null || $process->getLastSyncedAt() < $staleBefore)) {
                $this->trySync($process, null);
            }
        }

        $processIds = array_map(fn(CaseProcess $p) => $p->getId(), $processes);
        $tasksByProcess = $this->taskMapper->findGroupedByCaseProcessIds($processIds);
        $jobsByProcess = $this->jobMapper->findGroupedByCaseProcessIds($processIds);
        $defs = [];
        $rows = [];
        foreach ($processes as $process) {
            $defs[$process->getProcessDefId()] ??= $this->defMapper->find($process->getProcessDefId());
            $rows[] = [
                'process' => $process,
                'def' => $defs[$process->getProcessDefId()],
                'version' => $this->versionMapper->find($process->getProcessVersionId()),
                'tasks' => $tasksByProcess[$process->getId()] ?? [],
                'jobs' => $jobsByProcess[$process->getId()] ?? [],
            ];
        }
        return $rows;
    }

    /**
     * A process of a case, for the diagram: the BPMN of its version plus the
     * elements it is at and has passed.
     *
     * @return array{process: CaseProcess, version: ProcessVersion}
     */
    public function getForDiagram(int $caseId, int $caseProcessId): array {
        $process = $this->requireCaseProcess($caseId, $caseProcessId);
        $version = $this->versionMapper->find($process->getProcessVersionId());
        if ($version === null) {
            throw new NotFoundException("Process version {$process->getProcessVersionId()} not found");
        }
        return ['process' => $process, 'version' => $version];
    }

    public function cancel(int $caseId, int $caseProcessId, string $userId, string $reason): CaseProcess {
        $process = $this->requireCaseProcess($caseId, $caseProcessId);
        if ($process->getStatus() !== CaseProcess::STATUS_RUNNING) {
            throw new ProcessConflictException('Processen kører ikke.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Angiv en begrundelse.');
        }

        return $this->doCancel($process, $userId, $reason);
    }

    /**
     * Cancel all running processes of a case that has been closed
     * (CaseClosedProcessListener). sync() does the same for processes the
     * listener couldn't cancel, e.g. while the engine was down.
     *
     * @throws \RuntimeException when the engine can't be reached
     */
    /**
     * Set the variable caseResponsible of the case's running processes, so
     * tasks created from now on for the case's responsible go to the new one.
     */
    public function updateCaseResponsible(int $caseId, string $userId): void {
        foreach ($this->caseProcessMapper->findByCase($caseId) as $process) {
            if ($process->getStatus() === CaseProcess::STATUS_RUNNING) {
                $this->flowable->setVariable($process->getFlowableInstanceId(), 'caseResponsible', $userId);
            }
        }
    }

    public function cancelRunningForClosedCase(int $caseId, string $userId): void {
        foreach ($this->caseProcessMapper->findByCase($caseId) as $process) {
            if ($process->getStatus() !== CaseProcess::STATUS_RUNNING) {
                continue;
            }
            if (!$this->flowable->isRunning($process->getFlowableInstanceId())) {
                // Ended in the engine but not synced yet, e.g. when its last
                // step closed the case: record how it really ended
                $this->sync($process, $userId, false);
                continue;
            }
            $this->doCancel($process, $userId, self::CASE_CLOSED_REASON);
        }
    }

    private function doCancel(CaseProcess $process, string $userId, string $reason): CaseProcess {
        $this->flowable->cancelProcess($process->getFlowableInstanceId(), $reason);

        $process->setStatus(CaseProcess::STATUS_CANCELLED);
        $process->setEndedAt(new \DateTime());
        $process->setEndedBy($userId);
        $process->setEndReason($reason);
        $process->setActiveActivities('[]');
        $process = $this->caseProcessMapper->update($process);

        foreach ($this->taskMapper->findOpenByCaseProcess($process->getId()) as $task) {
            $task->setStatus(ProcessTask::STATUS_CANCELLED);
            $task->setCompletedAt(new \DateTime());
            $this->taskMapper->update($task);
        }

        $def = $this->defMapper->find($process->getProcessDefId());
        $this->auditService->logProcessCancelled($process->getCaseId(), $userId, $process->getId(), $def?->getName() ?? '', $reason);
        return $process;
    }

    public function getTask(int $taskId): ProcessTask {
        $task = $this->taskMapper->find($taskId);
        if ($task === null) {
            throw new NotFoundException("Process task {$taskId} not found");
        }
        return $task;
    }

    /**
     * Whether the user may take or complete the task: it is assigned to
     * them, or it is unassigned and they are a candidate (or it has no
     * candidates, so anyone with write access to the case may take it).
     * Case access is checked by the caller.
     */
    public function isEligible(ProcessTask $task, string $userId): bool {
        if ($task->getStatus() !== ProcessTask::STATUS_OPEN) {
            return false;
        }
        if ($task->getAssignee() !== null) {
            return $task->getAssignee() === $userId;
        }
        return $this->isCandidate($task, $userId);
    }

    public function claim(int $taskId, string $userId): ProcessTask {
        $task = $this->getTask($taskId);
        if ($task->getStatus() !== ProcessTask::STATUS_OPEN) {
            throw new ProcessConflictException('Opgaven er ikke længere åben.');
        }
        if ($task->getAssignee() !== null) {
            throw new ProcessConflictException($task->getAssignee() === $userId
                ? 'Opgaven er allerede tildelt dig.'
                : 'Opgaven er allerede taget af en anden.');
        }
        if (!$this->isCandidate($task, $userId)) {
            throw new \InvalidArgumentException('Du er ikke kandidat til opgaven.');
        }

        $this->flowable->claimTask($task->getFlowableTaskId(), $userId);
        $task->setAssignee($userId);
        $task = $this->taskMapper->update($task);
        $this->auditService->logProcessTaskClaimed($task->getCaseId(), $userId, $task->getId(), (string)$task->getName());
        return $task;
    }

    /**
     * Give a task back so another candidate can take it.
     */
    public function release(int $taskId, string $userId): ProcessTask {
        $task = $this->getTask($taskId);
        if ($task->getStatus() !== ProcessTask::STATUS_OPEN || $task->getAssignee() !== $userId) {
            throw new ProcessConflictException('Opgaven er ikke tildelt dig.');
        }
        $this->flowable->claimTask($task->getFlowableTaskId(), null);
        $task->setAssignee(null);
        return $this->taskMapper->update($task);
    }

    /**
     * Complete a task assigned to the user (an unassigned task they are a
     * candidate for is taken first). $outcome must be one of the task's
     * outcomes when it has any; $values are the task's form fields.
     *
     * @param array<string, mixed> $values field id => value
     */
    public function complete(int $taskId, string $userId, ?string $outcome, ?string $comment, array $values = []): ProcessTask {
        $task = $this->getTask($taskId);
        if ($task->getStatus() !== ProcessTask::STATUS_OPEN) {
            throw new ProcessConflictException('Opgaven er ikke længere åben.');
        }
        if (!$this->isEligible($task, $userId)) {
            throw new \InvalidArgumentException($task->getAssignee() !== null
                ? 'Opgaven er tildelt en anden.'
                : 'Du er ikke kandidat til opgaven.');
        }

        $outcomes = $task->getOutcomeList();
        $outcomeLabel = null;
        if ($outcomes !== []) {
            foreach ($outcomes as $o) {
                if ($o['id'] === $outcome) {
                    $outcomeLabel = $o['name'];
                }
            }
            if ($outcomeLabel === null) {
                throw new \InvalidArgumentException('Vælg et udfald.');
            }
        } else {
            $outcome = null;
        }
        $fieldValues = $this->validateFormValues($task, $values);

        if ($task->getAssignee() === null) {
            // Record who did it in the engine too
            $this->flowable->claimTask($task->getFlowableTaskId(), $userId);
        }
        $properties = $fieldValues;
        if ($outcome !== null) {
            $properties[self::OUTCOME_PROPERTY] = $outcome;
        }
        $this->flowable->completeTask($task->getFlowableTaskId(), $properties);

        $comment = $comment !== null && trim($comment) !== '' ? trim($comment) : null;
        $task->setAssignee($userId);
        $task->setStatus(ProcessTask::STATUS_COMPLETED);
        $task->setOutcome($outcome);
        $task->setComment($comment);
        $task->setFormValues($fieldValues !== [] ? json_encode($fieldValues) : null);
        $task->setCompletedAt(new \DateTime());
        $task->setCompletedBy($userId);
        $task = $this->taskMapper->update($task);

        $fieldsForLog = [];
        foreach ($task->getFormFieldList() as $field) {
            if (isset($fieldValues[$field['id']])) {
                $fieldsForLog[$field['name']] = $fieldValues[$field['id']];
            }
        }
        $this->auditService->logProcessTaskCompleted($task->getCaseId(), $userId, $task->getId(), (string)$task->getName(), $outcomeLabel, $comment, $fieldsForLog);

        $process = $this->caseProcessMapper->find($task->getCaseProcessId());
        if ($process !== null) {
            $this->runStepsAndSync($process, $userId);
        }
        return $task;
    }

    /**
     * Open tasks the user can act on: assigned to them, or unassigned with
     * one of their organisation units (or a parent unit) as candidate.
     * Case access is checked by the caller.
     *
     * @return ProcessTask[]
     */
    public function openTasksFor(string $userId): array {
        $tasks = $this->taskMapper->findOpenByAssignee($userId);
        $userGroups = $this->userCandidateGroups($userId);
        if ($userGroups !== []) {
            foreach ($this->taskMapper->findOpenUnassignedWithCandidates() as $task) {
                if (array_intersect($task->getCandidateGroupList(), $userGroups) !== []) {
                    $tasks[] = $task;
                }
            }
        }
        return $tasks;
    }

    /**
     * Sync running processes not synced since $before (ProcessSyncJob).
     *
     * @return int number of processes synced
     */
    public function syncStale(\DateTime $before, int $limit): int {
        $count = 0;
        foreach ($this->caseProcessMapper->findRunningSyncedBefore($before, $limit) as $process) {
            if ($this->trySync($process, null)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Retry an automatic step that failed for good.
     */
    public function retryFailedStep(int $caseId, int $caseProcessId, string $userId): CaseProcess {
        $process = $this->requireCaseProcess($caseId, $caseProcessId);
        if ($process->getStatus() !== CaseProcess::STATUS_RUNNING || $process->getErrorJobId() === null) {
            throw new ProcessConflictException('Processen har ikke et fejlet trin.');
        }
        $this->flowable->moveDeadLetterJob($process->getErrorJobId());
        $process->setErrorJobId(null);
        $process->setErrorMessage(null);
        $process = $this->caseProcessMapper->update($process);
        $this->runStepsAndSync($process, $userId);
        return $this->caseProcessMapper->find($process->getId()) ?? $process;
    }

    /**
     * Run waiting automatic steps (any process), then sync the processes
     * they belonged to (ProcessWorkerJob).
     *
     * @return int number of processes that had steps run
     */
    public function runAutomaticSteps(int $maxPerTopic): int {
        $touched = $this->worker->run($maxPerTopic);
        foreach ($touched as $caseProcessId) {
            $process = $this->caseProcessMapper->find($caseProcessId);
            if ($process !== null) {
                $this->trySync($process, null);
            }
        }
        return count($touched);
    }

    /**
     * After an action that moved a process on: run the automatic steps it
     * may have reached, so their results show at once, and sync it.
     */
    private function runStepsAndSync(CaseProcess $process, string $userId): void {
        try {
            $touched = $this->worker->run(self::INLINE_STEPS_PER_TOPIC);
        } catch (\Throwable $e) {
            // ProcessWorkerJob runs them later
            $this->logger->warning('Could not run automatic process steps: ' . $e->getMessage());
            $touched = [];
        }
        foreach ($touched as $caseProcessId) {
            if ($caseProcessId !== $process->getId()) {
                $other = $this->caseProcessMapper->find($caseProcessId);
                if ($other !== null) {
                    $this->trySync($other, null);
                }
            }
        }
        $current = $this->caseProcessMapper->find($process->getId());
        if ($current !== null) {
            $this->trySync($current, $userId);
        }
    }

    /**
     * Sync, logging instead of throwing when the engine is unavailable.
     */
    private function trySync(CaseProcess $process, ?string $actingUserId): bool {
        try {
            $this->sync($process, $actingUserId);
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Could not sync process ' . $process->getId() . ' with the process engine: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Bring OpenCase's copy of a running process in step with the engine:
     * new and changed open tasks, tasks that ended, where the process is,
     * and whether it has ended. Users get a notification when a task is
     * assigned to them by the engine (not when they took it themselves);
     * see settleAssignee() for assignees without access to the case.
     *
     * @param bool $refreshTaskDetails re-read candidates and forms of all open
     *                                 tasks, e.g. after moving to a new version
     */
    public function sync(CaseProcess $process, ?string $actingUserId, bool $cancelIfCaseClosed = true, bool $refreshTaskDetails = false): void {
        if ($process->getStatus() !== CaseProcess::STATUS_RUNNING) {
            return;
        }
        $instanceId = $process->getFlowableInstanceId();
        $case = $this->caseMapper->findById($process->getCaseId());
        if ($cancelIfCaseClosed && $case !== null && $this->caseStatusMapper->isClosed($case->getStatusId())
            && $this->flowable->isRunning($instanceId)) {
            // The case was closed while the engine couldn't be reached
            $this->doCancel($process, 'system', self::CASE_CLOSED_REASON);
            return;
        }
        $engineTasks = $this->flowable->getTasks($instanceId);

        $known = [];
        foreach ($this->taskMapper->findOpenByCaseProcess($process->getId()) as $task) {
            $known[$task->getFlowableTaskId()] = $task;
        }

        foreach ($engineTasks as $engineTask) {
            $task = $known[$engineTask['id']] ?? null;
            unset($known[$engineTask['id']]);
            $dueDate = $engineTask['due_date'] !== null ? new \DateTime($engineTask['due_date']) : null;

            if ($task === null) {
                $task = new ProcessTask();
                $task->setCaseProcessId($process->getId());
                $task->setCaseId($process->getCaseId());
                $task->setFlowableTaskId($engineTask['id']);
                $task->setActivityId($engineTask['activity_id']);
                $task->setName($engineTask['name']);
                $task->setStatus(ProcessTask::STATUS_OPEN);
                $task->setAssignee($engineTask['assignee']);
                $this->applyTaskDetails($task, $process);
                $task->setDueDate($dueDate);
                $task->setCreatedAt($engineTask['created_at'] !== null ? new \DateTime($engineTask['created_at']) : new \DateTime());
                try {
                    $task = $this->taskMapper->insert($task);
                } catch (DBException $e) {
                    if ($e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                        // Inserted by a concurrent sync
                        continue;
                    }
                    throw $e;
                }
                $this->settleAssignee($task, $process, $case, $actingUserId, true);
                continue;
            }

            $assigneeChanged = $task->getAssignee() !== $engineTask['assignee'];
            $dueChanged = $task->getDueDate()?->getTimestamp() !== $dueDate?->getTimestamp();
            // The task's step can change when the process is moved to a new version
            $detailsChanged = $refreshTaskDetails || $task->getActivityId() !== $engineTask['activity_id'];
            if ($assigneeChanged || $dueChanged || $detailsChanged || $task->getName() !== $engineTask['name']) {
                $task->setAssignee($engineTask['assignee']);
                $task->setDueDate($dueDate);
                $task->setName($engineTask['name']);
                if ($detailsChanged) {
                    $task->setActivityId($engineTask['activity_id']);
                    $this->applyTaskDetails($task, $process);
                }
                $this->taskMapper->update($task);
            }
            $this->settleAssignee($task, $process, $case, $actingUserId, $assigneeChanged);
        }

        // Open tasks the engine no longer has: completed outside OpenCase,
        // or ended by e.g. an interrupting timer
        foreach ($known as $task) {
            $historic = $this->flowable->getHistoricTask($task->getFlowableTaskId());
            $task->setStatus(($historic['delete_reason'] ?? null) === null ? ProcessTask::STATUS_COMPLETED : ProcessTask::STATUS_CANCELLED);
            $task->setCompletedAt(isset($historic['end_time']) ? new \DateTime($historic['end_time']) : new \DateTime());
            if ($task->getStatus() === ProcessTask::STATUS_COMPLETED) {
                $task->setCompletedBy($historic['assignee'] ?? $task->getAssignee());
            }
            $this->taskMapper->update($task);
        }

        $process->setCompletedActivities(json_encode($this->flowable->getCompletedActivityIds($instanceId)));
        if ($this->flowable->isRunning($instanceId)) {
            $process->setActiveActivities(json_encode($this->flowable->getActiveActivityIds($instanceId)));
            // An automatic step that failed for good stops the process
            $failed = $this->flowable->getDeadLetterJobs($instanceId)[0] ?? null;
            $process->setErrorJobId($failed['id'] ?? null);
            $process->setErrorMessage($failed !== null ? ($failed['message'] ?? 'Ukendt fejl') : null);
        } else {
            $process->setErrorJobId(null);
            $process->setErrorMessage(null);
            $historic = $this->flowable->getHistoricInstance($instanceId);
            $process->setActiveActivities('[]');
            $process->setEndedAt(isset($historic['end_time']) ? new \DateTime($historic['end_time']) : new \DateTime());
            if (($historic['delete_reason'] ?? null) !== null) {
                $process->setStatus(CaseProcess::STATUS_CANCELLED);
                $process->setEndReason($historic['delete_reason']);
            } else {
                $process->setStatus(CaseProcess::STATUS_COMPLETED);
                $def = $this->defMapper->find($process->getProcessDefId());
                $this->auditService->logProcessCompleted($process->getCaseId(), $process->getId(), $def?->getName() ?? '');
            }
        }
        $process->setLastSyncedAt(new \DateTime());
        $this->caseProcessMapper->update($process);
    }

    /**
     * Candidate groups, outcomes and form fields of a task, from the engine.
     */
    private function applyTaskDetails(ProcessTask $task, CaseProcess $process): void {
        $groups = $this->flowable->getCandidateGroups($task->getFlowableTaskId());
        $task->setCandidateGroups($groups !== [] ? implode(',', $groups) : null);
        // "Medarbejder" fields are strings to the engine; the diagram marks them
        $version = $this->versionMapper->find($process->getProcessVersionId());
        $userFields = $version !== null ? BpmnXml::userFieldIds($version->getBpmnXml(), $task->getActivityId()) : [];

        $outcomes = [];
        $fields = [];
        foreach ($this->flowable->getFormProperties($task->getFlowableTaskId()) as $property) {
            if ($property['id'] === self::OUTCOME_PROPERTY && $property['type'] === 'enum') {
                $outcomes = $property['options'];
                continue;
            }
            if (!in_array($property['type'], self::FIELD_TYPES, true)) {
                continue;
            }
            $type = $property['type'] === 'string' && in_array($property['id'], $userFields, true) ? self::USER_FIELD_TYPE : $property['type'];
            $field = ['id' => $property['id'], 'name' => $property['name'], 'type' => $type, 'required' => $property['required']];
            if ($property['type'] === 'enum') {
                $field['options'] = $property['options'];
            }
            $fields[] = $field;
        }
        $task->setOutcomes($outcomes !== [] ? json_encode($outcomes) : null);
        $task->setFormFields($fields !== [] ? json_encode($fields) : null);
    }

    /**
     * Check and normalise the values entered for a task's form fields.
     * Flowable is lenient (it reads "15-10-2026" as a date in year 21), so
     * values are checked strictly here before they reach the engine.
     *
     * @param array<string, mixed> $values
     * @return array<string, string> field id => value, empty fields left out
     * @throws \InvalidArgumentException
     */
    private function validateFormValues(ProcessTask $task, array $values): array {
        $clean = [];
        foreach ($task->getFormFieldList() as $field) {
            $raw = $values[$field['id']] ?? null;
            $value = is_bool($raw) ? ($raw ? 'true' : 'false') : trim((string)($raw ?? ''));
            if ($field['type'] === 'boolean' && $value === '') {
                $value = 'false';
            }
            if ($value === '') {
                if ($field['required']) {
                    throw new \InvalidArgumentException("Udfyld \"{$field['name']}\".");
                }
                continue;
            }
            $ok = match ($field['type']) {
                'long' => preg_match('/^-?\d{1,18}$/', $value) === 1,
                'date' => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int)$m[2], (int)$m[3], (int)$m[1]),
                'boolean' => in_array($value, ['true', 'false'], true),
                'enum' => in_array($value, array_column($field['options'] ?? [], 'id'), true),
                self::USER_FIELD_TYPE => $this->userManager->get($value) !== null,
                default => mb_strlen($value) <= 4000,
            };
            if (!$ok) {
                throw new \InvalidArgumentException("Ugyldig værdi i \"{$field['name']}\".");
            }
            $clean[$field['id']] = $value;
        }
        return $clean;
    }

    private function isCandidate(ProcessTask $task, string $userId): bool {
        $groups = $task->getCandidateGroupList();
        if ($groups === []) {
            // Nobody in particular: anyone with write access to the case
            return true;
        }
        return array_intersect($groups, $this->userCandidateGroups($userId)) !== [];
    }

    /**
     * Candidate groups the user belongs to: "org:<uuid>" for each of their
     * organisation units and the units above them, so a task offered to a
     * department reaches its sub-units too.
     *
     * @return string[]
     */
    private function userCandidateGroups(string $userId): array {
        $userInfo = $this->userInfoMapper->findByUserId($userId);
        if ($userInfo === null) {
            return [];
        }
        $groups = [];
        foreach ($this->userOrgMapper->findOrgUuidsForUser($userInfo->getUuid()) as $orgUuid) {
            foreach ($this->scopeMatcher->resolveOrgAncestorUuids($orgUuid) as $uuid) {
                $groups[self::ORG_GROUP_PREFIX . $uuid] = true;
            }
        }
        return array_keys($groups);
    }

    /**
     * Tell a new assignee about the task. Someone without write access to the
     * case can't see or complete it, so it would stop the process, and the
     * notification would show them a case they may not read. Instead the task
     * is given back, for those who may take it, and the case's responsible is
     * told. This also covers an assignee losing access later. If the engine
     * can't be reached, the next sync tries again.
     */
    private function settleAssignee(ProcessTask $task, CaseProcess $process, ?CaseEntity $case, ?string $actingUserId, bool $newlyAssigned): void {
        $assignee = $task->getAssignee();
        if ($assignee === null) {
            return;
        }
        if ($this->permissionService->userHasWriteAccessToCase($assignee, $task->getCaseId())) {
            if ($newlyAssigned && $assignee !== $actingUserId) {
                $this->notifyAssigned($task, $case);
            }
            return;
        }

        try {
            $this->flowable->claimTask($task->getFlowableTaskId(), null);
        } catch (\Throwable $e) {
            $this->logger->warning('OpenCase: could not give back process task ' . $task->getId() . ': ' . $e->getMessage(), ['app' => 'opencase']);
            return;
        }
        $task->setAssignee(null);
        $this->taskMapper->update($task);
        $this->notifyGivenBack($task, $process, $case, $assignee);
    }

    private function notifyGivenBack(ProcessTask $task, CaseProcess $process, ?CaseEntity $case, string $assignee): void {
        $recipient = $case?->getResponsibleUserId() ?: $process->getStartedBy();
        if ($case === null || $recipient === null || $recipient === ''
            || !$this->permissionService->userHasReadAccessToCase($recipient, $case->getId())) {
            return;
        }
        try {
            $notification = $this->notificationManager->createNotification();
            $notification
                ->setApp('opencase')
                ->setUser($recipient)
                ->setDateTime(new \DateTime())
                ->setObject('process_task', (string)$task->getId())
                ->setSubject('process_task_given_back', [
                    'task_name' => (string)$task->getName(),
                    'assignee' => $this->userManager->getDisplayName($assignee) ?? $assignee,
                    'case_id' => $task->getCaseId(),
                    'case_number' => $case->getCaseNumber(),
                    'case_title' => $case->getTitle(),
                ]);
            $this->notificationManager->notify($notification);
        } catch (\Throwable) {
            // best-effort
        }
    }

    private function notifyAssigned(ProcessTask $task, ?CaseEntity $case): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification
                ->setApp('opencase')
                ->setUser((string)$task->getAssignee())
                ->setDateTime(new \DateTime())
                ->setObject('process_task', (string)$task->getId())
                ->setSubject('process_task_assigned', [
                    'task_name' => (string)$task->getName(),
                    'case_id' => $task->getCaseId(),
                    'case_number' => $case?->getCaseNumber() ?? '',
                    'case_title' => $case?->getTitle() ?? '',
                ]);
            $this->notificationManager->notify($notification);
        } catch (\Throwable) {
            // best-effort
        }
    }

    private function requireCase(int $caseId): CaseEntity {
        $case = $this->caseMapper->findById($caseId);
        if ($case === null) {
            throw new NotFoundException("Case {$caseId} not found");
        }
        return $case;
    }

    private function requireCaseProcess(int $caseId, int $caseProcessId): CaseProcess {
        $process = $this->caseProcessMapper->find($caseProcessId);
        if ($process === null || $process->getCaseId() !== $caseId) {
            throw new NotFoundException("Case process {$caseProcessId} not found");
        }
        return $process;
    }
}
