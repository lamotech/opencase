<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\DocumentRelationMapper;
use OCA\OpenCase\Service\DocumentRelationService;
use OCA\OpenCase\Service\DocumentService;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\PermissionService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\L10N\IFactory;

/**
 * REST API controller for document relations.
 *
 * Routes:
 *   GET  /api/v1/documents/{documentId}/relations            — List relations for a document
 *   POST /api/v1/documents/{documentId}/relations            — Relate documents to this document
 *   GET  /api/v1/documents/{documentId}/relation-candidates  — Documents on a case that can be related
 *   DELETE /api/v1/documents/{documentId}/relations/{id}     — Remove a relation
 */
class DocumentRelationController extends ApiController {

    use CaseAccessGuard;

    public function __construct(
        string $appName,
        IRequest $request,
        private DocumentRelationMapper $relationMapper,
        private DocumentMapper $documentMapper,
        private CaseMapper $caseMapper,
        private DocumentService $documentService,
        private DocumentRelationService $relationService,
        private PermissionService $permissionService,
        private IFactory $l10nFactory,
        private IUserManager $userManager,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    private function getUserLanguage(): string {
        if ($this->userId === null) {
            return 'en';
        }
        $user = $this->userManager->get($this->userId);
        return $user ? $this->l10nFactory->getUserLanguage($user) : 'en';
    }

    private function resolveTypeNames(): array {
        return $this->relationService->typeNames($this->getUserLanguage());
    }

    /**
     * Each relation is returned from this document's point of view:
     * `direction` is 'to' when this document is the from-side (the other
     * document is the target) and 'from' otherwise. The other document's
     * title is withheld when the user has no read access to it.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/documents/{documentId}/relations')]
    public function index(int $documentId): DataResponse {
        if ($this->documentMapper->findById($documentId) === null) {
            return new DataResponse(['error' => 'Document not found'], Http::STATUS_NOT_FOUND);
        }

        if ($denied = $this->denyUnlessDocumentRead($documentId)) {
            return $denied;
        }

        $typeNames = $this->resolveTypeNames();
        $relations = [];
        foreach ($this->relationMapper->findByDocument($documentId) as $r) {
            $isFrom = $r->getDocumentFromId() === $documentId;
            $otherId = $isFrom ? $r->getDocumentToId() : $r->getDocumentFromId();
            $other = $this->documentMapper->findById($otherId);
            if ($other === null) {
                continue;
            }
            $canRead = $this->permissionService->userHasReadAccessToDocument($this->userId, $otherId);

            $relations[] = [
                'id'                      => $r->getId(),
                'direction'               => $isFrom ? 'to' : 'from',
                'documentrelationtype_id' => $r->getDocumentrelationtypeId(),
                'type_name'               => $typeNames[$r->getDocumentrelationtypeId()] ?? '',
                'document_id'             => $otherId,
                'document_number'         => $other->getDocumentNumber(),
                'document_title'          => $canRead ? $other->getTitle() : null,
                'can_read'                => $canRead,
                'created_at'              => $r->getCreatedAt()?->format('c'),
                'created_by'              => $r->getCreatedBy(),
            ];
        }

        return new DataResponse(['document_relations' => $relations]);
    }

    /**
     * Documents on the case with the given case number that the user can
     * read, excluding this document itself. `already_related` marks documents
     * that already have a relation of the given type to this document.
     *
     * Query params:
     *   case_number              — Required. Exact case number
     *   documentrelationtype_id  — Relation type (default 1 = Bilag)
     *   limit                    — Page size (default 10, max 100)
     *   offset                   — Pagination offset
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/documents/{documentId}/relation-candidates')]
    public function candidates(int $documentId): DataResponse {
        if ($this->documentMapper->findById($documentId) === null) {
            return new DataResponse(['error' => 'Document not found'], Http::STATUS_NOT_FOUND);
        }

        if ($denied = $this->denyUnlessDocumentRead($documentId)) {
            return $denied;
        }

        $caseNumber = trim((string)$this->request->getParam('case_number', ''));
        $typeId     = (int)$this->request->getParam('documentrelationtype_id', 1);
        $limit      = max(1, min(100, (int)$this->request->getParam('limit', 10)));
        $offset     = max(0, (int)$this->request->getParam('offset', 0));

        $case = $caseNumber !== '' ? $this->caseMapper->findByCaseNumber($caseNumber) : null;
        if ($case === null) {
            return new DataResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $documents = $this->documentService->listByCase($case->getId(), $this->userId);
        } catch (NotFoundException) {
            return new DataResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
        }

        $documents = array_values(array_filter($documents, fn($d) => $d->getId() !== $documentId));
        $related   = array_flip($this->relationMapper->findRelatedDocumentIds($documentId, $typeId));

        $page = array_map(fn($d) => [
            'id'              => $d->getId(),
            'document_number' => $d->getDocumentNumber(),
            'title'           => $d->getTitle(),
            'document_date'   => $d->getDocumentDate(),
            'already_related' => isset($related[$d->getId()]),
        ], array_slice($documents, $offset, $limit));

        return new DataResponse([
            'case' => [
                'id'          => $case->getId(),
                'case_number' => $case->getCaseNumber(),
                'title'       => $case->getTitle(),
            ],
            'documents' => $page,
            'total'     => count($documents),
        ]);
    }

    /**
     * Relate one or more documents to this document. This document is the
     * from-side; each given document becomes a to-side. Pairs that already
     * have a relation of the same type are skipped.
     *
     * Request body (JSON):
     *   document_ids             — Required. Ids of the documents to relate
     *   documentrelationtype_id  — Relation type (default 1 = Bilag)
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/documents/{documentId}/relations')]
    public function create(int $documentId): DataResponse {
        $document = $this->documentMapper->findById($documentId);
        if ($document === null) {
            return new DataResponse(['error' => 'Document not found'], Http::STATUS_NOT_FOUND);
        }

        if ($denied = $this->denyUnlessDocumentWrite($documentId)) {
            return $denied;
        }

        try {
            $created = $this->relationService->addRelations(
                $document,
                (array)$this->request->getParam('document_ids', []),
                (int)$this->request->getParam('documentrelationtype_id', 1),
                $this->userId,
                $this->getUserLanguage(),
            );
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

        return new DataResponse(['created' => count($created)], Http::STATUS_CREATED);
    }

    /**
     * Remove a relation. The relation may have this document on either side;
     * write access to this document is enough to remove it.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/documents/{documentId}/relations/{id}')]
    public function destroy(int $documentId, int $id): DataResponse {
        $document = $this->documentMapper->findById($documentId);
        if ($document === null) {
            return new DataResponse(['error' => 'Relation not found'], Http::STATUS_NOT_FOUND);
        }

        if ($denied = $this->denyUnlessDocumentWrite($documentId)) {
            return $denied;
        }

        try {
            $this->relationService->deleteRelation($document, $id, $this->userId, $this->getUserLanguage());
        } catch (NotFoundException) {
            return new DataResponse(['error' => 'Relation not found'], Http::STATUS_NOT_FOUND);
        }

        return new DataResponse([], Http::STATUS_NO_CONTENT);
    }
}
