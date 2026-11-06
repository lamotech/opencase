<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\CaseEntity;
use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\CaseStatusMapper;
use OCA\OpenCase\Db\Document;
use OCA\OpenCase\Db\DocumentCategoryMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\DocumentStatusMapper;
use OCA\OpenCase\Db\DocumentTypeMapper;
use OCA\OpenCase\Db\FavoriteMapper;
use OCA\OpenCase\Db\FileEntity;
use OCA\OpenCase\Db\InsightLevelMapper;
use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Service\AuditService;
use OCA\OpenCase\Service\CaseService;
use OCA\OpenCase\Service\CitizenSearchService;
use OCA\OpenCase\Service\CompanySearchService;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\DesktopLinks;
use OCA\OpenCase\Service\DigitalPostService;
use OCA\OpenCase\Service\DocumentService;
use OCA\OpenCase\Service\FileService;
use OCA\OpenCase\Service\HistoryService;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\PermissionService;
use OCA\OpenCase\Service\RoleService;
use OCA\OpenCase\Service\SearchService;
use OCA\OpenCase\Service\TransactionLogService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\L10N\IFactory;

/**
 * API for Office desktop clients (e.g. Euro-Office desktop).
 *
 * Authenticated with Basic auth (login name + app password from the login
 * flow in DesktopAuthController); #[CORS] rules out cookie sessions.
 * Every file is returned with a URL that opens it in the editor.
 *
 * Routes:
 *   GET /desktop/v1/api/me                       — Current user and what they may do
 *   GET /desktop/v1/api/documents/favorites      — Favorite documents
 *   GET /desktop/v1/api/documents/recent         — Recently accessed documents
 *   GET /desktop/v1/api/documents/my-documents   — Documents created by the user
 *   GET /desktop/v1/api/documents/fulltextsearch — Full-text search, files part
 *   GET  /desktop/v1/api/cases                    — Case search (case picker)
 *   GET  /desktop/v1/api/document-categories      — Document categories
 *   GET  /desktop/v1/api/document-types           — Document types
 *   GET  /desktop/v1/api/insight-levels           — Insight levels
 *   POST /desktop/v1/api/documents                — Create a document
 *   POST /desktop/v1/api/documents/{id}/files     — Add a file to a document
 *   GET  /desktop/v1/api/files/{fileId}/document  — Document info for a Nextcloud file ID
 *
 * Templates: see DesktopTemplateApiController.
 *
 * Common query parameter:
 *   client — editor the URLs are built for (default: eurooffice)
 *
 * The request and response formats are documented in README.md
 * ("Login and backend API").
 */
