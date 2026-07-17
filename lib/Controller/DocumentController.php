<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\DocumentCategoryMapper;
use OCA\OpenCase\Db\DocumentStatusMapper;
use OCA\OpenCase\Db\InsightLevelMapper;
use OCA\OpenCase\Service\DocumentService;
use OCA\OpenCase\Service\FileService;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\TransactionLogService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\L10N\IFactory;

/**
 * REST API controller for document management within cases.
 *
 * Documents are metadata containers within a case. Each document
 * can have one or more files attached.
 *
 * Routes:
 *   GET    /api/v1/document-statuses                  — List available document statuses (localized)
 *   GET    /api/v1/cases/{caseId}/documents          — List documents in a case
 *   POST   /api/v1/cases/{caseId}/documents          — Create a document
 *   GET    /api/v1/documents/{id}                     — Get a document
 *   PUT    /api/v1/documents/{id}                     — Update document metadata
 *   DELETE /api/v1/documents/{id}                     — Delete a draft document
 *   PUT    /api/v1/documents/{id}/access/{targetUser} — Set per-user access override
 *   POST   /api/v1/documents/{id}/move-to-case        — Move an inbox document to an existing case
 */
class DocumentController extends ApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private DocumentService $documentService,
        private DocumentStatusMapper $documentStatusMapper,
        private DocumentCategoryMapper $documentCategoryMapper,
        private InsightLevelMapper $insightLevelMapper,
        private FileService $fileService,
        private TransactionLogService $transactionLogService,
        private IFactory $l10nFactory,
        private IUserManager $userManager,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request, corsAllowedHeaders: 'Authorization, Content-Type, Accept, OCS-APIREQUEST');
    }

    /**
     * Resolve the current user's preferred language.
     *
     * Uses getUserLanguage() rather than findLanguage() so that the result
     * reflects the user's NC preference regardless of whether the app ships
     * translation files for that language.
     */
    private function getUserLanguage(): string {
        if ($this->userId === null) {
            return 'en';
        }
        $user = $this->userManager->get($this->userId);
        return $user ? $this->l10nFactory->getUserLanguage($user) : 'en';
    }

    /**
     * List all available document statuses in the current user's language.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/document-statuses')]
    public function statuses(): DataResponse {
        $lang = $this->getUserLanguage();
        $rows = $this->documentStatusMapper->findByLanguage($lang, true);
        if (empty($rows)) {
            $rows = $this->documentStatusMapper->findByLanguage('en', true);
        }
        return new DataResponse(['statuses' => $rows]);
    }

    /**
     * List all available document categories in the current user's language.
     */
    #[NoAdminRequired]
    #[CORS]
    #[ApiRoute(verb: 'GET', url: '/api/v1/document-categories')]
    public function categories(): DataResponse {
        $lang = $this->getUserLanguage();
        $rows = $this->documentCategoryMapper->findByLanguage($lang, true);
        if (empty($rows)) {
            $rows = $this->documentCategoryMapper->findByLanguage('en', true);
        }
        return new DataResponse(['categories' => $rows]);
    }

    /**
     * List documents from all inbox cases the current user can access.
     *
     * Query parameters:
     *   limit  — Max results (default 50)
     *   offset — Pagination offset
     */
    /**
     * Field-based document search across all accessible cases.
     *
     * Query parameters (all optional):
     *   title         — Partial title match (ILIKE)
     *   document_type — Exact document type
     *   status        — Document status ID (integer)
     *   date_from     — document_date >= date_from (ISO date, e.g. 2024-01-01)
     *   date_to       — document_date <= date_to
     *   organisation  — Organisation name (exact)
     *   limit         — Max results (default 50, max 200)
     *   offset        — Pagination offset
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/documents')]
    public function search(): DataResponse {
        $title        = $this->request->getParam('title') ?: null;
        $documentType = $this->request->getParam('document_type') ?: null;
        $statusParam  = $this->request->getParam('status');
        $status       = ($statusParam !== null && $statusParam !== '') ? (int)$statusParam : null;
        $dateFrom     = $this->request->getParam('date_from') ?: null;
        $dateTo       = $this->request->getParam('date_to') ?: null;
        $organisation = $this->request->getParam('organisation') ?: null;
        $limit        = max(1, min(1000, (int)($this->request->getParam('limit', 50))));
        $offset       = max(0, (int)($this->request->getParam('offset', 0)));
        $catParam     = $this->request->getParam('document_category_id');
        $documentCategoryId = ($catParam !== null && $catParam !== '') ? (int)$catParam : null;
        $ilParam      = $this->request->getParam('insight_level_id');
        $insightLevelId = ($ilParam !== null && $ilParam !== '') ? (int)$ilParam : null;
        $createdBy    = $this->request->getParam('created_by_me') === '1' ? $this->userId : null;

        $result = $this->documentService->searchDocuments(
            $this->userId, $title, $documentType, $status,
            $dateFrom, $dateTo, $organisation, $limit, $offset,
            $documentCategoryId, $insightLevelId, $createdBy,
        );

        if ($this->userId !== null) {
            $criteria = array_filter([
                'title'               => $title,
                'document_type'       => $documentType,
                'status'              => $status,
                'date_from'           => $dateFrom,
                'date_to'             => $dateTo,
                'organisation'        => $organisation,
                'document_category_id'=> $documentCategoryId,
                'insight_level_id'    => $insightLevelId,
                'created_by_me'       => $createdBy !== null ? '1' : null,
            ], fn($v) => $v !== null && $v !== '');
            $this->transactionLogService->log($this->userId, 'search_document', $criteria);
        }

        $documents = array_map(fn($row) => [
            'id'              => (int)($row['id'] ?? 0),
            'case_id'         => (int)($row['case_id'] ?? 0),
            'case_number'     => $row['case_number'] ?? '',
            'case_title'      => $row['case_title'] ?? '',
            'document_number' => $row['document_number'] ?? '',
            'title'           => $row['title'] ?? '',
            'document_type'   => $row['document_type'] ?? '',
            'status'          => (int)($row['status'] ?? 1),
            'document_date'   => $row['document_date'] ?? null,
            'received_date'   => $row['received_date'] ?? null,
            'created_at'      => $row['created_at'] ?? null,
            'updated_at'      => $row['updated_at'] ?? null,
            'org_name'        => $row['org_name'] ?? '',
        ], $result['documents']);

        return new DataResponse(['documents' => $documents, 'total' => $result['total']]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/inbound-documents')]
    public function inbound(): DataResponse {
        $limit  = max(1, min(200, (int)($this->request->getParam('limit', 50))));
        $offset = max(0, (int)($this->request->getParam('offset', 0)));

        $result    = $this->documentService->listInboxDocuments($this->userId, $limit, $offset);
        $rows      = $result['documents'];
        $total     = $result['total'];

        $documents = array_map(fn($row) => [
            'id'              => (int)$row['id'],
            'case_id'         => (int)$row['case_id'],
            'case_title'      => $row['case_title'] ?? '',
            'org_name'        => $row['org_name'] ?? '',
            'document_number' => $row['document_number'] ?? '',
            'title'           => $row['title'] ?? '',
            'document_type'   => $row['document_type'] ?? '',
            'received_date'   => $row['received_date'] ?? null,
            'created_at'      => $row['created_at'] ?? null,
        ], $rows);

        return new DataResponse(['documents' => $documents, 'total' => $total]);
    }

    /**
     * List all documents in a case.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/cases/{caseId}/documents')]
    public function index(int $caseId): DataResponse {
        try {
            $documents     = $this->documentService->listByCase($caseId, $this->userId);
            $lang          = $this->getUserLanguage();
            $statusNames   = $this->documentStatusMapper->getNameMap($lang);
            $statusFinals  = $this->documentStatusMapper->getIsFinalMap();
            $insightIds    = array_unique(array_filter(array_map(fn($d) => $d->getInsightLevelId(), $documents)));
            $insightNames  = $this->insightLevelMapper->getNamesByIds($insightIds, $lang);
            $categoryNames = $this->documentCategoryMapper->getNameMap($lang);
            return new DataResponse([
                'documents' => array_map(
                    fn($d) => $this->serializeDocument($d, $statusNames, $statusFinals, $insightNames, $categoryNames),
                    $documents
                ),
            ]);
        } catch (NotFoundException $e) {
            return new DataResponse(
                ['error' => 'Case not found or access denied'],
                Http::STATUS_NOT_FOUND
            );
        }
    }

    /**
     * Create a new document within a case.
     *
     * Request body (JSON):
     *   title         — Required. Document title
     *   document_type — Optional. e.g. afgørelse, notat, brev, bilag
     */
    #[NoAdminRequired]
    #[CORS]
    #[ApiRoute(verb: 'POST', url: '/api/v1/cases/{caseId}/documents')]
    public function create(int $caseId): DataResponse {
        $params = $this->request->getParams();

        if (empty($params['title'])) {
            return new DataResponse(
                ['error' => "Field 'title' is required"],
                Http::STATUS_BAD_REQUEST
            );
        }

        $insightLevelId     = isset($params['insight_level_id']) ? (int)$params['insight_level_id'] : null;
        $documentDate       = isset($params['document_date']) ? (string)$params['document_date'] : null;
        $receivedDate       = isset($params['received_date']) ? (string)$params['received_date'] : null;
        $registeredDate     = isset($params['registered_date']) ? (string)$params['registered_date'] : null;
        $documentCategoryId = isset($params['document_category_id']) ? (int)$params['document_category_id'] : null;

        try {
            $entity       = $this->documentService->create(
                $caseId,
                $params['title'],
                $params['document_type'] ?? null,
                $this->userId,
                $insightLevelId,
                $documentDate,
                $receivedDate,
                $registeredDate,
                $documentCategoryId,
            );
            $lang          = $this->getUserLanguage();
            $statusNames   = $this->documentStatusMapper->getNameMap($lang);
            $statusFinals  = $this->documentStatusMapper->getIsFinalMap();
            $ilId          = $entity->getInsightLevelId();
            $insightNames  = $ilId ? $this->insightLevelMapper->getNamesByIds([$ilId], $lang) : [];
            $categoryNames = $this->documentCategoryMapper->getNameMap($lang);
            return new DataResponse(
                ['document' => $this->serializeDocument($entity, $statusNames, $statusFinals, $insightNames, $categoryNames)],
                Http::STATUS_CREATED
            );
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        } catch (NotFoundException $e) {
            return new DataResponse(
                ['error' => 'Case not found or access denied'],
                Http::STATUS_NOT_FOUND
            );
        }
    }

    /**
     * Get a single document.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/documents/{id}')]
    public function show(int $id): DataResponse {
        try {
            $entity        = $this->documentService->get($id, $this->userId);
            $lang          = $this->getUserLanguage();
            $statusNames   = $this->documentStatusMapper->getNameMap($lang);
            $statusFinals  = $this->documentStatusMapper->getIsFinalMap();
            $ilId          = $entity->getInsightLevelId();
            $insightNames  = $ilId ? $this->insightLevelMapper->getNamesByIds([$ilId], $lang) : [];
            $categoryNames = $this->documentCategoryMapper->getNameMap($lang);
            return new DataResponse(['document' => $this->serializeDocument($entity, $statusNames, $statusFinals, $insightNames, $categoryNames)]);
        } catch (NotFoundException $e) {
            return new DataResponse(
                ['error' => 'Document not found'],
                Http::STATUS_NOT_FOUND
            );
        }
    }

    /**
     * Update document metadata.
     *
     * Request body (JSON): any of title, document_type, status (int).
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/documents/{id}')]
    public function update(int $id): DataResponse {
        $params = $this->request->getParams();

        $status = isset($params['status']) ? (int)$params['status'] : null;
        $insightLevelProvided   = array_key_exists('insight_level_id', $params);
        $insightLevelId         = $insightLevelProvided ? (isset($params['insight_level_id']) ? (int)$params['insight_level_id'] : null) : null;
        $documentDateProvided   = array_key_exists('document_date', $params);
        $documentDate           = $documentDateProvided ? ($params['document_date'] !== '' ? (string)$params['document_date'] : null) : null;
        $receivedDateProvided   = array_key_exists('received_date', $params);
        $receivedDate           = $receivedDateProvided ? ($params['received_date'] !== '' ? (string)$params['received_date'] : null) : null;
        $registeredDateProvided = array_key_exists('registered_date', $params);
        $registeredDate         = $registeredDateProvided ? ($params['registered_date'] !== '' ? (string)$params['registered_date'] : null) : null;

        try {
            $entity       = $this->documentService->update(
                $id,
                $params['title'] ?? null,
                $params['document_type'] ?? null,
                $status,
                $this->userId,
                $insightLevelId,
                $insightLevelProvided,
                $documentDate,
                $documentDateProvided,
                $receivedDate,
                $receivedDateProvided,
                $registeredDate,
                $registeredDateProvided,
            );
            $statusFinals = $this->documentStatusMapper->getIsFinalMap();

            // Sync filecache permissions whenever the status is explicitly set
            if ($status !== null) {
                $isFinal = $statusFinals[$status] ?? false;
                $this->fileService->updateFilecachePermissionsForDocument($id, !$isFinal);
            }

            $lang          = $this->getUserLanguage();
            $statusNames   = $this->documentStatusMapper->getNameMap($lang);
            $ilId          = $entity->getInsightLevelId();
            $insightNames  = $ilId ? $this->insightLevelMapper->getNamesByIds([$ilId], $lang) : [];
            $categoryNames = $this->documentCategoryMapper->getNameMap($lang);
            return new DataResponse(['document' => $this->serializeDocument($entity, $statusNames, $statusFinals, $insightNames, $categoryNames)]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        } catch (NotFoundException $e) {
            return new DataResponse(
                ['error' => 'Document not found'],
                Http::STATUS_NOT_FOUND
            );
        }
    }

    /**
     * Delete a draft document and its files.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/documents/{id}')]
    public function destroy(int $id): DataResponse {
        try {
            $this->documentService->delete($id, $this->userId);
            return new DataResponse(['deleted' => true]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        } catch (NotFoundException $e) {
            return new DataResponse(
                ['error' => 'Document not found'],
                Http::STATUS_NOT_FOUND
            );
        }
    }

    /**
     * Set a per-user access override on a document.
     *
     * Request body (JSON):
     *   access_level — Required. One of: read, write, deny
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/documents/{id}/access/{targetUser}')]
    public function setAccess(int $id, string $targetUser): DataResponse {
        $params = $this->request->getParams();

        if (empty($params['access_level'])) {
            return new DataResponse(
                ['error' => "Field 'access_level' is required"],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $this->documentService->setUserAccess(
                $id,
                $targetUser,
                $params['access_level'],
                $this->userId,
            );

            return new DataResponse(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        } catch (NotFoundException $e) {
            return new DataResponse(
                ['error' => 'Document not found or access denied'],
                Http::STATUS_NOT_FOUND
            );
        }
    }

    /**
     * Move an inbox document to an existing non-inbox case.
     *
     * Request body (JSON):
     *   case_number — Required. Target case number (e.g. "2026-00004")
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/documents/{id}/move-to-case')]
    public function moveToCase(int $id): DataResponse {
        $params = $this->request->getParams();
        $caseNumber = trim((string)($params['case_number'] ?? ''));

        if ($caseNumber === '') {
            return new DataResponse(['error' => 'case_number is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $entity        = $this->documentService->moveToCase($id, $caseNumber, $this->userId);
            $lang          = $this->getUserLanguage();
            $statusNames   = $this->documentStatusMapper->getNameMap($lang);
            $statusFinals  = $this->documentStatusMapper->getIsFinalMap();
            $ilId          = $entity->getInsightLevelId();
            $insightNames  = $ilId ? $this->insightLevelMapper->getNamesByIds([$ilId], $lang) : [];
            $categoryNames = $this->documentCategoryMapper->getNameMap($lang);
            return new DataResponse(['document' => $this->serializeDocument($entity, $statusNames, $statusFinals, $insightNames, $categoryNames)]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * @param array<int, string> $statusNames    id → localized name map
     * @param array<int, bool>   $statusFinals   id → is_final map
     * @param array<int, string> $insightNames   id → localized name map
     * @param array<int, string> $categoryNames  id → localized name map
     */
    private function serializeDocument($entity, array $statusNames = [], array $statusFinals = [], array $insightNames = [], array $categoryNames = []): array {
        $statusId           = $entity->getStatus();
        $insightLevelId     = $entity->getInsightLevelId();
        $documentCategoryId = $entity->getDocumentCategoryId();
        $createdByUid       = $entity->getCreatedBy();
        $createdByUser      = $createdByUid ? $this->userManager->get($createdByUid) : null;
        return [
            'id'                     => $entity->getId(),
            'uuid'                   => $entity->getUuid(),
            'case_id'                => $entity->getCaseId(),
            'document_number'        => $entity->getDocumentNumber(),
            'title'                  => $entity->getTitle(),
            'document_type'          => $entity->getDocumentType(),
            'status'                 => $statusId,
            'status_name'            => $statusNames[$statusId] ?? (string)$statusId,
            'is_final'               => $statusFinals[$statusId] ?? false,
            'insight_level_id'       => $insightLevelId,
            'insight_level_name'     => $insightLevelId ? ($insightNames[$insightLevelId] ?? '') : '',
            'document_category_id'   => $documentCategoryId,
            'document_category_name' => $documentCategoryId ? ($categoryNames[$documentCategoryId] ?? '') : '',
            'created_at'             => $entity->getCreatedAt()?->format('c'),
            'updated_at'             => $entity->getUpdatedAt()?->format('c'),
            'created_by'             => $entity->getCreatedBy(),
            'created_by_display_name' => $createdByUser?->getDisplayName() ?? $createdByUid ?? '',
            'document_date'          => $entity->getDocumentDate(),
            'received_date'          => $entity->getReceivedDate(),
            'registered_date'        => $entity->getRegisteredDate(),
        ];
    }
}
