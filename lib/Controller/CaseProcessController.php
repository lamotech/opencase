<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\CaseProcess;
use OCA\OpenCase\Db\DocumentCategoryMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Db\ProcessDef;
use OCA\OpenCase\Db\ProcessJob;
use OCA\OpenCase\Db\ProcessTask;
use OCA\OpenCase\Db\ProcessVersion;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\PermissionService;
use OCA\OpenCase\Service\Process\CaseProcessService;
use OCA\OpenCase\Service\Process\ProcessConflictException;
use OCA\OpenCase\Service\Process\ProcessTopics;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserManager;

/**
 * REST endpoints for processes running on cases and their tasks. Viewing
 * needs read access to the case; starting, cancelling and working on tasks
 * need write access.
 *
 * Routes:
 *   GET    /api/v1/cases/{caseId}/processes                      — processes on the case + processes that can be started
 *   POST   /api/v1/cases/{caseId}/processes                      — start a process
 *   GET    /api/v1/cases/{caseId}/processes/{processId}/diagram  — BPMN + where the process is
 *   POST   /api/v1/cases/{caseId}/processes/{processId}/cancel   — cancel a running process
 *   POST   /api/v1/cases/{caseId}/processes/{processId}/retry    — retry a failed automatic step
 *   POST   /api/v1/process-tasks/{taskId}/claim                  — take an unassigned task
 *   POST   /api/v1/process-tasks/{taskId}/release                — give a task back
 *   POST   /api/v1/process-tasks/{taskId}/complete               — complete a task
 *   GET    /api/v1/me/process-tasks                              — open tasks for the current user
 *   GET    /api/v1/processes/available                           — processes for a case with given metadata (new case)
 */
class CaseProcessController extends OCSController {

    use CaseAccessGuard;

    /** @var array<string, string> */
    private array $displayNames = [];
    /** @var array<string, string> */
    private array $orgNames = [];

