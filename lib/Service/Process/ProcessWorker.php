<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\Process;

use OCA\OpenCase\Db\CaseEntity;
use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\AccessProfile;
use OCA\OpenCase\Db\AccessProfileMapper;
use OCA\OpenCase\Db\AuditLogMapper;
use OCA\OpenCase\Db\CaseParticipantMapper;
use OCA\OpenCase\Db\CaseWorkerMapper;
use OCA\OpenCase\Db\CustomFieldDefMapper;
use OCA\OpenCase\Db\DocWorkflowEntity;
use OCA\OpenCase\Db\DocWorkflowMapper;
use OCA\OpenCase\Db\Document;
use OCA\OpenCase\Db\DocumentContactMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\DocumentStatusMapper;
use OCA\OpenCase\Db\FileMapper;
use OCA\OpenCase\Db\CaseProcess;
use OCA\OpenCase\Db\CaseProcessMapper;
use OCA\OpenCase\Db\CaseStatusMapper;
use OCA\OpenCase\Db\JournalNote;
use OCA\OpenCase\Db\JournalNoteMapper;
use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Db\ProcessJob;
use OCA\OpenCase\Db\ProcessJobMapper;
use OCA\OpenCase\Db\ProcessVersionMapper;
use OCA\OpenCase\Db\Reminder;
use OCA\OpenCase\Db\ReminderMapper;
use OCA\OpenCase\Service\AuditService;
use OCA\OpenCase\Service\CaseService;
use OCA\OpenCase\Service\CaseWorkerService;
use OCA\OpenCase\Service\CustomFieldService;
use OCA\OpenCase\Service\DigitalPostService;
use OCA\OpenCase\Service\DocumentContactService;
use OCA\OpenCase\Service\DocumentService;
use OCA\OpenCase\Service\FileService;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\PermissionService;
use OCA\OpenCase\Service\TemplateFileService;
use OCA\OpenCase\Service\TemplateService;
use OCA\OpenCase\Service\WorkflowService;
use OCP\DB\Exception as DBException;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Runs the automatic steps (BPMN service tasks, see ProcessTopics) of
 * processes on cases, as a Flowable external worker: locks the jobs waiting
 * for OpenCase, performs them and reports them done or failed.
 *
 * A step acts as the case's responsible caseworker, or else the user who
 * started the process, and needs write access to the case. Errors in the
 * process configuration (e.g. a template that no longer exists) fail the
 * step at once; other errors are retried by the engine. A step that failed
 * for good stops the process until a user retries it
 * (CaseProcessService::retryFailedStep).
 *
 * Called by ProcessWorkerJob, and right after actions in OpenCase so steps
 * following a completed task run at once.
 */
class ProcessWorker {

    private const LOCK_DURATION = 'PT10M';

    /** Document contact role "Modtager" (seeded with id 2), as in the Digital Post dialog. */
    private const RECEIVER_ROLE_ID = 2;
    /** Case participant role of the primary party. */
    private const PRIMARY_PARTY_ROLE_ID = 0;
    private const RETRY_TIMEOUT = 'PT5M';
    /** How often a step waiting for a document or an approval looks again. */
    private const POLL_SECONDS = 120;
    /** Document status "Endelig" (is_final). */
    private const FINAL_STATUS_ID = 3;
    /** Process variable gateway conditions test, as for user tasks. */
    private const OUTCOME_VARIABLE = 'udfald';
    /** Rounds of run() in one call: steps that make further steps ready run at once. */
    private const MAX_PASSES = 5;

    public function __construct(
        private FlowableClient $flowable,
        private CaseProcessMapper $caseProcessMapper,
        private ProcessVersionMapper $versionMapper,
        private ProcessJobMapper $jobMapper,
        private CaseMapper $caseMapper,
        private CaseStatusMapper $caseStatusMapper,
        private JournalNoteMapper $journalNoteMapper,
        private CaseService $caseService,
        private DocumentService $documentService,
        private FileService $fileService,
        private TemplateService $templateService,
        private TemplateFileService $templateFileService,
        private PermissionService $permissionService,
        private DigitalPostService $digitalPost,
        private DocumentMapper $documentMapper,
        private DocumentContactMapper $documentContactMapper,
        private CaseParticipantMapper $caseParticipantMapper,
        private FileMapper $fileMapper,
        private OrganisationMapper $organisationMapper,
        private AccessProfileMapper $accessProfileMapper,
        private CaseWorkerMapper $caseWorkerMapper,
        private AuditLogMapper $auditLogMapper,
        private DocumentStatusMapper $documentStatusMapper,
        private DocWorkflowMapper $docWorkflowMapper,
        private WorkflowService $workflowService,
        private CaseWorkerService $caseWorkerService,
        private CustomFieldDefMapper $customFieldDefMapper,
        private CustomFieldService $customFieldService,
        private DocumentContactService $documentContactService,
        private ReminderMapper $reminderMapper,
        private IUserManager $userManager,
        private INotificationManager $notificationManager,
        private AuditService $auditService,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Run the waiting steps, up to $maxPerTopic of each kind.
     *
     * @return int[] ids of the case processes that had steps run
     */
    public function run(int $maxPerTopic): array {
        if (!$this->flowable->isConfigured()) {
            return [];
        }
        $touched = [];
        // A step can make the next step ready (also of a kind already
        // handled in this pass), so go round again while there is work
        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $ran = 0;
            foreach (array_keys(ProcessTopics::all()) as $topic) {
                foreach ($this->flowable->acquireJobs($topic, $maxPerTopic, $this->workerId(), self::LOCK_DURATION) as $job) {
                    $ran++;
                    $caseProcessId = $this->runJob($topic, $job);
                    if ($caseProcessId !== null) {
                        $touched[$caseProcessId] = true;
                    }
                }
            }
            if ($ran === 0) {
                break;
            }
        }
        return array_keys($touched);
    }