class DesktopApiController extends ApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private DocumentService $documentService,
        private FileService $fileService,
        private CaseMapper $caseMapper,
        private DocumentMapper $documentMapper,
        private FavoriteMapper $favoriteMapper,
        private HistoryService $historyService,
        private SearchService $searchService,
        private TransactionLogService $transactionLogService,
        private Configuration $configuration,
        private CaseService $caseService,
        private CaseStatusMapper $caseStatusMapper,
        private OrganisationMapper $organisationMapper,
        private DocumentCategoryMapper $documentCategoryMapper,
        private DocumentTypeMapper $documentTypeMapper,
        private DocumentStatusMapper $documentStatusMapper,
        private InsightLevelMapper $insightLevelMapper,
        private PermissionService $permissionService,
        private IFactory $l10nFactory,
        private IUserManager $userManager,
        private DesktopLinks $links,
        private RoleService $roleService,
        private CitizenSearchService $citizenSearchService,
        private CompanySearchService $companySearchService,
        private DigitalPostService $digitalPostService,
        private AuditService $auditService,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * The current user and what they may do: whether they have access to
     * OpenCase at all (the other endpoints answer 403 when they do not),
     * may create and edit templates, may send Digital Post, and whether
     * citizen/company searches use the CPR/CVR registers.
     *
     * RoleMiddleware lets this through for users without OpenCase access,
     * so a client can tell them apart from a failed login.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function me(): JSONResponse {
        $userId = $this->userId ?? '';
        $isOpenCaseUser = $this->roleService->isOpenCaseUser($userId);
        return new JSONResponse(($this->links->user($this->userId) ?? []) + [
            'isOpenCaseUser'      => $isOpenCaseUser,
            'canManageTemplates'  => $isOpenCaseUser && (
                $this->roleService->userHasRole($userId, 'Administrator')
                || $this->roleService->userHasRole($userId, 'Template Designer')
            ),
            'canSendDigitalPost'  => $isOpenCaseUser && $this->digitalPostService->isEnabled(),
            'registerSearch'      => [
                'citizens'  => $this->citizenSearchService->usesRegister(),
                'companies' => $this->companySearchService->usesRegister(),
            ],
        ]);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function favorites(string $client = 'eurooffice', int $limit = 50): JSONResponse {
        if (!$this->links->isSupportedClient($client)) {
            return new JSONResponse(['error' => 'Unsupported client'], Http::STATUS_BAD_REQUEST);
        }

        $ids = [];
        foreach ($this->favoriteMapper->findByUser($this->userId ?? '') as $fav) {
            if ($fav->getEntity() === 'document') {
                $ids[] = (int)$fav->getEntityKey();
            }
        }

        return new JSONResponse($this->serializeDocuments($ids, $client, $limit));
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function recent(string $client = 'eurooffice', int $limit = 50): JSONResponse {
        if (!$this->links->isSupportedClient($client)) {
            return new JSONResponse(['error' => 'Unsupported client'], Http::STATUS_BAD_REQUEST);
        }

        $ids = [];
        foreach ($this->historyService->findByUser($this->userId ?? '') as $entry) {
            if ($entry->getEntity() === 'document') {
                $ids[] = (int)$entry->getEntityKey();
            }
        }

        return new JSONResponse($this->serializeDocuments($ids, $client, $limit));
    }

    /**
     * Documents created by the user, most recently updated first — like the
     * "Mine dokumenter" page (/opencase/my-documents). Paged with limit/offset;
     * the total count is sent in the X-Total-Count header.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function myDocuments(string $client = 'eurooffice', int $limit = 50, int $offset = 0): JSONResponse {
        if (!$this->links->isSupportedClient($client)) {
            return new JSONResponse(['error' => 'Unsupported client'], Http::STATUS_BAD_REQUEST);
        }

        $limit  = max(1, min($limit, 200));
        $result = $this->documentService->searchDocuments(
            $this->userId ?? '',
            limit: $limit,
            offset: max(0, $offset),
            createdBy: $this->userId ?? '',
        );

        $ids      = array_map(fn(array $row) => (int)$row['id'], $result['documents']);
        $response = new JSONResponse($this->serializeDocuments($ids, $client, $limit));
        $response->addHeader('X-Total-Count', (string)$result['total']);
        $response->addHeader('Access-Control-Expose-Headers', 'X-Total-Count');
        return $response;
    }

    /**
     * Full-text search: the files part of the search page (/opencase/search,
     * "Filer" tab), with the same filters, facets and paging.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function fulltextSearch(string $client = 'eurooffice', string $q = '', ?int $limit = null, int $offset = 0): JSONResponse {
        if (!$this->links->isSupportedClient($client)) {
            return new JSONResponse(['error' => 'Unsupported client'], Http::STATUS_BAD_REQUEST);
        }
        if (trim($q) === '') {
            return new JSONResponse(['error' => "Query parameter 'q' is required"], Http::STATUS_BAD_REQUEST);
        }

        $filters = [];
        foreach (['organisation', 'case_type', 'document_type', 'year'] as $key) {
            $value = $this->request->getParam($key);
            if (is_string($value) && $value !== '') {
                $filters[$key] = $value;
            }
        }

        // Same paging limits as the search page
        $maxResults = max(1, (int)$this->configuration->getConfigValue('search_max_result_count', '500'));
        $limit      = max(1, min($limit ?? (int)$this->configuration->getConfigValue('search_page_size', '50'), 200));
        $offset     = max(0, $offset);

        $response = [
            'query'   => $q,
            'filters' => (object)$filters,
            'total'   => 0,
            'limit'   => $limit,
            'offset'  => $offset,
            'facets'  => ['organisations' => [], 'caseTypes' => [], 'documentTypes' => []],
            'files'   => [],
        ];

        if ($offset >= $maxResults) {
            return new JSONResponse($response);
        }

        try {
            $results = $this->searchService->search(
                $this->userId ?? '',
                $q,
                $filters,
                $offset,
                min($limit, $maxResults - $offset),
            );
        } catch (\Throwable) {
            return new JSONResponse(['error' => 'Search failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $this->transactionLogService->log($this->userId ?? '', 'search_freetext', array_merge(['q' => $q], $filters));

        $aggregations = $results['aggregations'];
        $response['total']  = min((int)$results['total'], $maxResults);
        $response['facets'] = [
            'organisations' => $this->serializeBuckets($aggregations['organisations']['buckets'] ?? []),
            'caseTypes'     => $this->serializeBuckets($aggregations['case_types']['buckets'] ?? []),
            'documentTypes' => $this->serializeBuckets($aggregations['document_types']['buckets'] ?? []),
        ];

        foreach ($results['files'] as $hit) {
            $item = $this->serializeSearchHit($hit, $client);
            if ($item !== null) {
                $response['files'][] = $item;
            }
        }

        return new JSONResponse($response);
    }

    /**
     * Search cases by case number or title — the case picker used when saving
     * a file from the Files app to OpenCase.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function cases(string $search = '', int $limit = 20): JSONResponse {
        if (trim($search) === '') {
            return new JSONResponse(['error' => "Query parameter 'search' is required"], Http::STATUS_BAD_REQUEST);
        }

        $userId = $this->userId ?? '';
        $result = $this->caseService->list($userId, search: $search, limit: max(1, min($limit, 200)));
        $this->transactionLogService->log($userId, 'search_case', ['search' => $search]);

        $cases       = $result['cases'];
        $orgNames    = $this->organisationMapper->getNamesByUuids(array_unique(array_map(fn($c) => $c->getOrgUuid(), $cases)));
        $statusNames = $this->caseStatusMapper->getNamesByIds(array_unique(array_map(fn($c) => $c->getStatusId(), $cases)), $this->userLanguage());

        return new JSONResponse(array_map(fn(CaseEntity $c) => [
            'id'             => $c->getId(),
            'caseNumber'     => $c->getCaseNumber(),
            'title'          => $c->getTitle(),
            'organisation'   => $orgNames[$c->getOrgUuid()] ?? '',
            'status'         => $statusNames[$c->getStatusId()]['name'] ?? '',
            'isClosed'       => (bool)($statusNames[$c->getStatusId()]['is_closed'] ?? false),
            'insightLevelId' => $c->getInsightLevelId(),
            'canWrite'       => $this->permissionService->userHasWriteAccessToCase($userId, $c->getId()),
            'url'            => $this->links->appUrl('case/' . $c->getId()),
        ], $cases));
    }

    /**
     * Document categories in the user's language. Required when creating a document.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function documentCategories(): JSONResponse {
        $rows = $this->documentCategoryMapper->findByLanguage($this->userLanguage(), true);
        if (empty($rows)) {
            $rows = $this->documentCategoryMapper->findByLanguage('en', true);
        }
        return new JSONResponse(array_map(fn(array $r) => ['id' => $r['id'], 'name' => $r['name']], $rows));
    }

    /**
     * User-selectable document types (system types set by integrations are left out).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function documentTypes(): JSONResponse {
        return new JSONResponse(array_map(
            fn(array $r) => ['id' => $r['id'], 'name' => $r['name']],
            $this->documentTypeMapper->findAll(true, false),
        ));
    }

    /**
     * Insight levels in the user's language.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function insightLevels(): JSONResponse {
        return new JSONResponse(array_map(fn($l) => [
            'id'          => $l->getId(),
            'name'        => $l->getName(),
            'description' => $l->getDescription(),
        ], $this->insightLevelMapper->findAllForLanguage($this->userLanguage(), 'en', true)));
    }

    /**
     * Create a document with metadata in a case. Returns it in the same shape
     * as the document lists (with an empty files list).
     *
     * Body (JSON): caseId, title, documentCategoryId (required); documentType,
     * insightLevelId, documentDate, receivedDate, registeredDate, summary.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function createDocument(string $client = 'eurooffice'): JSONResponse {
        if (!$this->links->isSupportedClient($client)) {
            return new JSONResponse(['error' => 'Unsupported client'], Http::STATUS_BAD_REQUEST);
        }

        $params             = $this->request->getParams();
        $caseId             = (int)($params['caseId'] ?? 0);
        $title              = trim((string)($params['title'] ?? ''));
        $documentCategoryId = $this->intOrNull($params['documentCategoryId'] ?? null);

        if ($caseId <= 0 || $title === '' || $documentCategoryId === null) {
            return new JSONResponse(
                ['error' => "Fields 'caseId', 'title' and 'documentCategoryId' are required"],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $documentType = $this->stringOrNull($params['documentType'] ?? null);
        if ($documentType !== null
            && !in_array($documentType, array_column($this->documentTypeMapper->findAll(true, false), 'name'), true)
        ) {
            return new JSONResponse(['error' => 'Unknown documentType'], Http::STATUS_BAD_REQUEST);
        }

        // Like the web dialog: the insight level defaults to the case's own
        $insightLevelId = array_key_exists('insightLevelId', $params)
            ? $this->intOrNull($params['insightLevelId'])
            : $this->caseMapper->findById($caseId)?->getInsightLevelId();

        try {
            $document = $this->documentService->create(
                $caseId,
                $title,
                $documentType,
                $this->userId ?? '',
                $insightLevelId,
                $this->stringOrNull($params['documentDate'] ?? null),
                $this->stringOrNull($params['receivedDate'] ?? null),
                $this->stringOrNull($params['registeredDate'] ?? null),
                $documentCategoryId,
                $this->stringOrNull($params['summary'] ?? null),
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (NotFoundException) {
            return new JSONResponse(['error' => 'Case not found or no write access'], Http::STATUS_NOT_FOUND);
        }
        $this->historyService->recordDocumentAccess($document->getId(), $this->userId ?? '');

        return new JSONResponse($this->serializeDocument($document, $client), Http::STATUS_CREATED);
    }

    /**
     * Add a file to a document. multipart/form-data with the content in
     * `file`; the optional `name` field overrides the uploaded file name.
     * Returns the file like the document lists (fileId, name, editorUrl).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function uploadFile(int $id, string $client = 'eurooffice'): JSONResponse {
        if (!$this->links->isSupportedClient($client)) {
            return new JSONResponse(['error' => 'Unsupported client'], Http::STATUS_BAD_REQUEST);
        }

        $upload = $this->request->getUploadedFile('file');
        if ($upload === null || $upload['error'] !== UPLOAD_ERR_OK) {
            $message = match ($upload['error'] ?? UPLOAD_ERR_NO_FILE) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds maximum upload size',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => "No file was uploaded in field 'file'",
                default => 'File upload failed',
            };
            return new JSONResponse(['error' => $message], Http::STATUS_BAD_REQUEST);
        }

        $name = $this->stringOrNull($this->request->getParam('name')) ?? (string)$upload['name'];

        $stream = fopen($upload['tmp_name'], 'rb');
        if ($stream === false) {
            return new JSONResponse(['error' => 'Failed to read uploaded file'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        try {
            $file = $this->fileService->upload(
                $id,
                $name,
                $upload['type'] ?: 'application/octet-stream',
                $stream,
                $this->userId ?? '',
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (NotFoundException) {
            return new JSONResponse(['error' => 'Document not found or no write access'], Http::STATUS_NOT_FOUND);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $case       = $this->caseMapper->findById($file->getCaseId());
        $editorFile = $case === null ? null : $this->links->editorFile($file, $case, $client, $this->userId ?? '');
        if ($editorFile === null) {
            return new JSONResponse(['error' => 'File was stored but cannot be opened'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        $this->historyService->recordDocumentAccess($file->getDocumentId(), $this->userId ?? '');

        return new JSONResponse($editorFile, Http::STATUS_CREATED);
    }

    /**
     * Document info for a file, looked up by its Nextcloud file ID — the ID
     * the editor has for the open file (and `fileId` elsewhere in this API).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function fileDocument(int $fileId, string $client = 'eurooffice'): JSONResponse {
        if (!$this->links->isSupportedClient($client)) {
            return new JSONResponse(['error' => 'Unsupported client'], Http::STATUS_BAD_REQUEST);
        }

        $userId = $this->userId ?? '';
        try {
            $file = $this->fileService->findByNextcloudFileId($fileId, $userId);
            $doc  = $this->documentService->get($file->getDocumentId(), $userId, false);
        } catch (NotFoundException) {
            return new JSONResponse(['error' => 'File not found or no access'], Http::STATUS_NOT_FOUND);
        }
        $case = $this->caseMapper->findById($doc->getCaseId());
        if ($doc->isSoftDeleted() || $case === null) {
            return new JSONResponse(['error' => 'File not found or no access'], Http::STATUS_NOT_FOUND);
        }
        // The client asks for this when a file is opened in the editor, so it
        // stands in for opening the document page in OpenCase.
        $this->auditService->logDocumentViewed($doc->getCaseId(), $doc->getId(), $userId);
        $this->auditService->logFileViewed($doc->getCaseId(), $doc->getId(), $file->getId(), $userId, $file->getOriginalFilename());

        $lang           = $this->userLanguage();
        $statusId       = $doc->getStatus();
        $categoryId     = $doc->getDocumentCategoryId();
        $insightLevelId = $doc->getInsightLevelId();
        $categoryNames  = $categoryId ? $this->documentCategoryMapper->getNameMap($lang) : [];
        $insightNames   = $insightLevelId ? $this->insightLevelMapper->getNamesByIds([$insightLevelId], $lang) : [];
        $orgNames       = $this->organisationMapper->getNamesByUuids([$case->getOrgUuid()]);
        $createdBy      = $doc->getCreatedBy();
        $updatedBy      = $doc->getUpdatedBy() ?: $createdBy;

        return new JSONResponse($this->serializeDocument($doc, $client) + [
            'documentType'   => $doc->getDocumentType() ?? '',
            'status'         => [
                'id'      => $statusId,
                'name'    => $this->documentStatusMapper->getNameMap($lang)[$statusId] ?? (string)$statusId,
                'isFinal' => (bool)($this->documentStatusMapper->getIsFinalMap()[$statusId] ?? false),
            ],
            'category'       => $categoryId ? ['id' => $categoryId, 'name' => $categoryNames[$categoryId] ?? ''] : null,
            'insightLevel'   => $insightLevelId ? ['id' => $insightLevelId, 'name' => $insightNames[$insightLevelId] ?? ''] : null,
            'documentDate'   => $doc->getDocumentDate(),
            'receivedDate'   => $doc->getReceivedDate(),
            'registeredDate' => $doc->getRegisteredDate(),
            'summary'        => $doc->getSummary(),
            'createdAt'      => $doc->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'createdBy'      => $this->links->user($createdBy),
            'updatedAt'      => $doc->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedBy'      => $this->links->user($updatedBy),
            'canWrite'       => $this->documentService->canWrite($doc->getId(), $userId),
            'isFavorite'     => $this->favoriteMapper->findByUserAndEntity($userId, 'document', $doc->getId()) !== null,
            'case'           => [
                'id'           => $case->getId(),
                'caseNumber'   => $case->getCaseNumber(),
                'title'        => $case->getTitle(),
                'organisation' => $orgNames[$case->getOrgUuid()] ?? '',
                'url'          => $this->links->appUrl('case/' . $case->getId()),
            ],
            'file'           => ($this->links->editorFile($file, $case, $client, $this->userId ?? '') ?? ['fileId' => $fileId, 'name' => $file->getVirtualFilename()]) + [
                'mimeType'  => $file->getMimeType(),
                'size'      => $file->getSize(),
                'version'   => $file->getVersion(),
                'updatedAt' => $file->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }

    /**
     * Serialize the given documents in order, skipping documents that are
     * deleted or that the user can no longer read. Not audited: like the web
     * app's lists, listing a document is not viewing it.
     *
     * @param int[] $documentIds
     */
    private function serializeDocuments(array $documentIds, string $client, int $limit): array {
        $limit = max(1, min($limit, 200));
        $items = [];

        foreach ($documentIds as $id) {
            if (count($items) >= $limit) {
                break;
            }
            try {
                $doc = $this->documentService->get($id, $this->userId ?? '', false);
            } catch (NotFoundException) {
                continue;
            }
            if ($doc->isSoftDeleted()) {
                continue;
            }
            $items[] = $this->serializeDocument($doc, $client);
        }

        return $items;
    }

    private function serializeDocument(Document $doc, string $client): array {
        $case  = $this->caseMapper->findById($doc->getCaseId());
        $files = [];

        // Inbox documents without a case have no files in the virtual mount
        $docFiles = $case === null ? [] : $this->fileService->listByDocument($doc->getId(), $this->userId ?? '');

        foreach ($docFiles as $file) {
            $editorFile = $this->links->editorFile($file, $case, $client, $this->userId ?? '');
            if ($editorFile !== null) {
                $files[] = $editorFile;
            }
        }

        return [
            'id'             => $doc->getId(),
            'documentnumber' => $doc->getDocumentNumber(),
            'title'          => $doc->getTitle(),
            'url'            => $this->documentUrl($doc->getId()),
            'files'          => $files,
        ];
    }

    /**
     * One Elasticsearch file hit, or null when the file, its document or its
     * case is gone or no longer readable (the index can lag behind).
     */
    private function serializeSearchHit(array $hit, string $client): ?array {
        try {
            $file = $this->fileService->get((int)($hit['file_id'] ?? 0), $this->userId ?? '');
        } catch (NotFoundException) {
            return null;
        }
        $doc  = $this->documentMapper->findById($file->getDocumentId());
        $case = $this->caseMapper->findById($file->getCaseId());
        if ($doc === null || $doc->isSoftDeleted() || $case === null) {
            return null;
        }

        $editorFile = $this->links->editorFile($file, $case, $client, $this->userId ?? '');
        if ($editorFile === null) {
            return null;
        }

        return $editorFile + [
            'mimeType'   => $file->getMimeType(),
            'size'       => $file->getSize(),
            'updatedAt'  => $file->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'highlights' => array_map(
                fn($fragment) => $this->safeHighlight((string)$fragment),
                array_values($hit['_highlights']['attachment.content'] ?? []),
            ),
            'document'   => [
                'id'             => $doc->getId(),
                'documentnumber' => $doc->getDocumentNumber(),
                'title'          => $doc->getTitle(),
                'documentType'   => $doc->getDocumentType() ?? '',
                'url'            => $this->documentUrl($doc->getId()),
            ],
            'case'       => [
                'id'           => $case->getId(),
                'caseNumber'   => $case->getCaseNumber(),
                'title'        => $case->getTitle(),
                'organisation' => (string)($hit['organisation'] ?? ''),
                'year'         => (string)($hit['year'] ?? ''),
            ],
        ];
    }


    /**
     * Facet buckets as {value, label, count}; `value` is what goes back in
     * the matching filter parameter.
     */
    private function serializeBuckets(array $buckets): array {
        return array_map(fn(array $b) => [
            'value' => (string)$b['key'],
            'label' => (string)($b['label'] ?? $b['key']),
            'count' => (int)$b['doc_count'],
        ], array_values($buckets));
    }

    /**
     * Escape a highlight fragment (raw extracted file text) so clients can
     * render it as HTML; only the <mark> tags around matches survive.
     */
    private function safeHighlight(string $fragment): string {
        return str_replace(
            ['&lt;mark&gt;', '&lt;/mark&gt;'],
            ['<mark>', '</mark>'],
            htmlspecialchars($fragment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    private function documentUrl(int $documentId): string {
        return $this->links->appUrl('document/' . $documentId);
    }


    private function userLanguage(): string {
        $user = $this->userId !== null ? $this->userManager->get($this->userId) : null;
        return $user !== null ? $this->l10nFactory->getUserLanguage($user) : 'en';
    }


    private function intOrNull(mixed $value): ?int {
        return $value === null || $value === '' ? null : (int)$value;
    }

    private function stringOrNull(mixed $value): ?string {
        return is_scalar($value) && trim((string)$value) !== '' ? trim((string)$value) : null;
    }

}
