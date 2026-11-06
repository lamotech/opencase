<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\DocumentNote;
use OCA\OpenCase\Service\DocumentNoteService;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\PermissionService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * REST API controller for document notes.
 *
 * Routes:
 *   GET    /api/v1/documents/{documentId}/notes   — List notes for a document
 *   POST   /api/v1/documents/{documentId}/notes   — Create a note
 *   PUT    /api/v1/document-notes/{id}            — Update a note
 *   DELETE /api/v1/document-notes/{id}            — Delete a note
 */
class DocumentNoteController extends ApiController {

    use CaseAccessGuard;

    public function __construct(
        string $appName,
        IRequest $request,
        private DocumentNoteService $noteService,
        private DocumentMapper $documentMapper,
        private PermissionService $permissionService,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    private function serialize(DocumentNote $note): array {
        return [
            'id'          => $note->getId(),
            'document_id' => $note->getDocumentId(),
            'title'       => $note->getTitle(),
            'text'        => $note->getText(),
            'created_at'  => $note->getCreatedAt()?->format('c'),
            'created_by'  => $note->getCreatedBy(),
            'updated_at'  => $note->getUpdatedAt()?->format('c'),
        ];
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/documents/{documentId}/notes')]
    public function index(int $documentId): DataResponse {
        if ($this->documentMapper->findById($documentId) === null) {
            return new DataResponse(['error' => 'Document not found'], Http::STATUS_NOT_FOUND);
        }
        if ($denied = $this->denyUnlessDocumentRead($documentId)) {
            return $denied;
        }

        $notes = array_map(fn($n) => $this->serialize($n), $this->noteService->listForDocument($documentId));
        return new DataResponse(['document_notes' => $notes]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/documents/{documentId}/notes')]
    public function create(int $documentId): DataResponse {
        if ($this->documentMapper->findById($documentId) === null) {
            return new DataResponse(['error' => 'Document not found'], Http::STATUS_NOT_FOUND);
        }
        if ($denied = $this->denyUnlessDocumentWrite($documentId)) {
            return $denied;
        }

        $params = $this->request->getParams();
        try {
            $note = $this->noteService->create($documentId, (string)($params['title'] ?? ''), $params['text'] ?? null, $this->userId ?? '');
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        return new DataResponse(['document_note' => $this->serialize($note)], Http::STATUS_CREATED);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/document-notes/{id}')]
    public function update(int $id): DataResponse {
        try {
            $note = $this->noteService->get($id);
        } catch (NotFoundException) {
            return new DataResponse(['error' => 'Document note not found'], Http::STATUS_NOT_FOUND);
        }
        if ($denied = $this->denyUnlessDocumentWrite($note->getDocumentId())) {
            return $denied;
        }

        $params = $this->request->getParams();
        try {
            $note = $this->noteService->update($note, (string)($params['title'] ?? ''), $params['text'] ?? null, $this->userId ?? '');
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        return new DataResponse(['document_note' => $this->serialize($note)]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/document-notes/{id}')]
    public function destroy(int $id): DataResponse {
        try {
            $note = $this->noteService->get($id);
        } catch (NotFoundException) {
            return new DataResponse(['error' => 'Document note not found'], Http::STATUS_NOT_FOUND);
        }
        if ($denied = $this->denyUnlessDocumentWrite($note->getDocumentId())) {
            return $denied;
        }

        $this->noteService->delete($note, $this->userId ?? '');
        return new DataResponse([], Http::STATUS_NO_CONTENT);
    }
}