    /**
     * @param array{id: string, element_id: string, element_name: ?string, process_instance_id: string, process_definition_id: string, retries: int} $job
     * @return int|null the case process id, if the job belongs to one
     */
    private function runJob(string $topic, array $job): ?int {
        $process = $this->caseProcessMapper->findByFlowableInstanceId($job['process_instance_id']);
        if ($process === null) {
            // Not started through OpenCase: nothing to act on
            $this->flowable->failJob($job['id'], $this->workerId(), 'Processen er ikke startet fra OpenCase.', 0, self::RETRY_TIMEOUT);
            return null;
        }

        $log = $this->jobMapper->findByFlowableJobId($job['id']);
        if ($log !== null && $log->getStatus() === ProcessJob::STATUS_DONE) {
            // Done before, but the engine didn't get the "complete" call
            $outcome = json_decode((string)$log->getResult(), true)['outcome'] ?? null;
            $this->flowable->completeJob($job['id'], $this->workerId(), $outcome !== null ? [self::OUTCOME_VARIABLE => $outcome] : []);
            return $process->getId();
        }
        $log ??= $this->newLog($process, $topic, $job);
        if ($log === null) {
            // Another worker is running it right now
            return $process->getId();
        }

        try {
            $case = $this->caseMapper->findById($process->getCaseId());
            if ($case === null) {
                throw new NotFoundException('Sagen findes ikke.');
            }
            $userId = $case->getResponsibleUserId() ?: (string)$process->getStartedBy();
            $log->setActedAs($userId);

            $version = $this->versionMapper->findByFlowableProcDefId($job['process_definition_id']);
            if ($version === null) {
                throw new NotFoundException('Procesversionen findes ikke i OpenCase.');
            }
            $params = BpmnXml::serviceTaskFields($version->getBpmnXml(), $job['element_id']);

            if ($topic === ProcessTopics::CLOSE_CASE) {
                $this->closeCase($case, $userId, $job, $log);
                return $process->getId();
            }
            $xml = $version->getBpmnXml();
            $target = fn() => $this->targetCase($case, $process, $xml, $job['element_id'], $params);

            // Steps that wait: null while waiting (the job was handed back),
            // else the process variables to complete the job with
            $variables = match ($topic) {
                ProcessTopics::WAIT_FOR_INACTIVITY => $this->waitForInactivity($target(), $params, $job, $log),
                ProcessTopics::WAIT_FOR_DOCUMENT => $this->waitForDocument($target(), $params, $job, $log),
                ProcessTopics::REQUEST_APPROVAL => $this->requestApproval($target(), $userId, $params, $process, $xml, $job, $log),
                default => false,
            };
            if ($variables === null) {
                return $process->getId();
            }
            if ($variables !== false) {
                $this->auditService->logProcessStepExecuted($case->getId(), $userId, $process->getId(), (string)($job['element_name'] ?? $job['element_id']), $topic);
                $this->reportDone($job, $variables);
                return $process->getId();
            }

            $result = match ($topic) {
                ProcessTopics::ADD_JOURNAL_NOTE => $this->addJournalNote($target(), $userId, $params),
                ProcessTopics::CREATE_DOCUMENT => $this->createDocument($target(), $userId, $params),
                ProcessTopics::SEND_DIGITAL_POST => $this->sendDigitalPost($target(), $userId, $params, $process, $xml, $job['element_id']),
                ProcessTopics::CREATE_SUB_CASE => $this->createSubCase($case, $userId, $params, $process),
                ProcessTopics::SEND_NOTIFICATION => $this->sendNotification($target(), $params, (string)($job['element_name'] ?? '')),
                ProcessTopics::FINALIZE_DOCUMENT => $this->finalizeDocument($target(), $userId, $params, $process, $xml, $job['element_id']),
                ProcessTopics::ASSIGN_CASE => $this->assignCase($target(), $userId, $params, $process),
                ProcessTopics::SET_CUSTOM_FIELD => $this->setCustomField($target(), $userId, $params, $process),
                ProcessTopics::ADD_RECIPIENT => $this->addRecipient($target(), $userId, $params, $process, $xml, $job['element_id']),
                ProcessTopics::CREATE_REMINDER => $this->createReminder($target(), $userId, $params, $process, $xml, $job['element_id']),
                default => throw new \InvalidArgumentException("Ukendt automatisk handling: {$topic}"),
            };
            $this->markDone($log, $result);
            $this->auditService->logProcessStepExecuted($case->getId(), $userId, $process->getId(), (string)($job['element_name'] ?? $job['element_id']), $topic);
        } catch (\Throwable $e) {
            $this->fail($log, $job, $e);
            return $process->getId();
        }
        $this->reportDone($job);
        return $process->getId();
    }

    /**
     * @param array<string, string> $params
     * @return array<string, int>
     */
    private function addJournalNote(CaseEntity $case, string $userId, array $params): array {
        $this->requireWriteAccess($case, $userId);
        $title = trim($params['title'] ?? '');
        if ($title === '') {
            throw new \InvalidArgumentException('Journalnotatet mangler en titel.');
        }

        $note = new JournalNote();
        $note->setCaseId($case->getId());
        $note->setTitle($title);
        $note->setText(($params['text'] ?? '') !== '' ? $params['text'] : null);
        $note->setIsLocked(0);
        $note->setCreatedBy($userId);
        $now = new \DateTime();
        $note->setCreatedAt($now);
        $note->setUpdatedAt($now);
        $note = $this->journalNoteMapper->insert($note);

        $this->auditService->logJournalNoteCreated($case->getId(), $userId, $note->getId(), $title);
        return ['journal_note_id' => $note->getId()];
    }

    /**
     * @param array<string, string> $params
     * @return array<string, int>
     */
    private function createDocument(CaseEntity $case, string $userId, array $params): array {
        $this->requireWriteAccess($case, $userId);
        $title = trim($params['title'] ?? '');
        $categoryId = (int)($params['category'] ?? 0);
        $templateId = (int)($params['template'] ?? 0);
        // Check the template before anything is created
        $template = $templateId > 0 ? $this->templateService->get($templateId) : null;
        if ($template !== null && !$this->templateService->isAvailableForOrg($templateId, $case->getOrgUuid())) {
            throw new \InvalidArgumentException("Skabelonen \"{$template->getName()}\" er ikke tilgængelig for sagens organisation.");
        }

        $document = $this->documentService->create(
            $case->getId(),
            $title,
            null,
            $userId,
            documentCategoryId: $categoryId > 0 ? $categoryId : null,
        );
        $result = ['document_id' => $document->getId()];
        if ($template === null) {
            return $result;
        }

        try {
            FileService::sanitiseFilename($title, $template->getOriginalFilename());
            $filename = $title;
        } catch (\InvalidArgumentException) {
            $filename = '';
        }
        // As the "Ny fil fra skabelon" dialog with nothing changed: required
        // and pre-selected phrases, and the organisation's logo
        $file = $this->templateFileService->createFile($document->getId(), $templateId, $filename, null, $userId);
        $result['file_id'] = $file->getId();
        return $result;
    }

