<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\DocWorkflowEntity;
use OCA\OpenCase\Db\DocWorkflowMapper;
use OCA\OpenCase\Db\DocWorkflowStepEntity;
use OCA\OpenCase\Db\DocWorkflowStepMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;

class WorkflowService {

    public function __construct(
        private DocWorkflowMapper $workflowMapper,
        private DocWorkflowStepMapper $stepMapper,
        private DocumentMapper $documentMapper,
        private FileShareService $fileShareService,
        private AuditService $auditService,
        private IUserManager $userManager,
        private INotificationManager $notificationManager,
    ) {
    }

    /**
     * Create a new workflow on a document.
     *
     * Step deadlines are distributed as equal fractions of the time from now
     * to the workflow deadline:
     *   step_i deadline = now + (i / n) * total_seconds   (i = 1..n)
     *
     * @param string[] $userIds  Ordered list of user IDs
     * @throws \InvalidArgumentException on validation failure
     * @throws \RuntimeException if document not found or an active workflow already exists
     */
    public function create(
        int $documentId,
        string $type,
        ?\DateTime $deadline,
        array $userIds,
        string $createdBy,
    ): DocWorkflowEntity {
        if (!in_array($type, ['review', 'approval'], true)) {
            throw new \InvalidArgumentException("Invalid workflow type '{$type}'");
        }

        if (empty($userIds)) {
            throw new \InvalidArgumentException('At least one user is required');
        }

        foreach ($userIds as $uid) {
            if ($this->userManager->get($uid) === null) {
                throw new \InvalidArgumentException("User '{$uid}' does not exist");
            }
        }

        $document = $this->documentMapper->findById($documentId);
        if ($document === null) {
            throw new \RuntimeException("Document {$documentId} not found");
        }

        if ($this->workflowMapper->findActiveByDocument($documentId) !== null) {
            throw new \RuntimeException('An active workflow already exists for this document');
        }

        $now = new \DateTime();

        $workflow = new DocWorkflowEntity();
        $workflow->setDocumentId($documentId);
        $workflow->setType($type);
        $workflow->setStatus('active');
        $workflow->setDeadline($deadline);
        $workflow->setCreatedBy($createdBy);
        $workflow->setCreatedAt($now);
        $workflow->setUpdatedAt($now);
        $workflow = $this->workflowMapper->insert($workflow);

        $stepDeadlines = $this->calculateStepDeadlines($now, $deadline, count($userIds));

        foreach (array_values($userIds) as $i => $uid) {
            $step = new DocWorkflowStepEntity();
            $step->setWorkflowId($workflow->getId());
            $step->setUserId($uid);
            $step->setSortOrder($i + 1);
            $step->setStatus('pending');
            $step->setDeadline($stepDeadlines[$i] ?? null);
            $this->stepMapper->insert($step);
        }

        $this->auditService->logWorkflowCreated(
            $document->getCaseId(),
            $documentId,
            $workflow->getId(),
            $type,
            $deadline,
            $userIds,
            $createdBy,
        );

        // Grant read-only access to the document for every workflow participant.
        // Fire-and-forget: skip silently if the document has no files yet, if a
        // user is the same as the creator, or any other edge case.
        foreach ($userIds as $uid) {
            if ($uid === $createdBy) {
                continue;
            }
            try {
                $this->fileShareService->createDocumentShare(
                    $documentId,
                    $uid,
                    false,          // read-only
                    $createdBy,
                    $deadline,      // share expires when the workflow deadline passes
                );
            } catch (\Throwable) {
                // best-effort — never abort workflow creation for a sharing failure
            }
        }

        $this->notifyStepUser($userIds[0], $workflow, $document->getTitle());

        return $workflow;
    }

