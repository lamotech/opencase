<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\JournalNote;
use OCA\OpenCase\Db\JournalNoteMapper;
use OCA\OpenCase\Service\AuditService;
use OCA\OpenCase\Service\PermissionService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * REST API controller for case journal notes.
 *
 * Routes:
 *   GET    /api/v1/cases/{caseId}/journal-notes      — List journal notes for a case
 *   POST   /api/v1/cases/{caseId}/journal-notes      — Create a journal note
 *   PUT    /api/v1/journal-notes/{id}                — Update a journal note
 *   DELETE /api/v1/journal-notes/{id}                — Delete a journal note
 *   POST   /api/v1/journal-notes/{id}/lock           — Lock a journal note
 */
class JournalNoteController extends ApiController {

    use CaseAccessGuard;

    public function __construct(
        string $appName,
        IRequest $request,
        private JournalNoteMapper $noteMapper,
        private CaseMapper $caseMapper,
        private AuditService $auditService,
        private PermissionService $permissionService,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    private function serialize(JournalNote $note): array {
        return [
            'id'         => $note->getId(),
            'case_id'    => $note->getCaseId(),
            'title'      => $note->getTitle(),
            'text'       => $note->getText(),
            'created_at' => $note->getCreatedAt()?->format('c'),
            'created_by' => $note->getCreatedBy(),
            'updated_at' => $note->getUpdatedAt()?->format('c'),
            'is_locked'  => (bool)$note->getIsLocked(),
        ];
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/cases/{caseId}/journal-notes')]
    public function index(int $caseId): DataResponse {
        if ($this->caseMapper->findById($caseId) === null) {
            return new DataResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
        }

        if ($denied = $this->denyUnlessCaseRead($caseId)) {
            return $denied;
        }

        $notes = array_map(fn($n) => $this->serialize($n), $this->noteMapper->findByCase($caseId));

        return new DataResponse(['journal_notes' => $notes]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/cases/{caseId}/journal-notes')]
    public function create(int $caseId): DataResponse {
        if ($this->caseMapper->findById($caseId) === null) {
            return new DataResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
        }

        if ($denied = $this->denyUnlessCaseWrite($caseId)) {
            return $denied;
        }

        $params = $this->request->getParams();
        $title  = trim($params['title'] ?? '');

        if ($title === '') {
            return new DataResponse(['error' => "Field 'title' is required"], Http::STATUS_BAD_REQUEST);
        }

        $note = new JournalNote();
        $note->setCaseId($caseId);
        $note->setTitle($title);
        $note->setText($params['text'] ?? null);
        $note->setIsLocked((int)(bool)($params['is_locked'] ?? false));
        $note->setCreatedBy($this->userId ?? '');
        $now = new \DateTime();
        $note->setCreatedAt($now);
        $note->setUpdatedAt($now);

        $note = $this->noteMapper->insert($note);

        $this->auditService->logJournalNoteCreated($caseId, $this->userId ?? '', $note->getId(), $title);

        return new DataResponse(['journal_note' => $this->serialize($note)], Http::STATUS_CREATED);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/journal-notes/{id}')]
    public function update(int $id): DataResponse {
        $note = $this->noteMapper->findById($id);
        if ($note === null) {
            return new DataResponse(['error' => 'Journal note not found'], Http::STATUS_NOT_FOUND);
        }
        if ($denied = $this->denyUnlessCaseWrite($note->getCaseId())) {
            return $denied;
        }
        if ($note->getIsLocked()) {
            return new DataResponse(['error' => 'Journal note is locked and cannot be edited'], Http::STATUS_FORBIDDEN);
        }

        $params = $this->request->getParams();
        $title  = trim($params['title'] ?? '');

        if ($title === '') {
            return new DataResponse(['error' => "Field 'title' is required"], Http::STATUS_BAD_REQUEST);
        }

        $note->setTitle($title);
        $note->setText($params['text'] ?? null);
        $note->setIsLocked((int)(bool)($params['is_locked'] ?? false));
        $note->setUpdatedAt(new \DateTime());

        $note = $this->noteMapper->update($note);

        $this->auditService->logJournalNoteUpdated($note->getCaseId(), $this->userId ?? '', $id, $title);

        return new DataResponse(['journal_note' => $this->serialize($note)]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/journal-notes/{id}')]
    public function destroy(int $id): DataResponse {
        $note = $this->noteMapper->findById($id);
        if ($note === null) {
            return new DataResponse(['error' => 'Journal note not found'], Http::STATUS_NOT_FOUND);
        }
        if ($denied = $this->denyUnlessCaseWrite($note->getCaseId())) {
            return $denied;
        }
        if ($note->getIsLocked()) {
            return new DataResponse(['error' => 'Journal note is locked and cannot be deleted'], Http::STATUS_FORBIDDEN);
        }

        $this->auditService->logJournalNoteDeleted($note->getCaseId(), $this->userId ?? '', $id, $note->getTitle());
        $this->noteMapper->delete($note);

        return new DataResponse([], Http::STATUS_NO_CONTENT);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/journal-notes/{id}/lock')]
    public function lock(int $id): DataResponse {
        $note = $this->noteMapper->findById($id);
        if ($note === null) {
            return new DataResponse(['error' => 'Journal note not found'], Http::STATUS_NOT_FOUND);
        }
        if ($denied = $this->denyUnlessCaseWrite($note->getCaseId())) {
            return $denied;
        }

        $note->setIsLocked(1);
        $note->setUpdatedAt(new \DateTime());
        $note = $this->noteMapper->update($note);

        $this->auditService->logJournalNoteLocked($note->getCaseId(), $this->userId ?? '', $id, $note->getTitle());

        return new DataResponse(['journal_note' => $this->serialize($note)]);
    }
}