    /**
     * The case an action works on: the process's case, or with target
     * "subcase" the sub-case created by the nearest "Opret undersag" step
     * before this step in the same branch.
     *
     * @param array<string, string> $params
     */
    private function targetCase(CaseEntity $case, CaseProcess $process, string $xml, string $elementId, array $params): CaseEntity {
        if (($params['target'] ?? ProcessTopics::TARGET_CASE) !== ProcessTopics::TARGET_SUB_CASE) {
            return $case;
        }
        $nearest = BpmnXml::nearestPrecedingServiceTasks($xml, $elementId, ProcessTopics::CREATE_SUB_CASE);
        if (count($nearest) > 1) {
            throw new \InvalidArgumentException('Trinnet kommer efter flere "Opret undersag"-trin på samme afstand (f.eks. efter at grenene er samlet), så det er uklart hvilken undersag der menes. Placér trinnet i grenen før den samles.');
        }
        $done = $this->doneStepResults($process);
        foreach ($nearest as $subCaseStep) {
            $subCaseId = (int)($done[$subCaseStep]['case_id'] ?? 0);
            $subCase = $subCaseId > 0 ? $this->caseMapper->findById($subCaseId) : null;
            if ($subCase !== null && $subCase->getParentCaseId() === $case->getId()) {
                return $subCase;
            }
        }
        throw new \InvalidArgumentException('Der er ingen undersag fra et tidligere "Opret undersag"-trin før dette trin.');
    }

    /**
     * Results of the process's steps that are done, by element id (the
     * latest run of each).
     *
     * @return array<string, array>
     */
    private function doneStepResults(CaseProcess $process): array {
        $results = [];
        foreach ($this->jobMapper->findGroupedByCaseProcessIds([$process->getId()])[$process->getId()] ?? [] as $step) {
            if ($step->getStatus() === ProcessJob::STATUS_DONE) {
                $results[$step->getElementId()] = json_decode((string)$step->getResult(), true) ?: [];
            }
        }
        return $results;
    }

    /**
     * Wait until the case has had no activity (updated_at) for N days, and
     * the step was reached at least N days ago (so a loop back to this step
     * doesn't fire again at once). Until then the job is handed back to the
     * engine to be offered again exactly when the wait could be over.
     *
     * @param array<string, string> $params
     * @return array|null process variables when done, null while waiting
     */
    private function waitForInactivity(CaseEntity $case, array $params, array $job, ProcessJob $log): ?array {
        $days = (int)($params['days'] ?? 0);
        if ($days < 1) {
            throw new \InvalidArgumentException('Angiv antal dage uden aktivitet (mindst 1).');
        }
        $lastActivity = $case->getUpdatedAt();
        $from = max($lastActivity, $this->reachedAt($job, $log));
        $until = (clone $from)->modify("+{$days} days");
        $now = new \DateTime();

        if ($now >= $until) {
            $this->markDone($log, ['inactive_since' => $lastActivity->format('c'), 'days' => $days]);
            return [];
        }
        $this->handBack($log, $job,
            ['waiting_until' => $until->format('c'), 'last_activity' => $lastActivity->format('c'), 'days' => $days],
            'Venter på inaktivitet til ' . $until->format('c'),
            $until->getTimestamp() - $now->getTimestamp(),
        );
        return null;
    }

    /**
     * Wait for a document to arrive on the case after the step was reached:
     * created by a user, received by an inbound job, or moved here from the
     * inbox — in the chosen category, if any. The category is checked as it
     * is now, so a document filed into it later also counts.
     *
     * @param array<string, string> $params
     * @return array|null process variables when done, null while waiting
     */
    private function waitForDocument(CaseEntity $case, array $params, array $job, ProcessJob $log): ?array {
        $categoryId = (int)($params['category'] ?? 0);
        $reached = $this->reachedAt($job, $log);
        foreach ($this->auditLogMapper->findDocumentArrivals($case->getId(), $reached) as $documentId) {
            $document = $this->documentMapper->findById($documentId);
            if ($document === null || $document->isSoftDeleted() || $document->getCaseId() !== $case->getId()
                || ($categoryId > 0 && $document->getDocumentCategoryId() !== $categoryId)) {
                continue;
            }
            $this->markDone($log, [
                'document_id' => $document->getId(),
                'document_number' => $document->getDocumentNumber(),
                'title' => $document->getTitle(),
            ]);
            return [];
        }
        $this->handBack($log, $job,
            ['waiting_for' => 'document', 'category_id' => $categoryId > 0 ? $categoryId : null, 'since' => $reached->format('c')],
            'Venter på et dokument',
            self::POLL_SECONDS,
        );
        return null;
    }

    /**
     * Start a review or approval workflow on the process's document and wait
     * for it to end; the outcome goes into the process variable "udfald".
     * The workflow's id is kept in the step's result, so later rounds only
     * look at it. If the document already has an active workflow, the step
     * waits for that one instead of failing.
     *
     * @param array<string, string> $params
     * @return array|null process variables when done, null while waiting
     */
    private function requestApproval(CaseEntity $case, string $userId, array $params, CaseProcess $process, string $xml, array $job, ProcessJob $log): ?array {
        $previous = json_decode((string)$log->getResult(), true) ?: [];
        $workflowId = (int)($previous['workflow_id'] ?? 0);
        $workflow = $workflowId > 0 ? $this->docWorkflowMapper->findById($workflowId) : null;
        if ($workflow === null) {
            $workflow = $this->startApproval($case, $userId, $params, $process, $xml, $job['element_id']);
            $previous = [];
        }
        $info = [
            'workflow_id' => $workflow->getId(),
            'document_id' => $workflow->getDocumentId(),
            'type' => $workflow->getType(),
            'approvers' => $previous['approvers'] ?? array_map(fn($step) => $step->getUserId(), $this->workflowService->getSteps($workflow->getId())),
        ];

        if ($workflow->isActive()) {
            $this->handBack($log, $job, $info, 'Venter på godkendelse', self::POLL_SECONDS);
            return null;
        }
        $outcome = match ($workflow->getStatus()) {
            'completed' => $workflow->getType() === ProcessTopics::APPROVAL_TYPE_REVIEW ? ProcessTopics::OUTCOME_REVIEWED : ProcessTopics::OUTCOME_APPROVED,
            'rejected' => ProcessTopics::OUTCOME_REJECTED,
            default => ProcessTopics::OUTCOME_CANCELLED,
        };
        $this->markDone($log, $info + ['outcome' => $outcome]);
        return [self::OUTCOME_VARIABLE => $outcome];
    }