    /**
     * Submit a review/approval action on the current active step.
     *
     * @throws \InvalidArgumentException on validation failure
     * @throws \RuntimeException if not found or not the user's turn
     */
    public function submitAction(
        int $workflowId,
        string $userId,
        string $action,
        ?string $comment,
    ): DocWorkflowStepEntity {
        $workflow = $this->workflowMapper->findById($workflowId);
        if ($workflow === null || !$workflow->isActive()) {
            throw new \RuntimeException('Workflow not found or not active');
        }

        $allowedActions = $workflow->getType() === 'review'
            ? ['reviewed']
            : ['approved', 'rejected'];

        if (!in_array($action, $allowedActions, true)) {
            throw new \InvalidArgumentException("Action '{$action}' is not valid for a {$workflow->getType()} workflow");
        }

        $step = $this->stepMapper->findCurrentPendingStep($workflowId);
        if ($step === null) {
            throw new \RuntimeException('No pending step found');
        }

        if ($step->getUserId() !== $userId) {
            throw new \RuntimeException('It is not your turn to act on this workflow');
        }

        $now = new \DateTime();
        $step->setStatus($action);
        $step->setComment($comment ?: null);
        $step->setActedAt($now);
        $this->stepMapper->update($step);

        $document = $this->documentMapper->findById($workflow->getDocumentId());

        $this->auditService->logWorkflowStepActed(
            $document?->getCaseId() ?? 0,
            $workflow->getDocumentId(),
            $workflowId,
            $step->getId(),
            $userId,
            $action,
            $comment,
        );

        if ($action === 'rejected') {
            $workflow->setStatus('rejected');
            $workflow->setUpdatedAt($now);
            $this->workflowMapper->update($workflow);

            $this->auditService->logWorkflowRejected(
                $document?->getCaseId() ?? 0,
                $workflow->getDocumentId(),
                $workflowId,
                $userId,
                $comment,
            );
            $this->notifyCreator($workflow->getCreatedBy(), 'workflow_rejected', $workflow, $document?->getTitle() ?? '');
            return $step;
        }

        $nextStep = $this->stepMapper->findCurrentPendingStep($workflowId);
        if ($nextStep !== null) {
            $this->notifyStepUser($nextStep->getUserId(), $workflow, $document?->getTitle() ?? '');
        } else {
            $workflow->setStatus('completed');
            $workflow->setUpdatedAt($now);
            $this->workflowMapper->update($workflow);

            $this->auditService->logWorkflowCompleted(
                $document?->getCaseId() ?? 0,
                $workflow->getDocumentId(),
                $workflowId,
            );
            $this->notifyCreator($workflow->getCreatedBy(), 'workflow_completed', $workflow, $document?->getTitle() ?? '');
        }

        return $step;
    }

    /**
     * Cancel an active workflow.
     *
     * @throws \RuntimeException if not found or already concluded
     */
    public function cancel(int $workflowId, string $userId): void {
        $workflow = $this->workflowMapper->findById($workflowId);
        if ($workflow === null || !$workflow->isActive()) {
            throw new \RuntimeException('Workflow not found or not active');
        }

        $now = new \DateTime();
        $workflow->setStatus('cancelled');
        $workflow->setUpdatedAt($now);
        $this->workflowMapper->update($workflow);

        $document = $this->documentMapper->findById($workflow->getDocumentId());
        $this->auditService->logWorkflowCancelled(
            $document?->getCaseId() ?? 0,
            $workflow->getDocumentId(),
            $workflowId,
            $userId,
        );
    }

    public function getActiveForDocument(int $documentId): ?DocWorkflowEntity {
        return $this->workflowMapper->findActiveByDocument($documentId);
    }

