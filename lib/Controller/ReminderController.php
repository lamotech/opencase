<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\Reminder;
use OCA\OpenCase\Db\ReminderMapper;
use OCA\OpenCase\Service\AuditService;
use OCA\OpenCase\Service\PermissionService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;

/**
 * REST API for reminders.
 *
 * Routes:
 *   GET    /api/v1/cases/{caseId}/reminders         — list reminders for a case
 *   POST   /api/v1/cases/{caseId}/reminders         — create a reminder on a case
 *   PUT    /api/v1/reminders/{id}                   — update status / responsible user
 *   DELETE /api/v1/reminders/{id}                   — delete a reminder
 */
class ReminderController extends ApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private ReminderMapper $reminderMapper,
        private CaseMapper $caseMapper,
        private DocumentMapper $documentMapper,
        private IUserManager $userManager,
        private INotificationManager $notificationManager,
        private AuditService $auditService,
        private PermissionService $permissionService,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    private function serialize(Reminder $r): array {
        $responsibleUser = null;
        if ($r->getResponsibleUserId() !== null) {
            $u = $this->userManager->get($r->getResponsibleUserId());
            $responsibleUser = [
                'uid'          => $r->getResponsibleUserId(),
                'display_name' => $u?->getDisplayName() ?? $r->getResponsibleUserId(),
            ];
        }

        return [
            'id'                   => $r->getId(),
            'entity'               => $r->getEntity(),
            'entity_key'           => $r->getEntityKey(),
            'title'                => $r->getTitle(),
            'deadline'             => $r->getDeadline()?->format('c'),
            'status'               => $r->getStatus(),
            'responsible_user_id'  => $r->getResponsibleUserId(),
            'responsible_user'     => $responsibleUser,
            'created_at'           => $r->getCreatedAt()?->format('c'),
            'created_by'           => $r->getCreatedBy(),
        ];
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/me/reminders')]
    public function myReminders(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['reminders' => []]);
        }

        $reminders = array_map(
            fn($r) => $this->serialize($r),
            $this->reminderMapper->findActiveByUser($this->userId),
        );

        return new DataResponse(['reminders' => $reminders]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/cases/{caseId}/reminders')]
    public function indexByCase(int $caseId): DataResponse {
        if ($this->caseMapper->findById($caseId) === null) {
            return new DataResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
        }

        $reminders = array_map(
            fn($r) => $this->serialize($r),
            $this->reminderMapper->findByEntity('case', $caseId),
        );

        return new DataResponse(['reminders' => $reminders]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/cases/{caseId}/reminders')]
    public function createForCase(int $caseId): DataResponse {
        if ($this->caseMapper->findById($caseId) === null) {
            return new DataResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
        }

        $params = $this->request->getParams();
        $title  = trim($params['title'] ?? '');

        if ($title === '') {
            return new DataResponse(['error' => "Field 'title' is required"], Http::STATUS_BAD_REQUEST);
        }

        $responsibleUserId = ($params['responsible_user_id'] ?? '') ?: ($this->userId ?? '');
        $deadlineRaw       = $params['deadline'] ?? null;
        $deadline          = null;
        if ($deadlineRaw !== null && $deadlineRaw !== '') {
            $deadline = new \DateTime($deadlineRaw);
        }

        $reminder = new Reminder();
        $reminder->setEntity('case');
        $reminder->setEntityKey($caseId);
        $reminder->setTitle($title);
        $reminder->setDeadline($deadline);
        $reminder->setStatus('active');
        $reminder->setResponsibleUserId($responsibleUserId ?: null);
        $reminder->setCreatedAt(new \DateTime());
        $reminder->setCreatedBy($this->userId ?? '');

        $reminder = $this->reminderMapper->insert($reminder);

        $this->auditService->logReminderCreated($caseId, $this->userId ?? '', $reminder->getId(), $title, $reminder->getResponsibleUserId());

        if ($responsibleUserId !== '' && $responsibleUserId !== $this->userId) {
            $this->sendReminderNotification($reminder);
            $this->ensureWriteAccess($caseId, $responsibleUserId);
        }

        return new DataResponse(['reminder' => $this->serialize($reminder)], Http::STATUS_CREATED);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/documents/{documentId}/reminders')]
    public function indexByDocument(int $documentId): DataResponse {
        if ($this->documentMapper->findById($documentId) === null) {
            return new DataResponse(['error' => 'Document not found'], Http::STATUS_NOT_FOUND);
        }

        $reminders = array_map(
            fn($r) => $this->serialize($r),
            $this->reminderMapper->findByEntity('document', $documentId),
        );

        return new DataResponse(['reminders' => $reminders]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/documents/{documentId}/reminders')]
    public function createForDocument(int $documentId): DataResponse {
        $document = $this->documentMapper->findById($documentId);
        if ($document === null) {
            return new DataResponse(['error' => 'Document not found'], Http::STATUS_NOT_FOUND);
        }

        $params = $this->request->getParams();
        $title  = trim($params['title'] ?? '');

        if ($title === '') {
            return new DataResponse(['error' => "Field 'title' is required"], Http::STATUS_BAD_REQUEST);
        }

        $responsibleUserId = ($params['responsible_user_id'] ?? '') ?: ($this->userId ?? '');
        $deadlineRaw       = $params['deadline'] ?? null;
        $deadline          = null;
        if ($deadlineRaw !== null && $deadlineRaw !== '') {
            $deadline = new \DateTime($deadlineRaw);
        }

        $reminder = new Reminder();
        $reminder->setEntity('document');
        $reminder->setEntityKey($documentId);
        $reminder->setTitle($title);
        $reminder->setDeadline($deadline);
        $reminder->setStatus('active');
        $reminder->setResponsibleUserId($responsibleUserId ?: null);
        $reminder->setCreatedAt(new \DateTime());
        $reminder->setCreatedBy($this->userId ?? '');

        $reminder = $this->reminderMapper->insert($reminder);

        $caseId = $document->getCaseId();
        $this->auditService->logReminderCreated($caseId, $this->userId ?? '', $reminder->getId(), $title, $reminder->getResponsibleUserId());

        if ($responsibleUserId !== '' && $responsibleUserId !== $this->userId) {
            $this->sendReminderNotification($reminder);
            $this->ensureWriteAccess($caseId, $responsibleUserId);
        }

        return new DataResponse(['reminder' => $this->serialize($reminder)], Http::STATUS_CREATED);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/reminders/{id}')]
    public function update(int $id): DataResponse {
        $reminder = $this->reminderMapper->findById($id);
        if ($reminder === null) {
            return new DataResponse(['error' => 'Reminder not found'], Http::STATUS_NOT_FOUND);
        }

        $params = $this->request->getParams();

        if (isset($params['status'])) {
            $reminder->setStatus($params['status']);
        }

        $prevResponsible = $reminder->getResponsibleUserId();
        if (array_key_exists('responsible_user_id', $params)) {
            $newResponsible = ($params['responsible_user_id'] ?? '') ?: null;
            $reminder->setResponsibleUserId($newResponsible);
        }

        if (isset($params['title'])) {
            $title = trim($params['title']);
            if ($title !== '') {
                $reminder->setTitle($title);
            }
        }

        if (isset($params['deadline'])) {
            $deadlineRaw = $params['deadline'];
            $reminder->setDeadline($deadlineRaw !== '' ? new \DateTime($deadlineRaw) : null);
        }

        $changes = array_filter([
            'status'               => isset($params['status']) ? $params['status'] : null,
            'responsible_user_id'  => array_key_exists('responsible_user_id', $params) ? ($params['responsible_user_id'] ?? null) : null,
        ], fn($v) => $v !== null);

        $reminder = $this->reminderMapper->update($reminder);

        $caseId = $reminder->getEntity() === 'case' ? $reminder->getEntityKey() : 0;
        $this->auditService->logReminderUpdated($caseId, $this->userId ?? '', $reminder->getId(), $reminder->getTitle(), $changes);

        $newResponsible = $reminder->getResponsibleUserId();
        if (
            $newResponsible !== null
            && $newResponsible !== $prevResponsible
            && $newResponsible !== $this->userId
        ) {
            $this->sendReminderNotification($reminder);
            $caseIdForGrant = match ($reminder->getEntity()) {
                'case'     => $reminder->getEntityKey(),
                'document' => $this->documentMapper->findById($reminder->getEntityKey())?->getCaseId() ?? 0,
                default    => 0,
            };
            if ($caseIdForGrant > 0) {
                $this->ensureWriteAccess($caseIdForGrant, $newResponsible);
            }
        }

        return new DataResponse(['reminder' => $this->serialize($reminder)]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/reminders/{id}')]
    public function destroy(int $id): DataResponse {
        $reminder = $this->reminderMapper->findById($id);
        if ($reminder === null) {
            return new DataResponse(['error' => 'Reminder not found'], Http::STATUS_NOT_FOUND);
        }

        $caseId = $reminder->getEntity() === 'case' ? $reminder->getEntityKey() : 0;
        $this->auditService->logReminderDeleted($caseId, $this->userId ?? '', $id, $reminder->getTitle());

        $this->reminderMapper->delete($reminder);

        return new DataResponse([], Http::STATUS_NO_CONTENT);
    }

    private function ensureWriteAccess(int $caseId, string $userId): void {
        if (!$this->permissionService->userHasWriteAccessToCase($userId, $caseId)) {
            $this->permissionService->grantUserAccess($caseId, $userId, true, $this->userId ?? '');
        }
    }

    private function sendReminderNotification(Reminder $reminder): void {
        $recipient = $reminder->getResponsibleUserId();
        if ($recipient === null || $recipient === '') {
            return;
        }

        try {
            $notification = $this->notificationManager->createNotification();
            $notification
                ->setApp('opencase')
                ->setUser($recipient)
                ->setDateTime(new \DateTime())
                ->setObject('reminder', (string)$reminder->getId())
                ->setSubject('reminder_assigned', [
                    'reminder_title' => $reminder->getTitle(),
                    'reminder_id'    => $reminder->getId(),
                    'entity'         => $reminder->getEntity(),
                    'entity_key'     => $reminder->getEntityKey(),
                    'assigner'       => $this->userId ?? '',
                    'deadline'       => $reminder->getDeadline()?->format('c'),
                ]);
            $this->notificationManager->notify($notification);
        } catch (\InvalidArgumentException) {
            // notification is best-effort
        }
    }
}