    /**
     * @param array<string, string> $params
     */
    private function startApproval(CaseEntity $case, string $userId, array $params, CaseProcess $process, string $xml, string $elementId): DocWorkflowEntity {
        $this->requireWriteAccess($case, $userId);
        $type = $params['type'] ?? ProcessTopics::APPROVAL_TYPE_APPROVAL;
        if (!in_array($type, [ProcessTopics::APPROVAL_TYPE_APPROVAL, ProcessTopics::APPROVAL_TYPE_REVIEW], true)) {
            throw new \InvalidArgumentException("Ukendt type: {$type}");
        }
        $document = $this->documentFromProcess($case, $process, $xml, $elementId, (int)($params['category'] ?? 0));

        $active = $this->docWorkflowMapper->findActiveByDocument($document->getId());
        if ($active !== null) {
            return $active;
        }

        $mode = $params['approvers'] ?? ProcessTopics::RECIPIENTS_USER;
        $approvers = match ($mode) {
            ProcessTopics::RECIPIENTS_RESPONSIBLE => array_filter([$case->getResponsibleUserId()]),
            ProcessTopics::RECIPIENTS_CASEWORKERS => array_values(array_unique(array_filter(
                array_map(fn($w) => $w->getUserId(), $this->caseWorkerMapper->findByCase($case->getId())),
                fn(string $uid) => $uid !== $case->getResponsibleUserId(),
            ))),
            ProcessTopics::RECIPIENTS_USER => array_filter([trim($params['user'] ?? '')]),
            default => throw new \InvalidArgumentException("Ukendt modtagervalg: {$mode}"),
        };
        if ($approvers === []) {
            throw new \InvalidArgumentException(match ($mode) {
                ProcessTopics::RECIPIENTS_USER => 'Vælg den medarbejder, dokumentet skal sendes til.',
                ProcessTopics::RECIPIENTS_RESPONSIBLE => 'Sagen har ingen ansvarlig sagsbehandler.',
                default => 'Sagen har ingen andre sagsbehandlere at sende dokumentet til.',
            });
        }
        foreach ($approvers as $uid) {
            if ($this->userManager->get($uid) === null) {
                throw new \InvalidArgumentException("Medarbejderen '{$uid}' findes ikke.");
            }
        }
        $days = (int)($params['days'] ?? 0);
        $deadline = $days > 0 ? (new \DateTime())->modify("+{$days} days") : null;

        return $this->workflowService->create($document->getId(), $type, $deadline, $approvers, $userId);
    }

    /**
     * Set the process's document to status "Endelig" and make its files
     * read-only, as when a user does it.
     *
     * @param array<string, string> $params
     * @return array{document_id: int, already_final: bool}
     */
    private function finalizeDocument(CaseEntity $case, string $userId, array $params, CaseProcess $process, string $xml, string $elementId): array {
        $this->requireWriteAccess($case, $userId);
        $document = $this->documentFromProcess($case, $process, $xml, $elementId, (int)($params['category'] ?? 0));
        $result = ['document_id' => $document->getId(), 'already_final' => !empty($this->documentStatusMapper->getIsFinalMap()[$document->getStatus()])];
        if (!$result['already_final']) {
            $this->documentService->update($document->getId(), null, null, self::FINAL_STATUS_ID, $userId);
            $this->fileService->updateFilecachePermissionsForDocument($document->getId(), false);
        }
        return $result;
    }

    /**
     * Make a user the case's responsible caseworker (as editing the case
     * does) or add them as a caseworker. Nothing changes if they already
     * have that role.
     *
     * @param array<string, string> $params
     * @return array{user: string, role: string, unchanged?: bool, previous?: ?string}
     */
    private function assignCase(CaseEntity $case, string $userId, array $params, CaseProcess $process): array {
        $this->requireWriteAccess($case, $userId);
        $fromField = ($params['source'] ?? ProcessTopics::SOURCE_FIXED) === ProcessTopics::SOURCE_FIELD;
        $field = trim($params['field'] ?? '');
        $assignee = $fromField ? (string)$this->fieldValue($process, $field) : trim($params['user'] ?? '');
        if ($assignee === '') {
            throw new \InvalidArgumentException($fromField
                ? "Feltet \"{$field}\" er ikke udfyldt på en tidligere opgave."
                : 'Vælg den medarbejder, sagen skal tildeles.');
        }
        if ($this->userManager->get($assignee) === null) {
            throw new \InvalidArgumentException("Medarbejderen '{$assignee}' findes ikke.");
        }

        $role = $params['role'] ?? ProcessTopics::ROLE_RESPONSIBLE;
        $result = ['user' => $assignee, 'role' => $role];
        if ($role === ProcessTopics::ROLE_RESPONSIBLE) {
            $previous = $case->getResponsibleUserId();
            if ($previous === $assignee) {
                return $result + ['unchanged' => true];
            }
            $this->caseService->update($case->getId(), null, null, null, null, $userId, $assignee);
            return $result + ['previous' => $previous];
        }
        if ($role !== ProcessTopics::ROLE_CASEWORKER) {
            throw new \InvalidArgumentException("Ukendt rolle: {$role}");
        }
        if ($case->getResponsibleUserId() === $assignee || $this->caseWorkerMapper->existsByCaseAndUser($case->getId(), $assignee)) {
            return $result + ['unchanged' => true];
        }
        $this->caseWorkerService->add($case->getId(), $assignee, $userId);
        return $result;
    }

