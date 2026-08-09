<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\AccessDecisionMapper;
use OCA\OpenCase\Db\AccessExclusionReasonMapper;
use OCA\OpenCase\Db\AccessRequestItemMapper;
use OCA\OpenCase\Db\AccessRequestMapper;
use OCA\OpenCase\Db\AccessRedactionMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\FileMapper;
use OCA\OpenCase\Service\AccessDecisionService;
use OCA\OpenCase\Service\AccessRequestService;
use OCA\OpenCase\Service\AccessSearchService;
use OCA\OpenCase\Service\AuditService;
use OCA\OpenCase\Service\PermissionService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

class AccessRequestController extends ApiController {

    use CaseAccessGuard;

    public function __construct(
        string $appName,
        IRequest $request,
        private AccessRequestService $requestService,
        private AccessRequestMapper $requestMapper,
        private AccessRequestItemMapper $itemMapper,
        private AccessRedactionMapper $redactionMapper,
        private AccessDecisionMapper $decisionMapper,
        private AccessExclusionReasonMapper $exclusionReasonMapper,
        private AccessDecisionService $decisionService,
        private AccessSearchService $searchService,
        private AuditService $auditService,
        private DocumentMapper $documentMapper,
        private FileMapper $fileMapper,
        private IConfig $config,
        private PermissionService $permissionService,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function index(int $caseId): DataResponse {
        if ($denied = $this->denyUnlessCaseRead($caseId)) {
            return $denied;
        }

        $requests = $this->requestMapper->findByCase($caseId);
        return new DataResponse([
            'access_requests' => array_map(
                fn($r) => $this->requestService->serialize($r),
                $requests,
            ),
        ]);
    }

    #[NoAdminRequired]
    public function create(int $caseId): DataResponse {
        if ($denied = $this->denyUnlessCaseWrite($caseId)) {
            return $denied;
        }

        $type    = $this->request->getParam('type', '');
        $subject = trim($this->request->getParam('subject', ''));

        if (empty($subject)) {
            return new DataResponse(['message' => 'subject is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $req = $this->requestService->create($caseId, $type, $subject, $this->userId, [
                'description'          => $this->request->getParam('description'),
                'requester_name'       => $this->request->getParam('requester_name'),
                'requester_email'      => $this->request->getParam('requester_email'),
                'requester_phone'      => $this->request->getParam('requester_phone'),
                'requester_identifier' => $this->request->getParam('requester_identifier'),
                'assigned_user'        => $this->request->getParam('assigned_user'),
                'legal_basis'          => $this->request->getParam('legal_basis'),
            ]);
            return new DataResponse(['access_request' => $this->requestService->serialize($req)], Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['message' => 'Case not found'], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    public function show(int $id): DataResponse {
        if ($denied = $this->denyUnlessRequestRead($id)) {
            return $denied;
        }

        try {
            $req      = $this->requestMapper->findById($id);
            $items    = $this->itemMapper->findByRequest($id);
            $decision = $this->decisionMapper->findByRequest($id);

            $itemData = [];
            foreach ($items as $item) {
                $redactions = $this->redactionMapper->findByItem($item->getId());
                $itemData[] = [
                    'id'                       => $item->getId(),
                    'request_id'               => $item->getRequestId(),
                    'source_type'              => $item->getSourceType(),
                    'source_id'                => $item->getSourceId(),
                    'title'                    => $item->getTitle(),
                    'mime_type'                => $item->getMimeType(),
                    'document_date'            => $item->getDocumentDate(),
                    'decision'                 => $item->getDecision(),
                    'exclusion_reason'         => $item->getExclusionReason(),
                    'legal_reference'          => $item->getLegalReference(),
                    'contains_personal_data'   => (bool)$item->getContainsPersonalData(),
                    'contains_sensitive_data'  => (bool)$item->getContainsSensitiveData(),
                    'contains_confidential_data' => (bool)$item->getContainsConfidentialData(),
                    'sort_order'               => $item->getSortOrder(),
                    'created_at'               => $item->getCreatedAt()?->format('c'),
                    'redactions'               => array_map(fn($r) => [
                        'id'               => $r->getId(),
                        'redaction_type'   => $r->getRedactionType(),
                        'original_text'    => $r->getOriginalText(),
                        'replacement_text' => $r->getReplacementText(),
                        'reason'           => $r->getReason(),
                        'legal_reference'  => $r->getLegalReference(),
                        'created_by'       => $r->getCreatedBy(),
                        'created_at'       => $r->getCreatedAt()?->format('c'),
                    ], $redactions),
                ];
            }

            return new DataResponse([
                'access_request' => $this->requestService->serialize($req),
                'items'          => $itemData,
                'decision'       => $decision ? $this->decisionService->serializeDecision($decision) : null,
            ]);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    public function update(int $id): DataResponse {
        if ($denied = $this->denyUnlessRequestWrite($id)) {
            return $denied;
        }

        try {
            $req = $this->requestService->update($id, $this->userId, $this->request->getParams());
            return new DataResponse(['access_request' => $this->requestService->serialize($req)]);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    public function changeStatus(int $id): DataResponse {
        if ($denied = $this->denyUnlessRequestWrite($id)) {
            return $denied;
        }

        $status = $this->request->getParam('status', '');
        try {
            $req = $this->requestService->changeStatus($id, $status, $this->userId);
            return new DataResponse(['access_request' => $this->requestService->serialize($req)]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    public function search(int $id): DataResponse {
        if ($denied = $this->denyUnlessRequestRead($id)) {
            return $denied;
        }

        try {
            $req        = $this->requestMapper->findById($id);
            $candidates = $this->searchService->searchCandidates($req->getCaseId(), $id, [
                'q'             => $this->request->getParam('q', ''),
                'date_from'     => $this->request->getParam('date_from'),
                'date_to'       => $this->request->getParam('date_to'),
                'document_type' => $this->request->getParam('document_type'),
            ]);
            return new DataResponse(['candidates' => $candidates]);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    public function addItem(int $id): DataResponse {
        if ($denied = $this->denyUnlessRequestWrite($id)) {
            return $denied;
        }

        $sourceType = $this->request->getParam('source_type', '');
        $sourceId   = (int)$this->request->getParam('source_id', 0);
        $title      = trim($this->request->getParam('title', ''));

        if (empty($title) || empty($sourceType) || $sourceId === 0) {
            return new DataResponse(['message' => 'source_type, source_id and title required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $req = $this->requestMapper->findById($id);

            // Idempotent — skip if already added
            $existing = $this->itemMapper->findBySourceAndRequest($id, $sourceType, $sourceId);
            if ($existing !== null) {
                return new DataResponse(['item' => $this->serializeItem($existing)]);
            }

            $item = new \OCA\OpenCase\Db\AccessRequestItem();
            $item->setRequestId($id);
            $item->setSourceType($sourceType);
            $item->setSourceId($sourceId);
            $item->setTitle($title);
            $item->setMimeType($this->request->getParam('mime_type'));
            $item->setDocumentDate($this->request->getParam('document_date'));
            $item->setDecision('pending');
            $item->setSortOrder((int)$this->request->getParam('sort_order', 0));
            $item->setCreatedAt(new \DateTime());

            $saved = $this->itemMapper->insert($item);
            $this->auditService->logAccessRequestUpdated($req->getCaseId(), $this->userId, $id);

            return new DataResponse(['item' => $this->serializeItem($saved)], Http::STATUS_CREATED);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    public function updateItem(int $itemId): DataResponse {
        if ($denied = $this->denyUnlessItemWrite($itemId)) {
            return $denied;
        }

        try {
            $item = $this->itemMapper->findById($itemId);
            $req  = $this->requestMapper->findById($item->getRequestId());

            $allowed = ['pending', 'include', 'exclude', 'partly_include'];
            if ($dec = $this->request->getParam('decision')) {
                if (!in_array($dec, $allowed, true)) {
                    return new DataResponse(['message' => 'Invalid decision'], Http::STATUS_BAD_REQUEST);
                }
                $item->setDecision($dec);
            }
            if (($v = $this->request->getParam('exclusion_reason')) !== null)  { $item->setExclusionReason($v); }
            if (($v = $this->request->getParam('legal_reference')) !== null)   { $item->setLegalReference($v); }
            if (($v = $this->request->getParam('contains_personal_data')) !== null)    { $item->setContainsPersonalData((int)$v); }
            if (($v = $this->request->getParam('contains_sensitive_data')) !== null)   { $item->setContainsSensitiveData((int)$v); }
            if (($v = $this->request->getParam('contains_confidential_data')) !== null){ $item->setContainsConfidentialData((int)$v); }

            $saved = $this->itemMapper->update($item);
            $this->auditService->logAccessRequestUpdated($req->getCaseId(), $this->userId, $item->getRequestId());

            return new DataResponse(['item' => $this->serializeItem($saved)]);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    public function removeItem(int $itemId): DataResponse {
        if ($denied = $this->denyUnlessItemWrite($itemId)) {
            return $denied;
        }

        try {
            $item = $this->itemMapper->findById($itemId);
            $req  = $this->requestMapper->findById($item->getRequestId());
            $this->redactionMapper->deleteByItem($itemId);
            $this->itemMapper->delete($item);
            $this->auditService->logAccessRequestUpdated($req->getCaseId(), $this->userId, $item->getRequestId());
            return new DataResponse([]);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    public function saveDecision(int $id): DataResponse {
        if ($denied = $this->denyUnlessRequestWrite($id)) {
            return $denied;
        }

        try {
            $dec = $this->decisionService->save($id, $this->userId, $this->request->getParams());
            return new DataResponse(['decision' => $this->decisionService->serializeDecision($dec)]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    public function approveDecision(int $id): DataResponse {
        if ($denied = $this->denyUnlessRequestWrite($id)) {
            return $denied;
        }

        try {
            $dec = $this->decisionService->approve($id, $this->userId);
            return new DataResponse(['decision' => $this->decisionService->serializeDecision($dec)]);
        } catch (\RuntimeException $e) {
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    public function decisionTemplates(): DataResponse {
        return new DataResponse(['templates' => $this->decisionService->getTemplates()]);
    }

    #[NoAdminRequired]
    public function exclusionReasons(): DataResponse {
        $reasons = $this->exclusionReasonMapper->findAll();
        return new DataResponse([
            'reasons' => array_map(fn($r) => ['id' => $r->getId(), 'label' => $r->getLabel()], $reasons),
        ]);
    }

    #[NoAdminRequired]
    public function addRedaction(int $itemId): DataResponse {
        if ($denied = $this->denyUnlessItemWrite($itemId)) {
            return $denied;
        }

        try {
            $item = $this->itemMapper->findById($itemId);
            $req  = $this->requestMapper->findById($item->getRequestId());

            $r = new \OCA\OpenCase\Db\AccessRedaction();
            $r->setRequestItemId($itemId);
            $r->setRedactionType($this->request->getParam('redaction_type', 'manual'));
            $r->setOriginalText($this->request->getParam('original_text'));
            $r->setReplacementText($this->request->getParam('replacement_text', '████'));
            $r->setReason($this->request->getParam('reason'));
            $r->setLegalReference($this->request->getParam('legal_reference'));
            $r->setCreatedBy($this->userId);
            $r->setCreatedAt(new \DateTime());

            $saved = $this->redactionMapper->insert($r);
            $this->auditService->logAccessRequestUpdated($req->getCaseId(), $this->userId, $item->getRequestId());

            return new DataResponse([
                'redaction' => [
                    'id'               => $saved->getId(),
                    'request_item_id'  => $saved->getRequestItemId(),
                    'redaction_type'   => $saved->getRedactionType(),
                    'original_text'    => $saved->getOriginalText(),
                    'replacement_text' => $saved->getReplacementText(),
                    'reason'           => $saved->getReason(),
                    'legal_reference'  => $saved->getLegalReference(),
                    'created_by'       => $saved->getCreatedBy(),
                    'created_at'       => $saved->getCreatedAt()?->format('c'),
                ],
            ], Http::STATUS_CREATED);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    public function removeRedaction(int $redactionId): DataResponse {
        if ($denied = $this->denyUnlessRedactionWrite($redactionId)) {
            return $denied;
        }

        try {
            $r    = $this->redactionMapper->findById($redactionId);
            $item = $this->itemMapper->findById($r->getRequestItemId());
            $req  = $this->requestMapper->findById($item->getRequestId());
            $this->redactionMapper->delete($r);
            $this->auditService->logAccessRequestUpdated($req->getCaseId(), $this->userId, $item->getRequestId());
            return new DataResponse([]);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    public function maskingFiles(int $id): DataResponse {
        if ($denied = $this->denyUnlessRequestRead($id)) {
            return $denied;
        }

        try {
            $this->requestMapper->findById($id);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        $items = $this->itemMapper->findByRequest($id);
        $rows  = [];

        foreach ($items as $item) {
            if ($item->getSourceType() !== 'document') {
                continue;
            }
            if (!in_array($item->getDecision(), ['include', 'partly_include'], true)) {
                continue;
            }
            try {
                $doc   = $this->documentMapper->findById($item->getSourceId());
                $files = $this->fileMapper->findByDocument($item->getSourceId());
                foreach ($files as $file) {
                    $maskedPath = $this->maskedPath($id, $item->getId(), $file->getId(), $file->getOriginalFilename());
                    $rows[] = [
                        'item_id'         => $item->getId(),
                        'file_id'         => $file->getId(),
                        'document_number' => $doc->getDocumentNumber(),
                        'document_title'  => $doc->getTitle(),
                        'file_name'       => $file->getOriginalFilename(),
                        'mime_type'       => $file->getMimeType(),
                        'has_masked'      => file_exists($maskedPath),
                    ];
                }
            } catch (\Throwable) {
                // Skip missing documents / files
            }
        }

        return new DataResponse(['files' => $rows]);
    }

    #[NoAdminRequired]
    public function uploadMasked(int $itemId, int $fileId): DataResponse {
        if ($denied = $this->denyUnlessItemWrite($itemId)) {
            return $denied;
        }

        try {
            $item         = $this->itemMapper->findById($itemId);
            $originalFile = $this->fileMapper->findById($fileId);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        $uploaded = $this->request->getUploadedFile('file');
        if (empty($uploaded['tmp_name'])) {
            return new DataResponse(['message' => 'No file uploaded'], Http::STATUS_BAD_REQUEST);
        }

        $dir = $this->maskedDir($item->getRequestId(), $itemId);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $dest = $this->maskedPath($item->getRequestId(), $itemId, $fileId, $originalFile->getOriginalFilename());
        move_uploaded_file($uploaded['tmp_name'], $dest);

        return new DataResponse(['has_masked' => true]);
    }

    #[NoAdminRequired]
    public function downloadMasked(int $itemId, int $fileId): DataDownloadResponse|DataResponse {
        if ($denied = $this->denyUnlessItemRead($itemId)) {
            return $denied;
        }

        try {
            $item         = $this->itemMapper->findById($itemId);
            $originalFile = $this->fileMapper->findById($fileId);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        $path = $this->maskedPath($item->getRequestId(), $itemId, $fileId, $originalFile->getOriginalFilename());
        if (!file_exists($path)) {
            return new DataResponse(['message' => 'No masked file exists'], Http::STATUS_NOT_FOUND);
        }

        $content = file_get_contents($path);
        return new DataDownloadResponse(
            $content,
            'maskeret_' . $originalFile->getOriginalFilename(),
            $originalFile->getMimeType() ?? 'application/octet-stream',
        );
    }

    // ── Access resolution ────────────────────────────────────────────────
    //
    // Every endpoint here is addressed by a request, item or redaction id, so
    // the case each one hangs off has to be walked back to before the caller's
    // access can be checked. A missing row is reported as 404 and a case the
    // caller cannot reach as 403.

    private function denyUnlessRequestRead(int $requestId): ?DataResponse {
        return $this->resolved($this->caseIdForRequest($requestId), false);
    }

    private function denyUnlessRequestWrite(int $requestId): ?DataResponse {
        return $this->resolved($this->caseIdForRequest($requestId), true);
    }

    private function denyUnlessItemRead(int $itemId): ?DataResponse {
        return $this->resolved($this->caseIdForItem($itemId), false);
    }

    private function denyUnlessItemWrite(int $itemId): ?DataResponse {
        return $this->resolved($this->caseIdForItem($itemId), true);
    }

    private function denyUnlessRedactionWrite(int $redactionId): ?DataResponse {
        return $this->resolved($this->caseIdForRedaction($redactionId), true);
    }

    private function resolved(?int $caseId, bool $needsWrite): ?DataResponse {
        if ($caseId === null) {
            return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        return $needsWrite
            ? $this->denyUnlessCaseWrite($caseId)
            : $this->denyUnlessCaseRead($caseId);
    }

    private function caseIdForRequest(int $requestId): ?int {
        try {
            return $this->requestMapper->findById($requestId)->getCaseId();
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return null;
        }
    }

    private function caseIdForItem(int $itemId): ?int {
        try {
            return $this->caseIdForRequest($this->itemMapper->findById($itemId)->getRequestId());
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return null;
        }
    }

    private function caseIdForRedaction(int $redactionId): ?int {
        try {
            return $this->caseIdForItem($this->redactionMapper->findById($redactionId)->getRequestItemId());
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return null;
        }
    }

    private function maskedDir(int $requestId, int $itemId): string {
        $dataDir = rtrim($this->config->getSystemValueString('datadirectory', ''), '/');
        return "{$dataDir}/opencase_storage/_accessrequests/{$requestId}";
    }

    private function maskedPath(int $requestId, int $itemId, int $fileId, string $filename): string {
        $safe = preg_replace('/[^a-zA-Z0-9æøåÆØÅ._\-]/', '_', basename($filename));
        return $this->maskedDir($requestId, $itemId) . "/{$itemId}_{$fileId}_{$safe}";
    }

    private function serializeItem(\OCA\OpenCase\Db\AccessRequestItem $item): array {
        return [
            'id'                        => $item->getId(),
            'request_id'                => $item->getRequestId(),
            'source_type'               => $item->getSourceType(),
            'source_id'                 => $item->getSourceId(),
            'title'                     => $item->getTitle(),
            'mime_type'                 => $item->getMimeType(),
            'document_date'             => $item->getDocumentDate(),
            'decision'                  => $item->getDecision(),
            'exclusion_reason'          => $item->getExclusionReason(),
            'legal_reference'           => $item->getLegalReference(),
            'contains_personal_data'    => (bool)$item->getContainsPersonalData(),
            'contains_sensitive_data'   => (bool)$item->getContainsSensitiveData(),
            'contains_confidential_data' => (bool)$item->getContainsConfidentialData(),
            'sort_order'                => $item->getSortOrder(),
            'created_at'                => $item->getCreatedAt()?->format('c'),
        ];
    }
}
