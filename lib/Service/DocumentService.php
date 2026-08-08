<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\Document;
use OCA\OpenCase\Service\CaseService;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Service\AuditService;
use OCA\OpenCase\Service\CaseNumberService;
use Psr\Log\LoggerInterface;

/**
 * Business logic for document management within cases.
 */
class DocumentService {

    public function __construct(
        private DocumentMapper $documentMapper,
        private CaseMapper $caseMapper,
        private PermissionService $permissionService,
        private CaseNumberService $caseNumberService,
        private AuditService $auditService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Create a new document within a case.
     */
    public function create(
        int $caseId,
        string $title,
        ?string $documentType,
        string $userId,
        ?int $insightLevelId = null,
        ?string $documentDate = null,
        ?string $receivedDate = null,
        ?string $registeredDate = null,
        ?int $documentCategoryId = null,
    ): Document {
        // Verify case exists and user has write access
        $case = $this->caseMapper->findById($caseId);
        if ($case === null) {
            throw new NotFoundException("Case {$caseId} not found");
        }
        if (!$this->permissionService->userHasWriteAccessToCase($userId, $caseId)) {
            throw new NotFoundException('Access denied');
        }

        if (trim($title) === '') {
            throw new \InvalidArgumentException('Document title is required');
        }

        if ($documentCategoryId === null) {
            throw new \InvalidArgumentException('Document category is required');
        }

        $documentNumber = $this->caseNumberService->generateDocumentNumber(
            $caseId,
            $case->getCaseNumber()
        );

        $entity = new Document();
        $entity->setCaseId($caseId);
        $entity->setDocumentNumber($documentNumber);
        $entity->setTitle($title);
        $entity->setDocumentType($documentType);
        $entity->setStatus(1);
        $entity->setInsightLevelId($insightLevelId);
        $entity->setDocumentDate($documentDate ?? (new \DateTime())->format('Y-m-d'));
        $entity->setReceivedDate($receivedDate);
        $entity->setRegisteredDate($registeredDate);
        $entity->setDocumentCategoryId($documentCategoryId);
        $entity->setCreatedAt(new \DateTime());
        $entity->setUpdatedAt(new \DateTime());
        $entity->setCreatedBy($userId);
        $entity->setUuid(CaseService::generateUuid());

        $result = $this->documentMapper->insert($entity);

        // Update case timestamp
        $case->setUpdatedAt(new \DateTime());
        $this->caseMapper->update($case);

        $this->auditService->logDocumentCreated(
            $caseId,
            $userId,
            $result->getId(),
            $result->getDocumentNumber() ?? '',
            $result->getTitle(),
        );

        return $result;
    }

    /**
     * Update document metadata.
     */
    public function update(
        int $documentId,
        ?string $title,
        ?string $documentType,
        ?int $status,
        string $userId,
        ?int $insightLevelId = null,
        bool $insightLevelProvided = false,
        ?string $documentDate = null,
        bool $documentDateProvided = false,
        ?string $receivedDate = null,
        bool $receivedDateProvided = false,
        ?string $registeredDate = null,
        bool $registeredDateProvided = false,
    ): Document {
        $entity = $this->documentMapper->findById($documentId);
        if ($entity === null) {
            throw new NotFoundException("Document {$documentId} not found");
        }

        if (!$this->permissionService->userHasWriteAccessToDocument($userId, $documentId)) {
            throw new NotFoundException('Access denied');
        }

        $metadataChanges = [];

        if ($title !== null) {
            if ($title !== $entity->getTitle()) {
                $metadataChanges['title'] = ['from' => $entity->getTitle(), 'to' => $title];
            }
            $entity->setTitle($title);
        }
        if ($documentType !== null) {
            if ($documentType !== $entity->getDocumentType()) {
                $metadataChanges['document_type'] = ['from' => $entity->getDocumentType(), 'to' => $documentType];
            }
            $entity->setDocumentType($documentType);
        }

        if ($insightLevelProvided && $insightLevelId !== $entity->getInsightLevelId()) {
            $metadataChanges['insight_level_id'] = ['from' => $entity->getInsightLevelId(), 'to' => $insightLevelId];
            $entity->setInsightLevelId($insightLevelId);
        }

        if ($documentDateProvided) {
            $entity->setDocumentDate($documentDate);
        }
        if ($receivedDateProvided) {
            $entity->setReceivedDate($receivedDate);
        }
        if ($registeredDateProvided) {
            $entity->setRegisteredDate($registeredDate);
        }

        $oldStatusId = $entity->getStatus();
        if ($status !== null) {
            if (!in_array($status, [1, 2, 3], true)) {
                throw new \InvalidArgumentException("Invalid status ID: {$status}");
            }
            $entity->setStatus($status);
        }

        $entity->setUpdatedAt(new \DateTime());

        $result = $this->documentMapper->update($entity);

        $caseId = $entity->getCaseId();
        $this->auditService->logDocumentMetadataChanged($caseId, $documentId, $userId, $metadataChanges);
        if ($status !== null && $status !== $oldStatusId) {
            $this->auditService->logDocumentStatusChanged($caseId, $documentId, $userId, $oldStatusId, $status);
        }

        return $result;
    }

    /**
     * Whether the user has write access to this document (case-level access,
     * a per-document override, or a document/file-level write share).
     */
    public function canWrite(int $documentId, string $userId): bool {
        return $this->permissionService->userHasWriteAccessToDocument($userId, $documentId);
    }

    /**
     * Get a single document with permission check.
     *
     * @param bool $audit Whether to record a document_viewed audit/history
     *                    entry. Set to false when the document is fetched
     *                    incidentally rather than actually being viewed.
     */
    public function get(int $documentId, string $userId, bool $audit = true): Document {
        $entity = $this->documentMapper->findById($documentId);
        if ($entity === null) {
            throw new NotFoundException("Document {$documentId} not found");
        }

        return $this->checkReadAccessAndAudit($entity, $userId, $audit);
    }

    /**
     * Get a single document by UUID with permission check.
     */
    public function getByUuid(string $uuid, string $userId): Document {
        $entity = $this->documentMapper->findByUuid($uuid);
        if ($entity === null) {
            throw new NotFoundException("Document with uuid {$uuid} not found");
        }

        return $this->checkReadAccessAndAudit($entity, $userId);
    }

    /**
     * Get a single document by document number with permission check.
     */
    public function getByDocumentNumber(string $documentNumber, string $userId): Document {
        $entity = $this->documentMapper->findByDocumentNumber($documentNumber);
        if ($entity === null) {
            throw new NotFoundException("Document with document number {$documentNumber} not found");
        }

        return $this->checkReadAccessAndAudit($entity, $userId);
    }

    private function checkReadAccessAndAudit(Document $entity, string $userId, bool $audit = true): Document {
        $documentId = $entity->getId();

        if (!$this->permissionService->userHasReadAccessToDocument($userId, $documentId)) {
            throw new NotFoundException('Access denied');
        }

        if ($audit) {
            $this->auditService->logDocumentViewed($entity->getCaseId(), $documentId, $userId);
        }

        return $entity;
    }

    /**
     * List all documents in a case (with access checks).
     *
     * @return Document[]
     */
    public function listByCase(int $caseId, string $userId): array {
        // Verify case-level read access
        if (!$this->permissionService->userHasReadAccessToCase($userId, $caseId)) {
            throw new NotFoundException('Access denied');
        }

        $documents = $this->documentMapper->findByCase($caseId);

        // Filter out documents with per-user deny overrides
        return array_values(array_filter($documents, function (Document $doc) use ($userId) {
            return $this->permissionService->userHasReadAccessToDocument($userId, $doc->getId());
        }));
    }

    /**
     * Delete a document and all its files.
     * Only allowed for draft documents.
     */
    public function delete(int $documentId, string $userId): void {
        $entity = $this->documentMapper->findById($documentId);
        if ($entity === null) {
            throw new NotFoundException("Document {$documentId} not found");
        }

        if (!$this->permissionService->userHasWriteAccessToDocument($userId, $documentId)) {
            throw new NotFoundException('Access denied');
        }

        if ($entity->getStatus() !== 1) {
            throw new \InvalidArgumentException(
                'Only draft documents can be deleted. Change status to draft first.'
            );
        }

        // Deletion of associated files should be handled here
        // (physical files + ES index entries)
        // For now, delete the DB entity
        $this->documentMapper->delete($entity);
    }

    /**
     * List documents from inbox cases accessible to the user.
     *
     * @return array{documents: array[], total: int}
     */
    public function listInboxDocuments(string $userId, int $limit = 50, int $offset = 0): array {
        $inboxCaseIds = $this->caseMapper->findInboxCaseIdsForUser($userId);

        $documents = $this->documentMapper->findFromInboxCases($inboxCaseIds, $limit, $offset);
        $total     = $this->documentMapper->countFromInboxCases($inboxCaseIds);

        return ['documents' => $documents, 'total' => $total];
    }

    /**
     * Field-based document search across all cases accessible to the user.
     *
     * All filter parameters are optional; pass null to skip that filter.
     *
     * @return array{documents: array[], total: int}
     */
    public function searchDocuments(
        string $userId,
        ?string $title = null,
        ?string $documentType = null,
        ?int $status = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $orgName = null,
        int $limit = 50,
        int $offset = 0,
        ?int $documentCategoryId = null,
        ?int $insightLevelId = null,
        ?string $createdBy = null,
    ): array {
        $rows  = $this->documentMapper->findByFields(
            $userId,
            $title, $documentType, $status, $dateFrom, $dateTo, $orgName,
            $limit, $offset, $documentCategoryId, $insightLevelId, $createdBy,
        );
        $total = $this->documentMapper->countByFields(
            $userId,
            $title, $documentType, $status, $dateFrom, $dateTo, $orgName,
            $documentCategoryId, $insightLevelId, $createdBy,
        );

        return ['documents' => $rows, 'total' => $total];
    }

    /**
     * Move an inbox document to an existing non-inbox case and assign it a new document number.
     *
     * @throws NotFoundException    if the document, source case or target case is not found / inaccessible
     * @throws \InvalidArgumentException if the source case is not an inbox case
     */
    public function moveToCase(int $documentId, string $targetCaseNumber, string $userId): Document {
        $entity = $this->documentMapper->findById($documentId);
        if ($entity === null) {
            throw new NotFoundException("Document {$documentId} not found");
        }

        $sourceCase = $this->caseMapper->findById($entity->getCaseId());
        if ($sourceCase === null) {
            throw new NotFoundException('Source case not found');
        }
        if (!$sourceCase->getIsInbox()) {
            throw new \InvalidArgumentException('Only documents on inbox cases can be moved to another case');
        }

        $targetCase = $this->caseMapper->findByCaseNumber($targetCaseNumber);
        if ($targetCase === null) {
            throw new NotFoundException("Case {$targetCaseNumber} not found");
        }
        if ($targetCase->getIsInbox()) {
            throw new \InvalidArgumentException('Cannot move to an inbox case');
        }
        if (!$this->permissionService->userHasWriteAccessToCase($userId, $targetCase->getId())) {
            throw new NotFoundException('Access denied to target case');
        }

        $newDocumentNumber = $this->caseNumberService->generateDocumentNumber(
            $targetCase->getId(),
            $targetCase->getCaseNumber()
        );

        $entity->setCaseId($targetCase->getId());
        $entity->setDocumentNumber($newDocumentNumber);
        $entity->setUpdatedAt(new \DateTime());

        $this->documentMapper->update($entity);

        $targetCase->setUpdatedAt(new \DateTime());
        $this->caseMapper->update($targetCase);

        $this->auditService->logDocumentMovedToCase(
            $targetCase->getId(),
            $entity->getId(),
            $userId,
            $sourceCase->getCaseNumber(),
            $targetCase->getCaseNumber(),
            $newDocumentNumber,
        );

        return $entity;
    }

    /**
     * Grant or modify per-user access override on a document.
     */
    public function setUserAccess(
        int $documentId,
        string $targetUserId,
        string $accessLevel,
        string $grantedBy,
    ): void {
        $entity = $this->documentMapper->findById($documentId);
        if ($entity === null) {
            throw new NotFoundException("Document {$documentId} not found");
        }

        // Only users with write access to the parent case can manage document permissions
        if (!$this->permissionService->userHasWriteAccessToCase($grantedBy, $entity->getCaseId())) {
            throw new NotFoundException('Access denied');
        }

        $validLevels = ['read', 'write', 'deny'];
        if (!in_array($accessLevel, $validLevels)) {
            throw new \InvalidArgumentException("Invalid access level: {$accessLevel}");
        }

        $this->permissionService->setDocumentUserOverride(
            $documentId, $targetUserId, $accessLevel, $grantedBy
        );
    }
}