    /**
     * Set one of the case's custom fields to a value given in the designer
     * or entered in a task's form field. An empty form field clears it.
     *
     * @param array<string, string> $params
     * @return array{field: string, value: string|null}
     */
    private function setCustomField(CaseEntity $case, string $userId, array $params, CaseProcess $process): array {
        $this->requireWriteAccess($case, $userId);
        $key = trim($params['custom_field'] ?? '');
        $def = $key !== '' ? $this->customFieldDefMapper->findByKey($key) : null;
        if ($def === null || !$def->getActive()) {
            throw new \InvalidArgumentException("Tillægsfeltet '{$key}' findes ikke eller er ikke aktivt.");
        }
        $applicable = $this->customFieldService->getApplicableFields(
            $case->getCasetypeId(),
            $case->getOrgUuid(),
            $this->caseService->getClassificationCodeForCase($case),
            $case->getClassPlanUuid(),
        );
        if (!in_array($def->getId(), array_map(fn($d) => $d->getId(), $applicable), true)) {
            throw new \InvalidArgumentException("Tillægsfeltet \"{$def->getLabel()}\" gælder ikke for sagen (sagstype, organisation, KLE-nummer eller emne).");
        }

        $fromField = ($params['source'] ?? ProcessTopics::SOURCE_FIXED) === ProcessTopics::SOURCE_FIELD;
        $value = trim($fromField ? (string)$this->fieldValue($process, trim($params['field'] ?? '')) : ($params['value'] ?? ''));
        $label = $def->getLabel();
        $stored = match (true) {
            $value === '' => null,
            $def->getFieldType() === 'checkbox' => match (strtolower($value)) {
                'true', '1', 'ja' => true,
                'false', '0', 'nej' => false,
                default => throw new \InvalidArgumentException("\"{$label}\" er et ja/nej-felt, men værdien er \"{$value}\"."),
            },
            $def->getFieldType() === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
                => throw new \InvalidArgumentException("\"{$label}\" er et datofelt, men værdien \"{$value}\" er ikke en dato (ÅÅÅÅ-MM-DD)."),
            $def->getFieldType() === 'dropdown' && !in_array($value, $def->getOptionsArray(), true)
                => throw new \InvalidArgumentException("\"{$value}\" er ikke en af valgmulighederne i \"{$label}\"."),
            mb_strlen($value) > 500 => throw new \InvalidArgumentException("Værdien til \"{$label}\" er længere end 500 tegn."),
            default => $value,
        };
        if ($stored === null && $def->getRequired()) {
            throw new \InvalidArgumentException("\"{$label}\" er påkrævet og kan ikke ryddes.");
        }
        $this->customFieldService->setValuesForCase($case->getId(), [$key => $stored], $userId);
        return ['field' => $label, 'value' => is_bool($stored) ? ($stored ? 'Ja' : 'Nej') : $stored];
    }

    /**
     * Add the case's primary party, or all its parties, with a CPR/CVR
     * number as "Modtager" on the process's document.
     *
     * @param array<string, string> $params
     * @return array{document_id: int, added: string[], skipped: string[]}
     */
    private function addRecipient(CaseEntity $case, string $userId, array $params, CaseProcess $process, string $xml, string $elementId): array {
        $this->requireWriteAccess($case, $userId);
        $document = $this->documentFromProcess($case, $process, $xml, $elementId, (int)($params['category'] ?? 0));
        $all = ($params['parties'] ?? ProcessTopics::PARTIES_PRIMARY) === ProcessTopics::PARTIES_ALL;

        $parties = array_filter(
            $this->caseParticipantMapper->findByCase($case->getId()),
            fn($p) => ($p->getCprCvr() ?? '') !== '' && ($all || $p->getParticipantroleId() === self::PRIMARY_PARTY_ROLE_ID),
        );
        if ($parties === []) {
            throw new \InvalidArgumentException($all
                ? 'Sagen har ingen parter med CPR-/CVR-nummer.'
                : 'Sagen har ingen primær part med CPR-/CVR-nummer.');
        }

        $receivers = [];
        foreach ($this->documentContactMapper->findByDocument($document->getId()) as $contact) {
            if ($contact->getContactroleId() === self::RECEIVER_ROLE_ID) {
                $receivers[$contact->getCprCvr() . '/' . $contact->getPnumber()] = true;
            }
        }
        $added = [];
        $skipped = [];
        foreach ($parties as $party) {
            $name = $party->getName() ?: (string)$party->getCprCvr();
            $key = $party->getCprCvr() . '/' . $party->getPnumber();
            if (isset($receivers[$key])) {
                $skipped[] = $name;
                continue;
            }
            $receivers[$key] = true;
            $this->documentContactService->add($document->getId(), [
                'contactrole_id' => self::RECEIVER_ROLE_ID,
                'cpr_cvr' => $party->getCprCvr(),
                'pnumber' => $party->getPnumber(),
                'user_id' => $party->getUserId(),
                'name' => $party->getName(),
                'streetname' => $party->getStreetname(),
                'housenumber' => $party->getHousenumber(),
                'floor' => $party->getFloor(),
                'door' => $party->getDoor(),
                'zipcode' => $party->getZipcode(),
                'zipdistrict' => $party->getZipdistrict(),
                'phone' => $party->getPhone(),
                'email' => $party->getEmail(),
                'has_address_protection' => $party->getHasAddressProtection(),
                'updated_date' => $party->getUpdatedDate()?->format('c'),
            ], $userId);
            $added[] = $name;
        }
        return ['document_id' => $document->getId(), 'added' => $added, 'skipped' => $skipped];
    }