    /**
     * The document a workflow belongs to, so callers can check the caller's
     * access to it before acting on the workflow.
     */
    public function getDocumentIdForWorkflow(int $workflowId): ?int {
        return $this->workflowMapper->findById($workflowId)?->getDocumentId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    /**
     * Return tasks where it is currently the given user's turn to act.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMyPendingTasks(string $userId): array {
        $rows = $this->stepMapper->findPendingByUser($userId);

        $tasks = [];
        foreach ($rows as $row) {
            $workflowId = (int)$row['workflow_id'];
            $currentStep = $this->stepMapper->findCurrentPendingStep($workflowId);
            if ($currentStep !== null && $currentStep->getUserId() === $userId) {
                $tasks[] = [
                    'workflow_id'    => $workflowId,
                    'workflow_type'  => $row['workflow_type'],
                    'document_id'    => (int)$row['document_id'],
                    'document_title' => $row['document_title'],
                    'step_deadline'  => isset($row['step_deadline']) ? (string)$row['step_deadline'] : null,
                ];
            }
        }
        return $tasks;
    }

    public function getHistoryForDocument(int $documentId): array {
        $workflows = $this->workflowMapper->findByDocument($documentId);
        return array_map(function (DocWorkflowEntity $wf): array {
            $steps = $this->stepMapper->findByWorkflow($wf->getId());
            return $this->workflowToArray($wf, $steps);
        }, $workflows);
    }

    /**
     * @return DocWorkflowStepEntity[]
     */
    public function getSteps(int $workflowId): array {
        return $this->stepMapper->findByWorkflow($workflowId);
    }

    /**
     * @return array<string, mixed>
     */
    public function workflowToArray(DocWorkflowEntity $wf, array $steps): array {
        return [
            'id'          => $wf->getId(),
            'document_id' => $wf->getDocumentId(),
            'type'        => $wf->getType(),
            'status'      => $wf->getStatus(),
            'deadline'    => $wf->getDeadline()?->format('c'),
            'created_by'  => $wf->getCreatedBy(),
            'created_at'  => $wf->getCreatedAt()?->format('c'),
            'steps'       => array_map([$this, 'stepToArray'], $steps),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function stepToArray(DocWorkflowStepEntity $step): array {
        $user = $this->userManager->get($step->getUserId());
        return [
            'id'           => $step->getId(),
            'workflow_id'  => $step->getWorkflowId(),
            'user_id'      => $step->getUserId(),
            'display_name' => $user?->getDisplayName() ?? $step->getUserId(),
            'sort_order'   => $step->getSortOrder(),
            'status'       => $step->getStatus(),
            'comment'      => $step->getComment(),
            'deadline'     => $step->getDeadline()?->format('c'),
            'acted_at'     => $step->getActedAt()?->format('c'),
        ];
    }

    /**
     * Calculate per-step deadlines as equal intervals from $now to $workflowDeadline.
     * Step i (1-indexed) gets: now + (i/n) * total_seconds
     * This guarantees the last step deadline equals the workflow deadline exactly.
     *
     * @return array<int, \DateTime|null>
     */
    private function calculateStepDeadlines(\DateTime $now, ?\DateTime $workflowDeadline, int $count): array {
        if ($workflowDeadline === null || $count === 0) {
            return array_fill(0, $count, null);
        }

        $nowTs   = $now->getTimestamp();
        $totalSeconds = $workflowDeadline->getTimestamp() - $nowTs;

        $deadlines = [];
        for ($i = 1; $i <= $count; $i++) {
            $offset = (int) round($totalSeconds * $i / $count);
            $deadlines[] = (new \DateTime())->setTimestamp($nowTs + $offset);
        }

        return $deadlines;
    }

    private function notifyStepUser(string $userId, DocWorkflowEntity $workflow, string $documentTitle): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification
                ->setApp('opencase')
                ->setUser($userId)
                ->setDateTime(new \DateTime())
                ->setObject('workflow', (string)$workflow->getId())
                ->setSubject('workflow_action_required', [
                    'document_id'    => $workflow->getDocumentId(),
                    'document_title' => $documentTitle,
                    'workflow_type'  => $workflow->getType(),
                ]);
            $this->notificationManager->notify($notification);
        } catch (\InvalidArgumentException) {
            // best-effort
        }
    }

    private function notifyCreator(string $createdBy, string $subject, DocWorkflowEntity $workflow, string $documentTitle): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification
                ->setApp('opencase')
                ->setUser($createdBy)
                ->setDateTime(new \DateTime())
                ->setObject('workflow', (string)$workflow->getId())
                ->setSubject($subject, [
                    'document_id'    => $workflow->getDocumentId(),
                    'document_title' => $documentTitle,
                    'workflow_type'  => $workflow->getType(),
                ]);
            $this->notificationManager->notify($notification);
        } catch (\InvalidArgumentException) {
            // best-effort
        }
    }
}
