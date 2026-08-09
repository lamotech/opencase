<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\FileMapper;
use OCA\OpenCase\Service\AuditService;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\PermissionService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserManager;

/**
 * REST API controller for the audit log.
 *
 * Routes:
 *   GET /api/v1/cases/{caseId}/audit-log        — Case-level audit entries
 *   GET /api/v1/documents/{documentId}/audit-log — Document-level audit entries
 *   GET /api/v1/files/{fileId}/audit-log         — File-level audit entries
 *
 * Query params: limit (default 25, max 500), offset (default 0)
 */
class AuditController extends ApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private AuditService $auditService,
        private PermissionService $permissionService,
        private DocumentMapper $documentMapper,
        private FileMapper $fileMapper,
        private IUserManager $userManager,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Return case-level audit entries plus document_created events, newest first.
     */
    #[NoAdminRequired]
    public function index(int $caseId): DataResponse {
        if (!$this->permissionService->userHasReadAccessToCase($this->userId ?? '', $caseId)) {
            return new DataResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
        }

        [$limit, $offset] = $this->paginationParams();

        $entries = $this->auditService->findByCase($caseId, $limit, $offset);
        $total   = $this->auditService->countByCase($caseId);

        return new DataResponse([
            'entries' => array_map([$this, 'serialize'], $entries),
            'total'   => $total,
        ]);
    }

    /**
     * Return all audit entries for a document, newest first.
     */
    #[NoAdminRequired]
    public function indexByDocument(int $documentId): DataResponse {
        $document = $this->documentMapper->findById($documentId);
        if ($document === null) {
            return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        if (!$this->permissionService->userHasReadAccessToDocument($this->userId ?? '', $documentId)) {
            return new DataResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
        }

        [$limit, $offset] = $this->paginationParams();

        $entries = $this->auditService->findByDocument($documentId, $limit, $offset);
        $total   = $this->auditService->countByDocument($documentId);

        return new DataResponse([
            'entries' => array_map([$this, 'serialize'], $entries),
            'total'   => $total,
        ]);
    }

    /**
     * Return all audit entries for a file, newest first.
     */
    #[NoAdminRequired]
    public function indexByFile(int $fileId): DataResponse {
        $file = $this->fileMapper->findById($fileId);
        if ($file === null) {
            return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        if (!$this->permissionService->userHasReadAccessToDocument($this->userId ?? '', $file->getDocumentId())) {
            return new DataResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
        }

        [$limit, $offset] = $this->paginationParams();

        $entries = $this->auditService->findByFile($fileId, $limit, $offset);
        $total   = $this->auditService->countByFile($fileId);

        return new DataResponse([
            'entries' => array_map([$this, 'serialize'], $entries),
            'total'   => $total,
        ]);
    }

    private function paginationParams(): array {
        $limit  = min((int)($this->request->getParam('limit', '25')), 500);
        $offset = max((int)($this->request->getParam('offset', '0')), 0);
        return [$limit, $offset];
    }

    private function serialize($entry): array {
        $uid         = $entry->getUserId();
        $displayName = $this->userManager->get($uid)?->getDisplayName() ?? $uid;

        return [
            'id'                => $entry->getId(),
            'case_id'           => $entry->getCaseId(),
            'user_id'           => $uid,
            'user_display_name' => $displayName,
            'event_type'        => $entry->getEventType(),
            'details'           => $entry->getDetailsArray(),
            'created_at'        => $entry->getCreatedAt()?->format('c'),
        ];
    }
}