    /**
     * Create a reminder on the case or the process's document, as the
     * reminder dialog does: the recipient is notified and gets write access
     * to the case if they lack it.
     *
     * @param array<string, string> $params
     * @return array{reminder_id: int, user: string, deadline: ?string}
     */
    private function createReminder(CaseEntity $case, string $userId, array $params, CaseProcess $process, string $xml, string $elementId): array {
        $this->requireWriteAccess($case, $userId);
        $title = trim(strtr($params['title'] ?? '', ['{sagsnummer}' => $case->getCaseNumber(), '{sagstitel}' => $case->getTitle()]));
        if ($title === '') {
            throw new \InvalidArgumentException('Påmindelsen mangler en titel.');
        }

        $mode = $params['recipient'] ?? ProcessTopics::RECIPIENTS_RESPONSIBLE;
        $recipient = match ($mode) {
            ProcessTopics::RECIPIENTS_RESPONSIBLE => (string)$case->getResponsibleUserId(),
            ProcessTopics::RECIPIENTS_USER => trim($params['user'] ?? ''),
            ProcessTopics::SOURCE_FIELD => (string)$this->fieldValue($process, trim($params['field'] ?? '')),
            default => throw new \InvalidArgumentException("Ukendt modtagervalg: {$mode}"),
        };
        if ($recipient === '') {
            throw new \InvalidArgumentException(match ($mode) {
                ProcessTopics::RECIPIENTS_RESPONSIBLE => 'Sagen har ingen ansvarlig sagsbehandler.',
                ProcessTopics::RECIPIENTS_USER => 'Vælg den medarbejder, der skal have påmindelsen.',
                default => "Feltet \"{$params['field']}\" er ikke udfyldt på en tidligere opgave.",
            });
        }
        if ($this->userManager->get($recipient) === null) {
            throw new \InvalidArgumentException("Medarbejderen '{$recipient}' findes ikke.");
        }

        $deadlineMode = $params['deadline'] ?? ProcessTopics::DEADLINE_DAYS;
        $deadline = null;
        if ($deadlineMode === ProcessTopics::DEADLINE_DAYS) {
            $days = (int)($params['days'] ?? 0);
            if ($days < 1) {
                throw new \InvalidArgumentException('Angiv fristen i dage (mindst 1).');
            }
            $deadline = (new \DateTime())->modify("+{$days} days");
        } elseif ($deadlineMode === ProcessTopics::DEADLINE_FIELD) {
            $value = (string)$this->fieldValue($process, trim($params['deadline_field'] ?? ''));
            if ($value !== '') {
                $deadline = \DateTime::createFromFormat('!Y-m-d', $value) ?: null;
                if ($deadline === null) {
                    throw new \InvalidArgumentException("Feltet \"{$params['deadline_field']}\" indeholder ikke en dato: \"{$value}\".");
                }
            }
        }

        $onDocument = ($params['attach'] ?? ProcessTopics::ATTACH_CASE) === ProcessTopics::ATTACH_DOCUMENT;
        $document = $onDocument ? $this->documentFromProcess($case, $process, $xml, $elementId, (int)($params['category'] ?? 0)) : null;

        $reminder = new Reminder();
        $reminder->setEntity($document !== null ? 'document' : 'case');
        $reminder->setEntityKey($document !== null ? $document->getId() : $case->getId());
        $reminder->setTitle($title);
        $reminder->setDeadline($deadline);
        $reminder->setStatus('active');
        $reminder->setResponsibleUserId($recipient);
        $reminder->setCreatedAt(new \DateTime());
        $reminder->setCreatedBy($userId);
        $reminder = $this->reminderMapper->insert($reminder);
        $this->auditService->logReminderCreated($case->getId(), $userId, $reminder->getId(), $title, $recipient);

        if ($recipient !== $userId) {
            if (!$this->permissionService->userHasWriteAccessToCase($recipient, $case->getId())) {
                $this->permissionService->grantUserAccess($case->getId(), $recipient, true, $userId);
            }
            $notification = $this->notificationManager->createNotification();
            $notification
                ->setApp('opencase')
                ->setUser($recipient)
                ->setDateTime(new \DateTime())
                ->setObject('reminder', (string)$reminder->getId())
                ->setSubject('reminder_assigned', [
                    'reminder_title' => $title,
                    'reminder_id' => $reminder->getId(),
                    'entity' => $reminder->getEntity(),
                    'entity_key' => $reminder->getEntityKey(),
                    'assigner' => $userId,
                    'deadline' => $deadline?->format('c'),
                ]);
            $this->notificationManager->notify($notification);
        }
        return ['reminder_id' => $reminder->getId(), 'user' => $recipient, 'deadline' => $deadline?->format('c'), 'document_id' => $document?->getId()];
    }

    /**
     * The value of a task form field (a process variable) as text: dates as
     * YYYY-MM-DD, yes/no as "true"/"false". null when it isn't filled in.
     */
    private function fieldValue(CaseProcess $process, string $field): ?string {
        if ($field === '') {
            throw new \InvalidArgumentException('Vælg feltet, værdien skal hentes fra.');
        }
        $variable = $this->flowable->getVariable($process->getFlowableInstanceId(), $field);
        if ($variable === null || $variable['value'] === null) {
            return null;
        }
        return match ($variable['type']) {
            // Form dates are stored as midnight UTC
            'date' => substr((string)$variable['value'], 0, 10),
            'boolean' => $variable['value'] ? 'true' : 'false',
            default => trim((string)$variable['value']),
        };
    }

    /**
     * When the process reached the step: the job's creation in the engine.
     */
    private function reachedAt(array $job, ProcessJob $log): \DateTime {
        return isset($job['created_at']) ? new \DateTime($job['created_at']) : ($log->getCreatedAt() ?? new \DateTime());
    }

    /**
     * Record that the step is waiting and hand the job back to the engine,
     * to be offered again after $seconds — with its retries unchanged, so
     * waiting never counts as failing.
     */
    private function handBack(ProcessJob $log, array $job, array $result, string $message, int $seconds): void {
        $log->setStatus(ProcessJob::STATUS_WAITING);
        $log->setResult(json_encode($result));
        $log->setError(null);
        $log->setFinishedAt(null);
        $this->jobMapper->update($log);
        // At least a minute, so a clock difference can't make it spin
        $seconds = max(60, $seconds);
        $this->flowable->failJob($job['id'], $this->workerId(), $message, $job['retries'], "PT{$seconds}S");
    }

    /**
     * Send a Nextcloud notification linking to the case. Only recipients
     * who can read the case get it (it shows the case number and title);
     * the others are recorded as skipped. No other caseworkers is not an
     * error: there is just nobody to notify.
     *
     * @param array<string, string> $params
     * @return array{recipients: string[], skipped: string[]}
     */
    private function sendNotification(CaseEntity $case, array $params, string $stepName): array {
        $placeholders = ['{sagsnummer}' => $case->getCaseNumber(), '{sagstitel}' => $case->getTitle()];
        $message = trim(strtr($params['message'] ?? '', $placeholders));
        if ($message === '') {
            throw new \InvalidArgumentException('Notifikationen mangler en besked.');
        }
        $title = trim(strtr($params['title'] ?? '', $placeholders)) ?: ($stepName !== '' ? $stepName : 'Besked fra processen');

        $mode = $params['recipients'] ?? ProcessTopics::RECIPIENTS_RESPONSIBLE;
        $userIds = match ($mode) {
            ProcessTopics::RECIPIENTS_RESPONSIBLE => array_filter([$case->getResponsibleUserId()]),
            ProcessTopics::RECIPIENTS_CASEWORKERS => array_values(array_filter(
                array_map(fn($w) => $w->getUserId(), $this->caseWorkerMapper->findByCase($case->getId())),
                fn(string $uid) => $uid !== $case->getResponsibleUserId(),
            )),
            ProcessTopics::RECIPIENTS_USER => array_filter([trim($params['user'] ?? '')]),
            default => throw new \InvalidArgumentException("Ukendt modtagervalg: {$mode}"),
        };
        if ($userIds === [] && $mode !== ProcessTopics::RECIPIENTS_CASEWORKERS) {
            throw new \InvalidArgumentException($mode === ProcessTopics::RECIPIENTS_USER
                ? 'Vælg den medarbejder, der skal have notifikationen.'
                : 'Sagen har ingen ansvarlig sagsbehandler.');
        }

        $sent = [];
        $skipped = [];
        foreach (array_unique($userIds) as $uid) {
            if ($this->userManager->get($uid) === null || !$this->permissionService->userHasReadAccessToCase($uid, $case->getId())) {
                $skipped[] = $uid;
                continue;
            }
            $notification = $this->notificationManager->createNotification();
            $notification
                ->setApp('opencase')
                ->setUser($uid)
                ->setDateTime(new \DateTime())
                ->setObject('case', (string)$case->getId())
                ->setSubject('process_notification', [
                    'title' => $title,
                    'message' => $message,
                    'case_id' => $case->getId(),
                    'case_number' => $case->getCaseNumber(),
                    'case_title' => $case->getTitle(),
                ]);
            $this->notificationManager->notify($notification);
            $sent[] = $uid;
        }
        if ($mode === ProcessTopics::RECIPIENTS_USER && $sent === []) {
            throw new \InvalidArgumentException("Medarbejderen '{$userIds[0]}' findes ikke eller har ikke adgang til sagen.");
        }
        return ['recipients' => $sent, 'skipped' => $skipped];
    }

