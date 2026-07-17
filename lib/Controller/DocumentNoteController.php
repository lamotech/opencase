<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\DocumentNote;
use OCA\OpenCase\Db\DocumentNoteMapper;
use OCA\OpenCase\Service\AuditService;
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

    public function __construct(
        string $appName,
        IRequest $request,
        private DocumentNoteMapper $noteMapper,
        private DocumentMapper $documentMapper,
        private AuditService $auditService,
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

        $notes = array_map(fn($n) => $this->serialize($n), $this->noteMapper->findByDocument($documentId));

        return new DataResponse(['document_notes' => $notes]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/documents/{documentId}/notes')]
    public function create(int $documentId): DataResponse {
        $document = $this->documentMapper->findById($documentId);
        if ($document === null) {
            return new DataResponse(['error' => 'Document not found'], Http::STATUS_NOT_FOUND);
        }

        $params = $this->request->getParams();
        $title  = trim($params['title'] ?? '');

        if ($title === '') {
            return new DataResponse(['error' => "Field 'title' is required"], Http::STATUS_BAD_REQUEST);
        }

        $note = new DocumentNote();
        $note->setDocumentId($documentId);
        $note->setTitle($title);
        $note->setText($params['text'] ?? null);
        $note->setCreatedBy($this->userId ?? '');
        $now = new \DateTime();
        $note->setCreatedAt($now);
        $note->setUpdatedAt($now);

        $note = $this->noteMapper->insert($note);

        $this->auditService->logDocumentNoteCreated(
            $document->getCaseId(), $documentId, $this->userId ?? '', $note->getId(), $title
        );

        return new DataResponse(['document_note' => $this->serialize($note)], Http::STATUS_CREATED);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/document-notes/{id}')]
    public function update(int $id): DataResponse {
        $note = $this->noteMapper->findById($id);
        if ($note === null) {
            return new DataResponse(['error' => 'Document note not found'], Http::STATUS_NOT_FOUND);
        }

        $params = $this->request->getParams();
        $title  = trim($params['title'] ?? '');

        if ($title === '') {
            return new DataResponse(['error' => "Field 'title' is required"], Http::STATUS_BAD_REQUEST);
        }

        $note->setTitle($title);
        $note->setText($params['text'] ?? null);
        $note->setUpdatedAt(new \DateTime());

        $note = $this->noteMapper->update($note);

        $document = $this->documentMapper->findById($note->getDocumentId());
        if ($document !== null) {
            $this->auditService->logDocumentNoteUpdated(
                $document->getCaseId(), $note->getDocumentId(), $this->userId ?? '', $id, $title
            );
        }

        return new DataResponse(['document_note' => $this->serialize($note)]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/document-notes/{id}')]
    public function destroy(int $id): DataResponse {
        $note = $this->noteMapper->findById($id);
        if ($note === null) {
            return new DataResponse(['error' => 'Document note not found'], Http::STATUS_NOT_FOUND);
        }

        $document = $this->documentMapper->findById($note->getDocumentId());
        if ($document !== null) {
            $this->auditService->logDocumentNoteDeleted(
                $document->getCaseId(), $note->getDocumentId(), $this->userId ?? '', $id, $note->getTitle()
            );
        }

        $this->noteMapper->delete($note);

        return new DataResponse([], Http::STATUS_NO_CONTENT);
    }
}
