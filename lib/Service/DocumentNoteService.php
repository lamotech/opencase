<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\DocumentNote;
use OCA\OpenCase\Db\DocumentNoteMapper;

/**
 * Notes on a document (opencase_document_notes), with audit logging.
 * Callers check access to the document first.
 */
class DocumentNoteService {

    public function __construct(
        private DocumentNoteMapper $noteMapper,
        private DocumentMapper $documentMapper,
        private AuditService $auditService,
    ) {
    }

    /**
     * @return DocumentNote[]
     */
    public function listForDocument(int $documentId): array {
        return $this->noteMapper->findByDocument($documentId);
    }

    /**
     * @throws NotFoundException
     */
    public function get(int $noteId): DocumentNote {
        $note = $this->noteMapper->findById($noteId);
        if ($note === null) {
            throw new NotFoundException('Document note not found');
        }
        return $note;
    }

    /**
     * @throws \InvalidArgumentException when the title is empty
     * @throws NotFoundException         when the document does not exist
     */
    public function create(int $documentId, string $title, ?string $text, string $userId): DocumentNote {
        $document = $this->documentMapper->findById($documentId);
        if ($document === null) {
            throw new NotFoundException('Document not found');
        }
        $title = $this->requireTitle($title);

        $note = new DocumentNote();
        $note->setDocumentId($documentId);
        $note->setTitle($title);
        $note->setText($text);
        $note->setCreatedBy($userId);
        $now = new \DateTime();
        $note->setCreatedAt($now);
        $note->setUpdatedAt($now);
        $note = $this->noteMapper->insert($note);

        $this->auditService->logDocumentNoteCreated($document->getCaseId(), $documentId, $userId, $note->getId(), $title);

        return $note;
    }

    /**
     * @throws \InvalidArgumentException when the title is empty
     */
    public function update(DocumentNote $note, string $title, ?string $text, string $userId): DocumentNote {
        $title = $this->requireTitle($title);

        $note->setTitle($title);
        $note->setText($text);
        $note->setUpdatedAt(new \DateTime());
        $note = $this->noteMapper->update($note);

        $document = $this->documentMapper->findById($note->getDocumentId());
        if ($document !== null) {
            $this->auditService->logDocumentNoteUpdated($document->getCaseId(), $note->getDocumentId(), $userId, $note->getId(), $title);
        }

        return $note;
    }

    public function delete(DocumentNote $note, string $userId): void {
        $document = $this->documentMapper->findById($note->getDocumentId());
        if ($document !== null) {
            $this->auditService->logDocumentNoteDeleted($document->getCaseId(), $note->getDocumentId(), $userId, $note->getId(), $note->getTitle());
        }
        $this->noteMapper->delete($note);
    }

    private function requireTitle(string $title): string {
        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException("Field 'title' is required");
        }
        return $title;
    }
}