    /**
     * Create a sub-case with the case's metadata (as the "Opret undersag"
     * dialog does), and start a process on it if one is chosen.
     *
     * The sub-case exists once created, so a process that can't be started
     * doesn't fail the step — running it again would create a second
     * sub-case. The problem is recorded in the step's result instead.
     *
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function createSubCase(CaseEntity $case, string $userId, array $params, CaseProcess $process): array {
        $this->requireWriteAccess($case, $userId);
        $title = trim(strtr($params['title'] ?? '', [
            '{sagsnummer}' => $case->getCaseNumber(),
            '{sagstitel}' => $case->getTitle(),
        ]));
        if ($title === '') {
            throw new \InvalidArgumentException('Undersagen mangler en titel.');
        }
        $processDefId = (int)($params['process'] ?? 0);
        if ($processDefId > 0 && $processDefId === $process->getProcessDefId()) {
            // Would create sub-cases without end
            throw new \InvalidArgumentException('En proces kan ikke starte sig selv på en undersag.');
        }

        // The sub-case gets the parent case's access: its KLE number and
        // sensitivity (FK Adgang) or its access group (Grupper, with the
        // case's own KLE number and sensitivity when it has them).
        $org = $this->organisationMapper->findByUuid($case->getOrgUuid());
        $profile = $this->accessProfileMapper->findById($case->getAccessProfileId());
        $classSubjectUuid = $case->effectiveClassSubjectUuid($profile);
        $sensitivityUuid = $case->effectiveSensitivityUuid($profile);
        $groupId = $profile?->getGroupId();
        $access = null;
        if ($profile?->getProfileType() === AccessProfile::TYPE_GROUP && $groupId !== null) {
            $access = array_filter([
                'group_id'           => $groupId,
                'class_subject_uuid' => $classSubjectUuid,
                'sensitivity_uuid'   => $sensitivityUuid,
            ], fn($v) => $v !== null);
        } elseif ($profile?->getProfileType() === AccessProfile::TYPE_FK && $classSubjectUuid !== null && $sensitivityUuid !== null) {
            $access = ['class_subject_uuid' => $classSubjectUuid, 'sensitivity_uuid' => $sensitivityUuid];
        }
        if ($org === null || $access === null) {
            throw new \InvalidArgumentException('Sagens organisation eller adgangsprofil kunne ikke findes.');
        }
        $casetypeId = (int)($params['casetype'] ?? 0);

        $subCase = $this->caseService->create(
            $title,
            $org->getOrgName(),
            $access,
            $userId,
            $case->getResponsibleUserId() ?: $userId,
            $case->getInsightLevelId(),
            $case->getClassificationFacetUuid(),
            $case->getId(),
            $casetypeId > 0 ? $casetypeId : $case->getCasetypeId(),
            null,
            $case->getClassPlanUuid(),
        );
        $result = ['case_id' => $subCase->getId(), 'case_number' => $subCase->getCaseNumber()];

        if ($processDefId > 0) {
            try {
                // Resolved at call time: CaseProcessService depends on this worker
                \OCP\Server::get(CaseProcessService::class)->start($subCase->getId(), $processDefId, $userId);
                $result['process_started'] = true;
            } catch (\Throwable $e) {
                $this->logger->warning("Sub-case {$subCase->getId()} was created, but its process could not be started: " . $e->getMessage());
                $result['process_error'] = $e->getMessage();
            }
        }
        return $result;
    }

    /**
     * Send a document with Digital Post: the document created by the
     * process's latest "Opret dokument" step, else the newest document on
     * the case in the chosen category; to the document's receivers, else the
     * case's primary party. Receivers without Digital Post get physical post
     * (DispatchDigitalPostJob sends the shipment). A document already sent
     * isn't sent again; the step just moves on.
     *
     * @param array<string, string> $params
     * @return array<string, int|string>
     */
    private function sendDigitalPost(CaseEntity $case, string $userId, array $params, CaseProcess $process, string $xml, string $elementId): array {
        if (!$this->digitalPost->canCheckSubscriptions()) {
            throw new \InvalidArgumentException('Digital Post er ikke aktiveret på denne instans (feature_digital-post).');
        }
        $this->requireWriteAccess($case, $userId);

        $document = $this->documentFromProcess($case, $process, $xml, $elementId, (int)($params['category'] ?? 0));

        // A document is sent once, so a loop back to this step moves on
        $sent = $this->digitalPost->latest($document->getId());
        if ($sent !== null) {
            return [
                'shipment_id' => $sent['shipment']->getId(),
                'document_id' => $document->getId(),
                'receivers' => count($sent['receivers']),
                'already_sent' => 1,
                'status' => $sent['shipment']->getStatus(),
            ];
        }

        $files = $this->fileMapper->findByDocument($document->getId());
        if ($files === []) {
            throw new \InvalidArgumentException("Dokumentet \"{$document->getTitle()}\" har ingen filer at sende.");
        }

        $receivers = [];
        foreach ($this->documentContactMapper->findByDocument($document->getId()) as $contact) {
            if ($contact->getContactroleId() === self::RECEIVER_ROLE_ID && ($contact->getCprCvr() ?? '') !== '') {
                $receivers[] = ['cpr_cvr' => $contact->getCprCvr(), 'receiver_name' => $contact->getName(), 'document_contact_id' => $contact->getId()];
            }
        }
        if ($receivers === []) {
            foreach ($this->caseParticipantMapper->findByCase($case->getId()) as $participant) {
                if ($participant->getParticipantroleId() === self::PRIMARY_PARTY_ROLE_ID && ($participant->getCprCvr() ?? '') !== '') {
                    $receivers[] = ['cpr_cvr' => $participant->getCprCvr(), 'receiver_name' => $participant->getName(), 'document_contact_id' => null];
                }
            }
        }
        if ($receivers === []) {
            throw new \InvalidArgumentException('Dokumentet har ingen modtagere med CPR-/CVR-nummer, og sagen har ingen primær part med CPR-/CVR-nummer.');
        }

        // Digital Post or physical post, like the dialog's lookup. A failing
        // lookup is retried by the engine (RuntimeException).
        foreach ($receivers as &$receiver) {
            $receiver['dp_subscription'] = $this->digitalPost->hasSubscription((string)$receiver['cpr_cvr']);
        }
        unset($receiver);

        $shipment = $this->digitalPost->createShipment(
            $document->getId(),
            $userId,
            ($params['can_be_replied'] ?? 'true') !== 'false',
            ($params['include_attachments'] ?? 'true') !== 'false',
            $files[0]->getId(),
            $receivers,
        );
        return ['shipment_id' => $shipment->getId(), 'document_id' => $document->getId(), 'receivers' => count($receivers)];
    }