    public function __construct(
        string $appName,
        IRequest $request,
        private CaseProcessService $processService,
        private PermissionService $permissionService,
        private CaseMapper $caseMapper,
        private OrganisationMapper $organisationMapper,
        private DocumentMapper $documentMapper,
        private DocumentCategoryMapper $documentCategoryMapper,
        private IUserManager $userManager,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/cases/{caseId}/processes')]
    public function index(int $caseId): DataResponse {
        if ($denied = $this->denyUnlessCaseRead($caseId)) {
            return $denied;
        }
        if (!$this->processService->isEnabled()) {
            return new DataResponse(['processes' => [], 'available' => []]);
        }
        $case = $this->caseMapper->findById($caseId);
        if ($case === null) {
            return new DataResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
        }

        $canWrite = $this->permissionService->userHasWriteAccessToCase((string)$this->userId, $caseId);
        $processes = array_map(
            fn(array $row) => $this->serializeProcess($row['process'], $row['def'], $row['version'], $row['tasks'], $row['jobs'], $canWrite),
            $this->processService->listForCase($caseId),
        );
        $available = $canWrite ? array_map(fn(array $row) => [
            'id' => $row['def']->getId(),
            'name' => $row['def']->getName(),
            'description' => $row['def']->getDescription(),
            'version_no' => $row['version']->getVersionNo(),
        ], $this->processService->availableFor($case)) : [];

        return new DataResponse(['processes' => $processes, 'available' => $available]);
    }

    /**
     * Processes that could be started on a case with this metadata, for
     * choosing one while creating the case.
     *
     * Query: casetype_id, org_uuid, classification_code (KLE number),
     * class_plan_uuid (Emneplan item)
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/processes/available')]
    public function availableForNewCase(): DataResponse {
        if (!$this->processService->isEnabled()) {
            return new DataResponse(['processes' => []]);
        }
        $casetypeRaw = $this->request->getParam('casetype_id');
        $processes = $this->processService->availableForMetadata(
            $casetypeRaw !== null && $casetypeRaw !== '' ? (int)$casetypeRaw : null,
            $this->request->getParam('org_uuid') ?: null,
            $this->request->getParam('classification_code') ?: null,
            $this->request->getParam('class_plan_uuid') ?: null,
        );
        return new DataResponse(['processes' => array_map(fn(array $row) => [
            'id' => $row['def']->getId(),
            'name' => $row['def']->getName(),
            'description' => $row['def']->getDescription(),
        ], $processes)]);
    }

    /**
     * Body: { "process_def_id": int }
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/cases/{caseId}/processes')]
    public function start(int $caseId): DataResponse {
        if ($denied = $this->denyUnlessCaseWrite($caseId)) {
            return $denied;
        }
        $defId = (int)$this->request->getParam('process_def_id', 0);

        return $this->handle(function () use ($caseId, $defId) {
            $process = $this->processService->start($caseId, $defId, (string)$this->userId);
            return new DataResponse(['process_id' => $process->getId()], Http::STATUS_CREATED);
        });
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/cases/{caseId}/processes/{processId}/diagram')]
    public function diagram(int $caseId, int $processId): DataResponse {
        if ($denied = $this->denyUnlessCaseRead($caseId)) {
            return $denied;
        }
        return $this->handle(function () use ($caseId, $processId) {
            $row = $this->processService->getForDiagram($caseId, $processId);
            return new DataResponse([
                'bpmn_xml' => $row['version']->getBpmnXml(),
                'active_activities' => $row['process']->getActiveActivityIds(),
                'completed_activities' => $row['process']->getCompletedActivityIds(),
            ]);
        });
    }

    /**
     * Body: { "reason": string }
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/cases/{caseId}/processes/{processId}/cancel')]
    public function cancel(int $caseId, int $processId): DataResponse {
        if ($denied = $this->denyUnlessCaseWrite($caseId)) {
            return $denied;
        }
        $reason = (string)$this->request->getParam('reason', '');

        return $this->handle(function () use ($caseId, $processId, $reason) {
            $this->processService->cancel($caseId, $processId, (string)$this->userId, $reason);
            return new DataResponse([]);
        });
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/cases/{caseId}/processes/{processId}/retry')]
    public function retry(int $caseId, int $processId): DataResponse {
        if ($denied = $this->denyUnlessCaseWrite($caseId)) {
            return $denied;
        }
        return $this->handle(function () use ($caseId, $processId) {
            $this->processService->retryFailedStep($caseId, $processId, (string)$this->userId);
            return new DataResponse([]);
        });
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/process-tasks/{taskId}/claim')]
    public function claim(int $taskId): DataResponse {
        return $this->taskAction($taskId, fn() => $this->processService->claim($taskId, (string)$this->userId));
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/process-tasks/{taskId}/release')]
    public function release(int $taskId): DataResponse {
        return $this->taskAction($taskId, fn() => $this->processService->release($taskId, (string)$this->userId));
    }

    /**
     * Body: { "outcome": string|null, "comment": string|null, "values": {field id: value} }
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/process-tasks/{taskId}/complete')]
    public function complete(int $taskId): DataResponse {
        $outcome = $this->request->getParam('outcome');
        $comment = $this->request->getParam('comment');
        $values = $this->request->getParam('values', []);
        return $this->taskAction($taskId, fn() => $this->processService->complete(
            $taskId,
            (string)$this->userId,
            $outcome !== null && $outcome !== '' ? (string)$outcome : null,
            $comment !== null ? (string)$comment : null,
            is_array($values) ? $values : [],
        ));
    }

    /**
     * Open tasks the current user can work on, on cases they can see,
     * ordered by due date (undated last).
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/me/process-tasks')]
    public function myTasks(): DataResponse {
        if ($this->userId === null || !$this->processService->isEnabled()) {
            return new DataResponse(['tasks' => []]);
        }
        $tasks = [];
        foreach ($this->processService->openTasksFor($this->userId) as $task) {
            if (!$this->permissionService->userHasWriteAccessToCase($this->userId, $task->getCaseId())) {
                continue;
            }
            $case = $this->caseMapper->findById($task->getCaseId());
            $tasks[] = $this->serializeTask($task, true) + [
                'case_number' => $case?->getCaseNumber() ?? '',
                'case_title' => $case?->getTitle() ?? '',
            ];
        }
        usort($tasks, function (array $a, array $b): int {
            if ($a['due_date'] === $b['due_date']) {
                return 0;
            }
            if ($a['due_date'] === null) {
                return 1;
            }
            if ($b['due_date'] === null) {
                return -1;
            }
            return strcmp($a['due_date'], $b['due_date']);
        });
        return new DataResponse(['tasks' => $tasks]);
    }

    /**
     * Run an action on a task after checking write access to its case.
     *
     * @param callable(): ProcessTask $action
     */
    private function taskAction(int $taskId, callable $action): DataResponse {
        try {
            $task = $this->processService->getTask($taskId);
        } catch (NotFoundException) {
            return new DataResponse(['error' => 'Task not found'], Http::STATUS_NOT_FOUND);
        }
        if ($denied = $this->denyUnlessCaseWrite($task->getCaseId())) {
            return $denied;
        }
        return $this->handle(fn() => new DataResponse(['task' => $this->serializeTask($action(), true)]));
    }

    /**
     * Run $action, mapping service exceptions to error responses.
     */
    private function handle(callable $action): DataResponse {
        try {
            return $action();
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (ProcessConflictException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        } catch (\RuntimeException $e) {
            // Process engine unreachable or misconfigured
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
        }
    }

    /**
     * @param ProcessTask[] $tasks
     * @param ProcessJob[] $jobs
     */
    private function serializeProcess(CaseProcess $process, ?ProcessDef $def, ?ProcessVersion $version, array $tasks, array $jobs, bool $canWrite): array {
        return [
            'id' => $process->getId(),
            'process_def_id' => $process->getProcessDefId(),
            'name' => $def?->getName() ?? '',
            'version_no' => $version?->getVersionNo(),
            'status' => $process->getStatus(),
            'started_at' => $process->getStartedAt()?->format('c'),
            'started_by' => $process->getStartedBy(),
            'started_by_name' => $this->displayName($process->getStartedBy()),
            'ended_at' => $process->getEndedAt()?->format('c'),
            'ended_by_name' => $this->displayName($process->getEndedBy()),
            'end_reason' => $process->getEndReason(),
            'last_synced_at' => $process->getLastSyncedAt()?->format('c'),
            'error_message' => $process->getErrorMessage(),
            // An automatic step that failed and will be retried by the engine
            'retrying_step' => $this->retryingStep($process, $jobs),
            'tasks' => array_map(fn(ProcessTask $t) => $this->serializeTask($t, $canWrite), $tasks),
            // Automatic steps run so far
            'steps' => array_map(fn(ProcessJob $j) => [
                'id' => $j->getId(),
                'name' => $j->getElementName() ?: (ProcessTopics::get($j->getTopic())['label'] ?? $j->getTopic()),
                'action' => ProcessTopics::get($j->getTopic())['label'] ?? $j->getTopic(),
                'status' => $j->getStatus(),
                'acted_as_name' => $this->displayName($j->getActedAs()),
                'error' => $j->getError(),
                'finished_at' => $j->getFinishedAt()?->format('c'),
                'created_at' => $j->getCreatedAt()?->format('c'),
            ] + $this->stepResult($j), $jobs),
        ];
    }

    private function serializeTask(ProcessTask $task, bool $canWrite): array {
        $mine = $task->getAssignee() !== null && $task->getAssignee() === $this->userId;
        $eligible = $canWrite && $this->userId !== null && $this->processService->isEligible($task, $this->userId);
        $outcomeLabel = null;
        foreach ($task->getOutcomeList() as $o) {
            if ($o['id'] === $task->getOutcome()) {
                $outcomeLabel = $o['name'];
            }
        }

        $candidateOrgs = [];
        foreach ($task->getCandidateGroupList() as $group) {
            if (str_starts_with($group, 'org:')) {
                $uuid = substr($group, 4);
                $candidateOrgs[] = ['uuid' => $uuid, 'name' => $this->orgName($uuid)];
            }
        }

        return [
            'id' => $task->getId(),
            'case_process_id' => $task->getCaseProcessId(),
            'case_id' => $task->getCaseId(),
            'activity_id' => $task->getActivityId(),
            'name' => $task->getName(),
            'status' => $task->getStatus(),
            'assignee' => $task->getAssignee(),
            'assignee_name' => $this->displayName($task->getAssignee()),
            'candidate_orgs' => $candidateOrgs,
            'due_date' => $task->getDueDate()?->format('c'),
            'outcomes' => $task->getOutcomeList(),
            'outcome' => $task->getOutcome(),
            'outcome_label' => $outcomeLabel,
            'comment' => $task->getComment(),
            'form_fields' => $task->getFormFieldList(),
            // Entered values with their labels, for the history
            'form_values' => $this->formValuesForDisplay($task),
            'created_at' => $task->getCreatedAt()?->format('c'),
            'completed_at' => $task->getCompletedAt()?->format('c'),
            'completed_by' => $task->getCompletedBy(),
            'completed_by_name' => $this->displayName($task->getCompletedBy()),
            // What the current user can do with the task
            'can_complete' => $eligible,
            'can_claim' => $eligible && $task->getAssignee() === null,
            'can_release' => $canWrite && $mine && $task->getStatus() === ProcessTask::STATUS_OPEN,
        ];
    }

    /**
     * What a step produced that the case history links to or warns about:
     * a created sub-case, and a process that couldn't be started on it.
     *
     * @return array{linked_case?: array{id: int, case_number: string}, warning?: string, info?: string, wait?: array}
     */
    private function stepResult(ProcessJob $job): array {
        $result = json_decode((string)$job->getResult(), true);
        if (!is_array($result)) {
            return [];
        }
        $extra = [];
        if ($job->getTopic() === ProcessTopics::CREATE_SUB_CASE && isset($result['case_id'])) {
            $extra['linked_case'] = ['id' => (int)$result['case_id'], 'case_number' => (string)($result['case_number'] ?? '')];
        }
        if (!empty($result['process_error'])) {
            $extra['warning'] = 'Processen kunne ikke startes på undersagen: ' . $result['process_error'];
        }
        if ($job->getTopic() === ProcessTopics::WAIT_FOR_INACTIVITY) {
            // ISO times; the browser formats them in local time
            $extra['wait'] = array_intersect_key($result, array_flip(['waiting_until', 'last_activity', 'inactive_since', 'days']));
        }
        if ($job->getTopic() === ProcessTopics::SEND_NOTIFICATION) {
            $names = fn(array $uids) => implode(', ', array_map(fn(string $u) => $this->displayName($u) ?? $u, $uids));
            $extra['info'] = ($result['recipients'] ?? []) !== []
                ? 'Sendt til ' . $names($result['recipients'])
                : 'Ingen at sende til';
            if (!empty($result['skipped'])) {
                $extra['warning'] = 'Ikke sendt til ' . $names($result['skipped']) . ' (ingen adgang til sagen)';
            }
        }
        if ($job->getTopic() === ProcessTopics::WAIT_FOR_DOCUMENT) {
            if (isset($result['document_id'])) {
                $extra['info'] = 'Modtaget: ' . trim(($result['document_number'] ?? '') . ' ' . ($result['title'] ?? ''));
            } elseif (!empty($result['category_id'])) {
                $category = $this->documentCategoryMapper->getNameMap('da')[(int)$result['category_id']] ?? (string)$result['category_id'];
                $extra['info'] = "Venter på et dokument i kategorien \"{$category}\"";
            } else {
                $extra['info'] = 'Venter på et dokument';
            }
        }
        if ($job->getTopic() === ProcessTopics::REQUEST_APPROVAL && isset($result['workflow_id'])) {
            $outcomes = array_column(ProcessTopics::get(ProcessTopics::REQUEST_APPROVAL)['outcomes'], 'name', 'id');
            $document = $this->documentMapper->findById((int)$result['document_id']);
            $what = ($result['type'] ?? '') === ProcessTopics::APPROVAL_TYPE_REVIEW ? 'gennemsyn' : 'godkendelse';
            $extra['info'] = isset($result['outcome'])
                ? 'Udfald: ' . ($outcomes[$result['outcome']] ?? $result['outcome'])
                : '"' . ($document?->getTitle() ?? '') . "\" er sendt til {$what} hos "
                    . implode(', ', array_map(fn(string $u) => $this->displayName($u) ?? $u, $result['approvers'] ?? []));
        }
        if ($job->getTopic() === ProcessTopics::ASSIGN_CASE && isset($result['user'])) {
            $name = $this->displayName($result['user']) ?? $result['user'];
            $extra['info'] = match (true) {
                !empty($result['unchanged']) => ($result['role'] === ProcessTopics::ROLE_RESPONSIBLE ? "{$name} var allerede ansvarlig" : "{$name} var allerede sagsbehandler"),
                $result['role'] === ProcessTopics::ROLE_RESPONSIBLE => "{$name} er gjort ansvarlig" . (!empty($result['previous']) ? ' (før: ' . ($this->displayName($result['previous']) ?? $result['previous']) . ')' : ''),
                default => "{$name} er tilføjet som sagsbehandler",
            };
        }
        if ($job->getTopic() === ProcessTopics::CREATE_REMINDER && isset($result['user'])) {
            $extra['info'] = 'Påmindelse til ' . ($this->displayName($result['user']) ?? $result['user'])
                . (!empty($result['deadline']) ? ' med frist ' . (new \DateTime($result['deadline']))->setTimezone(new \DateTimeZone('Europe/Copenhagen'))->format('d-m-Y') : ' uden frist');
        }
        if ($job->getTopic() === ProcessTopics::SET_CUSTOM_FIELD && isset($result['field'])) {
            $extra['info'] = $result['value'] !== null ? "{$result['field']}: {$result['value']}" : "{$result['field']} er ryddet";
        }
        if ($job->getTopic() === ProcessTopics::ADD_RECIPIENT && isset($result['document_id'])) {
            $extra['info'] = ($result['added'] ?? []) !== [] ? 'Tilføjet som modtager: ' . implode(', ', $result['added']) : 'Ingen nye modtagere';
            if (!empty($result['skipped'])) {
                $extra['info'] .= ' (allerede modtager: ' . implode(', ', $result['skipped']) . ')';
            }
        }
        if ($job->getTopic() === ProcessTopics::SEND_DIGITAL_POST && !empty($result['already_sent'])) {
            $title = $this->documentMapper->findById((int)$result['document_id'])?->getTitle() ?? '';
            $extra['info'] = "\"{$title}\" var allerede sendt med Digital Post (status: {$result['status']}) og er ikke sendt igen";
        }
        if ($job->getTopic() === ProcessTopics::FINALIZE_DOCUMENT && isset($result['document_id'])) {
            $title = $this->documentMapper->findById((int)$result['document_id'])?->getTitle() ?? '';
            $extra['info'] = !empty($result['already_final']) ? "\"{$title}\" var allerede endeligt" : "\"{$title}\" er gjort endeligt";
        }
        return $extra;
    }

    /**
     * The step the process is waiting at, if its last attempt failed but it
     * hasn't failed for good (then error_message is set instead).
     *
     * @param ProcessJob[] $jobs
     * @return array{name: string, error: ?string, failed_at: ?string}|null
     */
    private function retryingStep(CaseProcess $process, array $jobs): ?array {
        if ($process->getStatus() !== CaseProcess::STATUS_RUNNING || $process->getErrorMessage() !== null) {
            return null;
        }
        $active = $process->getActiveActivityIds();
        foreach (array_reverse($jobs) as $job) {
            if (!in_array($job->getElementId(), $active, true)) {
                continue;
            }
            return $job->getStatus() === ProcessJob::STATUS_FAILED ? [
                'name' => $job->getElementName() ?: $job->getElementId(),
                'error' => $job->getError(),
                'failed_at' => $job->getFinishedAt()?->format('c'),
            ] : null;
        }
        return null;
    }

    /**
     * @return array{label: string, value: string}[]
     */
    private function formValuesForDisplay(ProcessTask $task): array {
        $values = $task->getFormValueList();
        $display = [];
        foreach ($task->getFormFieldList() as $field) {
            if (!array_key_exists($field['id'], $values)) {
                continue;
            }
            $value = $values[$field['id']];
            $value = match ($field['type']) {
                'boolean' => $value === 'true' ? 'Ja' : 'Nej',
                'date' => (\DateTime::createFromFormat('Y-m-d', $value) ?: null)?->format('d-m-Y') ?? $value,
                'enum' => array_column($field['options'] ?? [], 'name', 'id')[$value] ?? $value,
                'user' => $this->displayName($value) ?? $value,
                default => $value,
            };
            $display[] = ['label' => $field['name'], 'value' => $value];
        }
        return $display;
    }

    private function displayName(?string $userId): ?string {
        if ($userId === null || $userId === '') {
            return null;
        }
        return $this->displayNames[$userId] ??= ($this->userManager->getDisplayName($userId) ?? $userId);
    }

    private function orgName(string $uuid): string {
        return $this->orgNames[$uuid] ??= ($this->organisationMapper->findByUuid($uuid)?->getOrgName() ?? $uuid);
    }
}