    /**
     * The document a step works on: the one of the nearest "Opret dokument"
     * or "Vent på dokument" step before it in the same branch, on the case
     * the step works on; else the newest document on the case in the
     * chosen category.
     */
    private function documentFromProcess(CaseEntity $case, CaseProcess $process, string $xml, string $elementId, int $categoryId): Document {
        $done = $this->doneStepResults($process);
        foreach (BpmnXml::precedingServiceTasks($xml, $elementId, ProcessTopics::DOCUMENT_TOPICS) as $documentStep) {
            $documentId = (int)($done[$documentStep]['document_id'] ?? 0);
            $document = $documentId > 0 ? $this->documentMapper->findById($documentId) : null;
            if ($document !== null && !$document->isSoftDeleted() && $document->getCaseId() === $case->getId()) {
                return $document;
            }
        }
        if ($categoryId > 0) {
            // findByCase() returns the newest first
            foreach ($this->documentMapper->findByCase($case->getId()) as $document) {
                if ($document->getDocumentCategoryId() === $categoryId) {
                    return $document;
                }
            }
        }
        throw new \InvalidArgumentException($categoryId > 0
            ? 'Processen har ikke et dokument fra et tidligere trin, og sagen har intet dokument i den valgte kategori.'
            : 'Processen har ikke et dokument fra et tidligere trin. Vælg evt. en dokumentkategori på trinnet.');
    }

    /**
     * Report the step done first and close the case after, so the process
     * ends as completed instead of being cancelled by the closing.
     */
    private function closeCase(CaseEntity $case, string $userId, array $job, ProcessJob $log): void {
        $this->requireWriteAccess($case, $userId);
        $alreadyClosed = $this->caseStatusMapper->isClosed($case->getStatusId());

        $this->markDone($log, ['already_closed' => $alreadyClosed]);
        $this->reportDone($job);
        $this->auditService->logProcessStepExecuted($case->getId(), $userId, $log->getCaseProcessId(), (string)($job['element_name'] ?? $job['element_id']), ProcessTopics::CLOSE_CASE);

        if (!$alreadyClosed) {
            try {
                $this->caseService->changeStatus($case->getId(), 2, $userId);
            } catch (\Throwable $e) {
                // The process has moved on; record that the case wasn't closed
                $log->setStatus(ProcessJob::STATUS_FAILED);
                $log->setError('Sagen kunne ikke lukkes: ' . $e->getMessage());
                $this->jobMapper->update($log);
            }
        }
    }

    private function requireWriteAccess(CaseEntity $case, string $userId): void {
        if ($userId === '' || !$this->permissionService->userHasWriteAccessToCase($userId, $case->getId())) {
            throw new \InvalidArgumentException("Brugeren '{$userId}' har ikke skriveadgang til sagen.");
        }
    }

    /**
     * @param array{id: string, element_id: string, element_name: ?string} $job
     */
    private function newLog(CaseProcess $process, string $topic, array $job): ?ProcessJob {
        $log = new ProcessJob();
        $log->setFlowableJobId($job['id']);
        $log->setCaseProcessId($process->getId());
        $log->setCaseId($process->getCaseId());
        $log->setElementId($job['element_id']);
        $log->setElementName($job['element_name']);
        $log->setTopic($topic);
        $log->setStatus(ProcessJob::STATUS_RUNNING);
        $log->setCreatedAt(new \DateTime());
        try {
            return $this->jobMapper->insert($log);
        } catch (DBException $e) {
            if ($e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Tell the engine a step is done. The action has been performed and the
     * step is recorded as done, so a failure here must not mark the step
     * failed: the engine hands the job out again when its lock expires, and
     * runJob() then only reports it done instead of repeating the action.
     */
    private function reportDone(array $job, array $variables = []): void {
        try {
            $this->flowable->completeJob($job['id'], $this->workerId(), $variables);
        } catch (\Throwable $e) {
            $this->logger->warning("Process step {$job['element_id']} was done, but the engine could not be told yet: " . $e->getMessage());
        }
    }

    private function markDone(ProcessJob $log, array $result): void {
        $log->setStatus(ProcessJob::STATUS_DONE);
        $log->setResult(json_encode($result));
        $log->setError(null);
        $log->setFinishedAt(new \DateTime());
        $this->jobMapper->update($log);
    }

    /**
     * Configuration and access errors won't go away by retrying: fail the
     * step at once so it shows on the case. Anything else is retried.
     */
    private function fail(ProcessJob $log, array $job, \Throwable $e): void {
        $permanent = $e instanceof \InvalidArgumentException || $e instanceof NotFoundException;
        $retries = $permanent ? 0 : $job['retries'] - 1;
        $this->logger->warning("Automatic process step {$job['element_id']} failed: " . $e->getMessage(), ['exception' => $e]);

        $log->setStatus(ProcessJob::STATUS_FAILED);
        $log->setError($e->getMessage());
        $log->setFinishedAt(new \DateTime());
        try {
            $this->jobMapper->update($log);
            $this->flowable->failJob($job['id'], $this->workerId(), $e->getMessage(), $retries, self::RETRY_TIMEOUT);
        } catch (\Throwable $inner) {
            $this->logger->error('Could not report failed process step: ' . $inner->getMessage());
        }
    }

    private function workerId(): string {
        return 'opencase-' . $this->config->getSystemValueString('instanceid', 'nextcloud');
    }
}
