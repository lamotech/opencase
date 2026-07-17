<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\CaseEntity;
use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\FileEntity;
use OCA\OpenCase\Db\FileMapper;
use OCA\OpenCase\Db\FileShareEntity;
use OCA\OpenCase\Db\FileShareMapper;
use OCA\OpenCase\Db\FileVersionEntity;
use OCA\OpenCase\Db\FileVersionMapper;
use OCA\OpenCase\Event\FileUpdatedEvent;
use OCA\OpenCase\Service\AuditService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Business logic for file management.
 *
 * Handles upload, download, and metadata management for files
 * attached to documents. Manages physical storage on disk and
 * dispatches indexing events.
 */
class FileService {

    private string $baseDir;

    public function __construct(
        private FileMapper $fileMapper,
        private DocumentMapper $documentMapper,
        private CaseMapper $caseMapper,
        private FileVersionMapper $fileVersionMapper,
        private FileShareMapper $fileShareMapper,
        private PermissionService $permissionService,
        private AuditService $auditService,
        private ElasticsearchService $esService,
        private IEventDispatcher $eventDispatcher,
        private IConfig $config,
        private IDBConnection $db,
        private LoggerInterface $logger,
        private IRootFolder $rootFolder,
    ) {
        $defaultDir    = rtrim($config->getSystemValueString('datadirectory', ''), '/') . '/opencase_storage';
        $this->baseDir = rtrim($config->getAppValue('opencase', 'storage_base_dir', $defaultDir), '/');
    }

    /**
     * Upload a file and attach it to a document.
     *
     * @param resource|string $content File content (stream or string)
     * @return FileEntity The created file entity
     */
    public function upload(
        int $documentId,
        string $originalFilename,
        string $mimeType,
        mixed $content,
        string $userId,
    ): FileEntity {
        // Resolve document and case
        $document = $this->documentMapper->findById($documentId);
        if ($document === null) {
            throw new NotFoundException("Document {$documentId} not found");
        }

        $caseId = $document->getCaseId();

        // Permission check
        if (!$this->permissionService->userHasWriteAccessToDocument($userId, $documentId)) {
            throw new NotFoundException('Access denied');
        }

        $case = $this->caseMapper->findById($caseId);
        if ($case === null) {
            throw new NotFoundException("Case {$caseId} not found");
        }

        // Generate UUID-based storage filename to avoid collisions
        $extension       = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $storageFilename = $this->generateUuid() . ($extension ? '.' . $extension : '');

        // Generate virtual filename (visible in the virtual filesystem)
        $virtualFilename = $this->fileMapper->generateUniqueVirtualFilename(
            $caseId, $originalFilename
        );

        // Build a temporary FileEntity just to compute the physical path
        $tempEntity = new FileEntity();
        $tempEntity->setCaseId($caseId);
        $tempEntity->setDocumentId($documentId);
        $tempEntity->setStorageFilename($storageFilename);

        $physicalPath = $this->buildPhysicalPath($tempEntity, $case);
        $physicalDir  = dirname($physicalPath);
        if (!is_dir($physicalDir) && !mkdir($physicalDir, 0770, true) && !is_dir($physicalDir)) {
            throw new \RuntimeException("Failed to create directory {$physicalDir} — check ownership/permissions of the storage base dir");
        }

        if (is_resource($content)) {
            $target = fopen($physicalPath, 'wb');
            if ($target === false) {
                throw new \RuntimeException("Failed to open {$physicalPath} for writing");
            }
            stream_copy_to_stream($content, $target);
            fclose($target);
        } else {
            file_put_contents($physicalPath, $content);
        }

        $fileSize = filesize($physicalPath);
        $checksum = hash_file('sha256', $physicalPath);

        // Create DB entity
        $entity = new FileEntity();
        $entity->setDocumentId($documentId);
        $entity->setCaseId($caseId);
        $entity->setOriginalFilename($originalFilename);
        $entity->setStorageFilename($storageFilename);
        $entity->setVirtualFilename($virtualFilename);
        $entity->setMimeType($mimeType);
        $entity->setSize($fileSize);
        $entity->setVersion(1);
        $entity->setChecksum($checksum);
        $entity->setCreatedAt(new \DateTime());
        $entity->setUpdatedAt(new \DateTime());
        $entity->setCreatedBy($userId);
        $entity->setLastModifiedBy($userId);

        $entity = $this->fileMapper->insert($entity);

        // Update parent timestamps
        $document->setUpdatedAt(new \DateTime());
        $this->documentMapper->update($document);

        $case->setUpdatedAt(new \DateTime());
        $this->caseMapper->update($case);

        // Populate oc_filecache so Collabora/WOPI can resolve the file by ID
        $this->ensureFilecacheEntry($entity, $case);

        // Propagate existing document-level shares to this new file so users who
        // received a document share before this upload can still open the new file
        // via the virtual filesystem (/f/{id} → OpenCaseCacheWrapper).
        $this->propagateDocumentShares($entity, $documentId);

        // Enforce read-only ceiling if document is Final
        if ($this->isDocumentFinal($documentId)) {
            $this->updateFilecachePermissionsForDocument($documentId, false);
        }

        // Trigger ES indexing
        $this->eventDispatcher->dispatchTyped(new FileUpdatedEvent($entity->getId()));

        $this->logger->info('OpenCase: File uploaded: {filename} to document {docId} in case {caseId}', [
            'filename' => $originalFilename,
            'docId' => $documentId,
            'caseId' => $caseId,
        ]);

        $this->auditService->logFileUploaded($caseId, $documentId, $entity->getId(), $userId, $originalFilename);

        return $entity;
    }

    /**
     * Upload a new version of an existing file.
     *
     * Replaces the physical file and increments the version counter.
     * The old version is not kept (implement versioning backend if needed).
     *
     * @param resource|string $content
     */
    public function uploadNewVersion(
        int $fileId,
        mixed $content,
        string $userId,
    ): FileEntity {
        $entity = $this->fileMapper->findById($fileId);
        if ($entity === null) {
            throw new NotFoundException("File {$fileId} not found");
        }

        if (!$this->permissionService->userHasWriteAccessToDocument($userId, $entity->getDocumentId())) {
            throw new NotFoundException('Access denied');
        }

        $case = $this->caseMapper->findById($entity->getCaseId());
        if ($case === null) {
            throw new NotFoundException("Case {$entity->getCaseId()} not found");
        }

        $physicalPath = $this->buildPhysicalPath($entity, $case);
        $physicalDir  = dirname($physicalPath);
        if (!is_dir($physicalDir) && !mkdir($physicalDir, 0770, true) && !is_dir($physicalDir)) {
            throw new \RuntimeException("Failed to create directory {$physicalDir} — check ownership/permissions of the storage base dir");
        }

        // Snapshot current file as a version before overwriting
        if (file_exists($physicalPath)) {
            $this->snapshotVersion($entity, $case, $physicalPath);
        }

        // Write new content
        if (is_resource($content)) {
            $target = fopen($physicalPath, 'wb');
            if ($target === false) {
                throw new \RuntimeException("Failed to open {$physicalPath} for writing");
            }
            stream_copy_to_stream($content, $target);
            fclose($target);
        } else {
            file_put_contents($physicalPath, $content);
        }

        // Update metadata
        $entity->setSize(filesize($physicalPath));
        $entity->setChecksum(hash_file('sha256', $physicalPath));
        $entity->setVersion($entity->getVersion() + 1);
        $entity->setUpdatedAt(new \DateTime());
        $entity->setLastModifiedBy($userId);

        $entity = $this->fileMapper->update($entity);

        // Update oc_filecache size/mtime so Collabora sees the new content
        $this->ensureFilecacheEntry($entity, $case, true);

        // Re-apply permissions ceiling in case document is Final
        if ($this->isDocumentFinal($entity->getDocumentId())) {
            $this->updateFilecachePermissionsForDocument($entity->getDocumentId(), false);
        }

        // Trigger re-indexing
        $this->eventDispatcher->dispatchTyped(new FileUpdatedEvent($fileId));

        return $entity;
    }

    /**
     * Get file content as a stream for download.
     *
     * @return array{entity: FileEntity, stream: resource}
     */
    public function download(int $fileId, string $userId): array {
        $entity = $this->fileMapper->findById($fileId);
        if ($entity === null) {
            throw new NotFoundException("File {$fileId} not found");
        }

        if (!$this->permissionService->userHasReadAccessToDocument($userId, $entity->getDocumentId())
            && $this->permissionService->getFileSharePermissionsForRecipient($userId, $fileId) === 0
        ) {
            throw new NotFoundException('Access denied');
        }

        $case = $this->caseMapper->findById($entity->getCaseId());
        if ($case === null) {
            throw new NotFoundException("Case {$entity->getCaseId()} not found");
        }

        $physicalPath = $this->buildPhysicalPath($entity, $case);

        if (!file_exists($physicalPath)) {
            throw new NotFoundException('Physical file not found');
        }

        $stream = fopen($physicalPath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("Failed to open file for reading");
        }

        return [
            'entity' => $entity,
            'stream' => $stream,
        ];
    }

    /**
     * Copy all files from one document to another.
     *
     * Physical files are duplicated on disk; new DB + filecache entries are
     * created for the target document. Used when copying a case.
     *
     * @return FileEntity[] The newly created file entities
     */
    public function copyFilesToDocument(int $sourceDocumentId, int $targetDocumentId, string $userId): array {
        $sourceFiles = $this->fileMapper->findByDocument($sourceDocumentId);
        if (empty($sourceFiles)) {
            return [];
        }

        $targetDocument = $this->documentMapper->findById($targetDocumentId);
        if ($targetDocument === null) {
            throw new NotFoundException("Target document {$targetDocumentId} not found");
        }

        $targetCase = $this->caseMapper->findById($targetDocument->getCaseId());
        if ($targetCase === null) {
            throw new NotFoundException("Target case not found");
        }

        $created = [];
        foreach ($sourceFiles as $sourceFile) {
            $sourceCase = $this->caseMapper->findById($sourceFile->getCaseId());
            if ($sourceCase === null) {
                continue;
            }

            $sourcePath = $this->buildPhysicalPath($sourceFile, $sourceCase);
            if (!file_exists($sourcePath)) {
                continue;
            }

            $extension       = pathinfo($sourceFile->getOriginalFilename(), PATHINFO_EXTENSION);
            $storageFilename = $this->generateUuid() . ($extension ? '.' . $extension : '');

            $virtualFilename = $this->fileMapper->generateUniqueVirtualFilename(
                $targetDocument->getCaseId(), $sourceFile->getOriginalFilename()
            );

            $tempEntity = new FileEntity();
            $tempEntity->setCaseId($targetDocument->getCaseId());
            $tempEntity->setDocumentId($targetDocumentId);
            $tempEntity->setStorageFilename($storageFilename);

            $targetPath = $this->buildPhysicalPath($tempEntity, $targetCase);
            $targetDir  = dirname($targetPath);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0770, true) && !is_dir($targetDir)) {
                continue;
            }

            if (!copy($sourcePath, $targetPath)) {
                continue;
            }

            $entity = new FileEntity();
            $entity->setDocumentId($targetDocumentId);
            $entity->setCaseId($targetDocument->getCaseId());
            $entity->setOriginalFilename($sourceFile->getOriginalFilename());
            $entity->setStorageFilename($storageFilename);
            $entity->setVirtualFilename($virtualFilename);
            $entity->setMimeType($sourceFile->getMimeType());
            $entity->setSize(filesize($targetPath));
            $entity->setVersion(1);
            $entity->setChecksum(hash_file('sha256', $targetPath));
            $entity->setCreatedAt(new \DateTime());
            $entity->setUpdatedAt(new \DateTime());
            $entity->setCreatedBy($userId);
            $entity->setLastModifiedBy($userId);

            $entity  = $this->fileMapper->insert($entity);
            $created[] = $entity;

            $this->ensureFilecacheEntry($entity, $targetCase);
            $this->eventDispatcher->dispatchTyped(new FileUpdatedEvent($entity->getId()));
        }

        return $created;
    }

    /**
     * Get file metadata with permission check.
     */
    public function get(int $fileId, string $userId): FileEntity {
        $entity = $this->fileMapper->findById($fileId);
        if ($entity === null) {
            throw new NotFoundException("File {$fileId} not found");
        }

        if (!$this->permissionService->userHasReadAccessToDocument($userId, $entity->getDocumentId())
            && $this->permissionService->getFileSharePermissionsForRecipient($userId, $fileId) === 0
        ) {
            throw new NotFoundException('Access denied');
        }

        return $entity;
    }

    /**
     * List all files in a document.
     *
     * @return FileEntity[]
     */
    public function listByDocument(int $documentId, string $userId): array {
        if (!$this->permissionService->userHasReadAccessToDocument($userId, $documentId)) {
            throw new NotFoundException('Access denied');
        }

        return $this->fileMapper->findByDocument($documentId);
    }

    /**
     * List all files in a case (across all documents), filtered by user access.
     *
     * @return FileEntity[]
     */
    public function listByCase(int $caseId, string $userId): array {
        if (!$this->permissionService->userHasReadAccessToCase($userId, $caseId)) {
            throw new NotFoundException('Access denied');
        }

        return $this->fileMapper->findByCaseForUser($caseId, $userId);
    }

    /**
     * Delete a file (physical + DB + ES index).
     */
    public function delete(int $fileId, string $userId): void {
        $entity = $this->fileMapper->findById($fileId);
        if ($entity === null) {
            throw new NotFoundException("File {$fileId} not found");
        }

        if (!$this->permissionService->userHasWriteAccessToDocument($userId, $entity->getDocumentId())) {
            throw new NotFoundException('Access denied');
        }

        $case = $this->caseMapper->findById($entity->getCaseId());
        if ($case === null) {
            throw new NotFoundException("Case {$entity->getCaseId()} not found");
        }

        // Remove from ES
        try {
            $this->esService->deleteDocument($fileId);
        } catch (\Throwable $e) {
            $this->logger->warning('OpenCase: Failed to delete ES document for file {fileId}: {error}', [
                'fileId' => $fileId,
                'error' => $e->getMessage(),
            ]);
        }

        // Remove physical file
        $physicalPath = $this->buildPhysicalPath($entity, $case);
        if (file_exists($physicalPath)) {
            unlink($physicalPath);
        }

        // Remove DB entity
        $this->fileMapper->delete($entity);

        // Remove oc_filecache entry so the file no longer appears anywhere in NC
        $this->removeFilecacheEntry($entity, $case);

        $this->logger->info('OpenCase: File deleted: {fileId} ({filename})', [
            'fileId' => $fileId,
            'filename' => $entity->getOriginalFilename(),
        ]);
    }

    /**
     * Resolve the Nextcloud internal file ID for an OpenCase file.
     *
     * Virtual path: {year}/{month}/{day}/{case_number}/{virtual_filename}
     * where year/month/day are derived from the case's created_at (immutable).
     *
     * Queries oc_filecache directly, bypassing the permission wrapper so the
     * directory-level ACL block does not prevent Collabora from resolving the
     * file. The user permission check is done above via $this->get().
     *
     * If the entry is missing (e.g. file uploaded before filecache sync was
     * introduced) it is registered on the fly (lazy sync) and the query is
     * retried once.
     *
     * @throws NotFoundException if the file is not found or the user has no access
     */
    public function getNextcloudFileId(int $fileId, string $userId): int {
        $entity = $this->get($fileId, $userId); // permission check

        $case = $this->caseMapper->findById($entity->getCaseId());
        if ($case === null) {
            throw new NotFoundException("Case {$entity->getCaseId()} not found");
        }

        $year  = $case->getCreatedYear();
        $month = $case->getCreatedMonth();
        $day   = $case->getCreatedDay();

        if ($year === '') {
            throw new NotFoundException("Case {$entity->getCaseId()} has no creation date");
        }

        $virtualPath = implode('/', [
            $year, $month, $day,
            $case->getCaseNumber(),
            $entity->getVirtualFilename(),
        ]);

        $lookupFileId = function () use ($virtualPath): int|false {
            $qb     = $this->db->getQueryBuilder();
            $result = $qb->select('fc.fileid')
                ->from('filecache', 'fc')
                ->innerJoin('fc', 'storages', 's', $qb->expr()->eq('fc.storage', 's.numeric_id'))
                ->where($qb->expr()->eq('s.id', $qb->createNamedParameter('opencase')))
                ->andWhere($qb->expr()->eq('fc.path', $qb->createNamedParameter($virtualPath)))
                ->executeQuery();
            $row = $result->fetchOne();
            $result->closeCursor();
            return $row === false ? false : (int) $row;
        };

        $ncFileId = $lookupFileId();

        if ($ncFileId === false) {
            // Entry missing — lazy-register and retry once.
            $this->ensureFilecacheEntry($entity, $case);
            $ncFileId = $lookupFileId();
        }

        if ($ncFileId === false) {
            throw new NotFoundException(
                "File not found in virtual filesystem cache (path: {$virtualPath})."
            );
        }

        return $ncFileId;
    }

    /**
     * List all stored versions of a file, newest first.
     *
     * @return FileVersionEntity[]
     * @throws NotFoundException if the file is not found or the user has no read access
     */
    public function listVersions(int $fileId, string $userId): array {
        $this->get($fileId, $userId); // permission check
        return $this->fileVersionMapper->findByFileId($fileId);
    }

    /**
     * Return a read stream for a specific historical version of a file.
     *
     * @return array{entity: FileEntity, version: FileVersionEntity, stream: resource}
     * @throws NotFoundException
     */
    public function downloadVersion(int $fileId, int $versionId, string $userId): array {
        $entity  = $this->get($fileId, $userId);
        $version = $this->fileVersionMapper->findById($versionId);
        if ($version === null || $version->getFileId() !== $fileId) {
            throw new NotFoundException("Version {$versionId} not found");
        }

        $case = $this->caseMapper->findById($entity->getCaseId());
        if ($case === null) {
            throw new NotFoundException("Case {$entity->getCaseId()} not found");
        }

        $path = $this->buildVersionPath($entity, $case, $version->getTimestamp());
        if (!file_exists($path)) {
            throw new NotFoundException("Version file not found on disk");
        }

        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("Failed to open version file for reading");
        }

        return ['entity' => $entity, 'version' => $version, 'stream' => $stream];
    }

    /**
     * Copy a historical version to the current user's NC home folder so it
     * can be opened read-only in Nextcloud Office.
     *
     * Returns array with entity, version, and nc_file_id.
     *
     * @return array{entity: FileEntity, version: FileVersionEntity, nc_file_id: int}
     * @throws NotFoundException
     */
    public function openVersionForView(int $fileId, int $versionId, string $userId): array {
        $entity  = $this->get($fileId, $userId);
        $version = $this->fileVersionMapper->findById($versionId);
        if ($version === null || $version->getFileId() !== $fileId) {
            throw new NotFoundException("Version {$versionId} not found");
        }

        $case = $this->caseMapper->findById($entity->getCaseId());
        if ($case === null) {
            throw new NotFoundException("Case {$entity->getCaseId()} not found");
        }

        $path = $this->buildVersionPath($entity, $case, $version->getTimestamp());
        if (!file_exists($path)) {
            throw new NotFoundException("Version file not found on disk");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Failed to read version file");
        }

        $ncFileId = $this->writeVersionToUserHome($content, $entity->getOriginalFilename(), $version, $userId);

        return ['entity' => $entity, 'version' => $version, 'nc_file_id' => $ncFileId];
    }

    /**
     * Restore a historical version as the current file.
     *
     * The current file is first snapshotted before being overwritten, so no
     * history is lost.
     *
     * @return array{entity: FileEntity, version: FileVersionEntity}
     * @throws NotFoundException
     */
    public function restoreVersion(int $fileId, int $versionId, string $userId): array {
        $entity = $this->fileMapper->findById($fileId);
        if ($entity === null) {
            throw new NotFoundException("File {$fileId} not found");
        }

        if (!$this->permissionService->userHasWriteAccessToDocument($userId, $entity->getDocumentId())) {
            throw new NotFoundException('Access denied');
        }

        $version = $this->fileVersionMapper->findById($versionId);
        if ($version === null || $version->getFileId() !== $fileId) {
            throw new NotFoundException("Version {$versionId} not found");
        }

        $case = $this->caseMapper->findById($entity->getCaseId());
        if ($case === null) {
            throw new NotFoundException("Case {$entity->getCaseId()} not found");
        }

        $versionPath = $this->buildVersionPath($entity, $case, $version->getTimestamp());
        if (!file_exists($versionPath)) {
            throw new NotFoundException("Version file not found on disk");
        }

        $currentPath = $this->buildPhysicalPath($entity, $case);

        // Snapshot the current file before overwriting
        if (file_exists($currentPath)) {
            $this->snapshotVersion($entity, $case, $currentPath);
        }

        if (!copy($versionPath, $currentPath)) {
            throw new \RuntimeException("Failed to copy version file to current path");
        }

        $entity->setSize(filesize($currentPath));
        $entity->setChecksum(hash_file('sha256', $currentPath));
        $entity->setVersion($entity->getVersion() + 1);
        $entity->setUpdatedAt(new \DateTime());
        $entity->setLastModifiedBy($userId);
        $entity = $this->fileMapper->update($entity);

        $this->ensureFilecacheEntry($entity, $case, true);

        if ($this->isDocumentFinal($entity->getDocumentId())) {
            $this->updateFilecachePermissionsForDocument($entity->getDocumentId(), false);
        }

        $this->eventDispatcher->dispatchTyped(new FileUpdatedEvent($fileId));

        return ['entity' => $entity, 'version' => $version];
    }

    // ---------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------

    /**
     * Build the physical path for a stored version file.
     *
     * Layout: {baseDir}/{year}/{month}/{case_id}/{document_id}/_versions/{timestamp}
     */
    private function buildVersionPath(FileEntity $entity, CaseEntity $case, int $timestamp): string {
        $year  = $case->getCreatedYear();
        $month = $case->getCreatedMonth();
        return sprintf(
            '%s/%s/%s/%d/%d/_versions/%d',
            $this->baseDir,
            $year,
            $month,
            $entity->getCaseId(),
            $entity->getDocumentId(),
            $timestamp
        );
    }

    /**
     * Write version content to the user's NC home folder under OpenCase/Versioner/.
     *
     * Re-uses an existing temp copy if one with the same name already exists
     * (i.e. the user views the same version twice).
     */
    private function writeVersionToUserHome(string $content, string $originalFilename, FileVersionEntity $version, string $userId): int {
        $userFolder = $this->rootFolder->getUserFolder($userId);
        $folderPath = 'OpenCase/Versioner';

        if (!$userFolder->nodeExists('OpenCase')) {
            $userFolder->newFolder('OpenCase');
        }
        if (!$userFolder->nodeExists($folderPath)) {
            /** @var \OCP\Files\Folder $opencaseFolder */
            $opencaseFolder = $userFolder->get('OpenCase');
            $opencaseFolder->newFolder('Versioner');
        }

        /** @var \OCP\Files\Folder $folder */
        $folder   = $userFolder->get($folderPath);
        $baseName = pathinfo($originalFilename, PATHINFO_FILENAME);
        $ext      = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $suffix   = $ext !== '' ? '.' . $ext : '';
        $date     = (new \DateTime())->setTimestamp($version->getTimestamp())->format('Y-m-d_His');
        $filename = $baseName . '_v' . $date . $suffix;

        // Reuse existing copy for the same version
        if ($folder->nodeExists($filename)) {
            return $folder->get($filename)->getId();
        }

        $file = $folder->newFile($filename, $content);
        return $file->getId();
    }

    /**
     * Copy the current physical file to the _versions directory and record
     * the snapshot in oc_opencase_fileversions. Called by uploadNewVersion()
     * before the new content is written.
     */
    private function snapshotVersion(FileEntity $entity, CaseEntity $case, string $physicalPath): void {
        $year  = $case->getCreatedYear();
        $month = $case->getCreatedMonth();

        $versionsDir = sprintf(
            '%s/%s/%s/%d/%d/_versions',
            $this->baseDir, $year, $month, $entity->getCaseId(), $entity->getDocumentId()
        );

        if (!is_dir($versionsDir) && !mkdir($versionsDir, 0770, true) && !is_dir($versionsDir)) {
            $this->logger->error('OpenCase: failed to create versions dir {dir}', ['dir' => $versionsDir]);
            return;
        }

        $timestamp   = time();
        $versionPath = $versionsDir . '/' . $timestamp;

        if (!file_exists($versionPath)) {
            copy($physicalPath, $versionPath);
        }

        try {
            $record = new FileVersionEntity();
            $record->setFileId($entity->getId());
            $record->setTimestamp($timestamp);
            $record->setSize($entity->getSize());
            $record->setMimeType($entity->getMimeType());
            $record->setCreatedBy($entity->getLastModifiedBy() ?: $entity->getCreatedBy());
            $record->setCreatedAt(new \DateTime());
            $this->fileVersionMapper->insert($record);
        } catch (\OCP\DB\Exception $e) {
            if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                $this->logger->error('OpenCase: failed to record version: {msg}', ['msg' => $e->getMessage()]);
            }
        }
    }

    /**
     * Propagate existing document-level shares to a newly uploaded file.
     *
     * When a document has been shared with users via DocumentShareDialog, those
     * shares are stored per-file. A file uploaded after the share was created
     * would otherwise be missing share entries, causing the virtual filesystem
     * to deny access even though the API allows it (because userHasReadAccessToDocument
     * returns true when *any* file in the document is shared).
     */
    private function propagateDocumentShares(FileEntity $entity, int $documentId): void {
        $existing = $this->fileShareMapper->findByDocumentId($documentId);
        if (empty($existing)) {
            return;
        }

        // Build the set of unique (shared_with, permissions, expires_at) tuples
        // from sibling files in this document (ignore any that already target this file).
        $seen = [];
        foreach ($existing as $share) {
            if ($share->getFileId() === $entity->getId()) {
                continue;
            }
            $key = $share->getSharedWith() . ':' . $share->getPermissions();
            if (!isset($seen[$key])) {
                $seen[$key] = $share;
            }
        }

        $now = new \DateTime();
        foreach ($seen as $source) {
            // Skip expired shares
            $exp = $source->getExpiresAt();
            if ($exp !== null && $exp < $now) {
                continue;
            }

            try {
                $newShare = new FileShareEntity();
                $newShare->setFileId($entity->getId());
                $newShare->setDocumentId($documentId);
                $newShare->setOwnerId($source->getOwnerId());
                $newShare->setSharedWith($source->getSharedWith());
                $newShare->setPermissions($source->getPermissions());
                $newShare->setCreatedAt($now);
                $newShare->setExpiresAt($source->getExpiresAt());
                $newShare->setNote($source->getNote());
                $this->fileShareMapper->insert($newShare);
            } catch (\Throwable $e) {
                $this->logger->warning('OpenCase: failed to propagate document share for file {id}: {msg}', [
                    'id'  => $entity->getId(),
                    'msg' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Ensure oc_filecache has a row for the given file and all its ancestor dirs.
     *
     * Called after upload (new file) and uploadNewVersion (overwrite).
     * Safe to call repeatedly: INSERT IGNORE skips rows that already exist.
     * When $update=true the existing file row's size/mtime/etag are refreshed.
     *
     * Permissions stored here are the ceiling (maximum any user could have).
     * The OpenCaseCacheWrapper masks them down to each user's actual rights.
     */
    private function ensureFilecacheEntry(FileEntity $entity, CaseEntity $case, bool $update = false): void {
        try {
            $year     = $case->getCreatedYear();
            $month    = $case->getCreatedMonth();
            $day      = $case->getCreatedDay();
            $caseNo   = $case->getCaseNumber();
            $filename = $entity->getVirtualFilename();
            $now      = time();
            $mimeType = $entity->getMimeType() ?: 'application/octet-stream';
            $fileSize = $entity->getSize();
            $mimePart = strstr($mimeType, '/', true) ?: $mimeType;

            // ── Storage row ────────────────────────────────────────────────
            $this->db->insertIfNotExist('*PREFIX*storages',
                ['id' => 'opencase', 'available' => 1, 'last_checked' => $now],
                ['id']
            );
            $storageId = $this->getStorageId();

            // ── Mime types ─────────────────────────────────────────────────
            foreach (array_unique(['httpd', 'httpd/unix-directory', $mimeType, $mimePart]) as $mt) {
                $this->db->insertIfNotExist('*PREFIX*mimetypes', ['mimetype' => $mt], ['mimetype']);
            }
            $qb      = $this->db->getQueryBuilder();
            $mimeRes = $qb->select('mimetype', 'id')->from('mimetypes')
                ->where($qb->expr()->in('mimetype', $qb->createNamedParameter(
                    ['httpd', 'httpd/unix-directory', $mimeType, $mimePart],
                    \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY
                )))
                ->executeQuery()->fetchAllAssociative();
            $mimeMap     = array_column($mimeRes, 'id', 'mimetype');
            $mimeDir     = (int) ($mimeMap['httpd/unix-directory'] ?? 0);
            $mimeDirPart = (int) ($mimeMap['httpd'] ?? 0);
            $mimeFileId  = (int) ($mimeMap[$mimeType] ?? $mimeDir);
            $mimePartId  = (int) ($mimeMap[$mimePart] ?? $mimeDirPart);

            // ── Helper: ensure a directory entry exists, return its fileid ─
            $ensureDir = function (string $path, int $parentId, string $name, int $permissions = 1) use (
                $storageId, $mimeDir, $mimeDirPart, $now
            ): int {
                $hash = md5($path);
                $this->db->insertIfNotExist('*PREFIX*filecache', [
                    'storage'          => $storageId,
                    'path'             => $path,
                    'path_hash'        => $hash,
                    'parent'           => $parentId,
                    'name'             => $name,
                    'mimetype'         => $mimeDir,
                    'mimepart'         => $mimeDirPart,
                    'size'             => 0,
                    'mtime'            => $now,
                    'storage_mtime'    => $now,
                    'encrypted'        => 0,
                    'unencrypted_size' => 0,
                    'etag'             => bin2hex(random_bytes(16)),
                    'permissions'      => $permissions,
                    'checksum'         => '',
                ], ['storage', 'path_hash']);
                $qb = $this->db->getQueryBuilder();
                return (int) $qb->select('fileid')->from('filecache')
                    ->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->andWhere($qb->expr()->eq('path_hash', $qb->createNamedParameter($hash)))
                    ->executeQuery()->fetchOne();
            };

            // ── Directory hierarchy levels 0–4 ────────────────────────────
            // Case dirs get permissions=7 (READ|UPDATE|CREATE) when any user
            // has write access to the profile; otherwise 1 (READ only).
            $caseDirPerms = $this->profileHasWriteAccess($case->getAccessProfileId()) ? 7 : 1;
            $rootId   = $ensureDir('',                     -1,     '');
            $yearId   = $ensureDir($year,                  $rootId, $year);
            $monthId  = $ensureDir("$year/$month",         $yearId, $month);
            $dayId    = $ensureDir("$year/$month/$day",    $monthId, $day);
            $casePath = "$year/$month/$day/$caseNo";
            $caseFcId = $ensureDir($casePath,              $dayId,  $caseNo, $caseDirPerms);

            // ── File entry level 5 ────────────────────────────────────────
            $filePath = "$casePath/$filename";
            $fileHash = md5($filePath);

            $this->db->insertIfNotExist('*PREFIX*filecache', [
                'storage'          => $storageId,
                'path'             => $filePath,
                'path_hash'        => $fileHash,
                'parent'           => $caseFcId,
                'name'             => $filename,
                'mimetype'         => $mimeFileId,
                'mimepart'         => $mimePartId,
                'size'             => $fileSize,
                'mtime'            => $now,
                'storage_mtime'    => $now,
                'encrypted'        => 0,
                'unencrypted_size' => 0,
                'etag'             => bin2hex(random_bytes(16)),
                'permissions'      => 3, // ceiling (READ|UPDATE); wrapper masks per user
                'checksum'         => '',
            ], ['storage', 'path_hash']);

            // For new-version overwrites: refresh size, mtime, etag
            if ($update) {
                $qb = $this->db->getQueryBuilder();
                $qb->update('filecache')
                    ->set('size',          $qb->createNamedParameter($fileSize, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                    ->set('mtime',         $qb->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                    ->set('storage_mtime', $qb->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                    ->set('etag',          $qb->createNamedParameter(bin2hex(random_bytes(8))))
                    ->where($qb->expr()->eq('storage',   $qb->createNamedParameter($storageId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->andWhere($qb->expr()->eq('path_hash', $qb->createNamedParameter($fileHash)))
                    ->executeStatement();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('OpenCase: filecache entry sync failed for file {id}: {msg}', [
                'id'  => $entity->getId(),
                'msg' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Set filecache permissions for every file in a document.
     *
     * Called when the document's status changes to/from a final state.
     * $writable=false  → permissions=1 (read-only ceiling; Collabora opens view-only)
     * $writable=true   → permissions=3 (read+update ceiling; Collabora opens for editing)
     *
     * The OpenCaseCacheWrapper may further restrict per-user rights at runtime,
     * but it can never grant more than what the filecache ceiling allows.
     */
    public function updateFilecachePermissionsForDocument(int $documentId, bool $writable): void {
        $files = $this->fileMapper->findByDocument($documentId);
        if (empty($files)) {
            return;
        }

        $storageId = $this->getStorageId();
        if ($storageId === 0) {
            return;
        }

        $permissions = $writable ? 3 : 1;

        foreach ($files as $file) {
            $case = $this->caseMapper->findById($file->getCaseId());
            if ($case === null) {
                continue;
            }

            $filePath = implode('/', [
                $case->getCreatedYear(), $case->getCreatedMonth(), $case->getCreatedDay(),
                $case->getCaseNumber(), $file->getVirtualFilename(),
            ]);

            $qb = $this->db->getQueryBuilder();
            $qb->update('filecache')
                ->set('permissions', $qb->createNamedParameter($permissions, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('path_hash', $qb->createNamedParameter(md5($filePath))))
                ->executeStatement();
        }
    }

    /**
     * Remove the oc_filecache row for a deleted file, then prune any ancestor
     * directory rows that have become empty (case dir → day → month → year).
     */
    private function removeFilecacheEntry(FileEntity $entity, CaseEntity $case): void {
        try {
            $year   = $case->getCreatedYear();
            $month  = $case->getCreatedMonth();
            $day    = $case->getCreatedDay();
            $caseNo = $case->getCaseNumber();

            $storageId = $this->getStorageId();
            if ($storageId === 0) {
                return;
            }

            // Delete the file row
            $filePath = "$year/$month/$day/$caseNo/{$entity->getVirtualFilename()}";
            $qb = $this->db->getQueryBuilder();
            $qb->delete('filecache')
                ->where($qb->expr()->eq('storage',   $qb->createNamedParameter($storageId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('path_hash', $qb->createNamedParameter(md5($filePath))))
                ->executeStatement();

            // Prune empty ancestor dirs from most-specific to least-specific.
            // Stop as soon as a dir still has children.
            $this->pruneEmptyAncestors($storageId, [
                "$year/$month/$day/$caseNo",
                "$year/$month/$day",
                "$year/$month",
                "$year",
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('OpenCase: filecache cleanup failed for file {id}: {msg}', [
                'id'  => $entity->getId(),
                'msg' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ensure oc_filecache has rows for a case and all its ancestor directories
     * (root → year → month → day → case dir) without requiring a file upload.
     *
     * Called from CaseService::create() so the case directory appears in the
     * virtual filesystem immediately, even before any files are attached.
     */
    public function ensureCaseFilecacheEntry(CaseEntity $case): void {
        try {
            $year   = $case->getCreatedYear();
            $month  = $case->getCreatedMonth();
            $day    = $case->getCreatedDay();
            $caseNo = $case->getCaseNumber();
            if ($year === '') {
                return;
            }

            $now = time();

            $this->db->insertIfNotExist('*PREFIX*storages',
                ['id' => 'opencase', 'available' => 1, 'last_checked' => $now],
                ['id']
            );
            $storageId = $this->getStorageId();

            foreach (['httpd', 'httpd/unix-directory'] as $mt) {
                $this->db->insertIfNotExist('*PREFIX*mimetypes', ['mimetype' => $mt], ['mimetype']);
            }
            $qb      = $this->db->getQueryBuilder();
            $mimeRes = $qb->select('mimetype', 'id')->from('mimetypes')
                ->where($qb->expr()->in('mimetype', $qb->createNamedParameter(
                    ['httpd', 'httpd/unix-directory'],
                    \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY
                )))
                ->executeQuery()->fetchAllAssociative();
            $mimeMap     = array_column($mimeRes, 'id', 'mimetype');
            $mimeDir     = (int) ($mimeMap['httpd/unix-directory'] ?? 0);
            $mimeDirPart = (int) ($mimeMap['httpd'] ?? 0);

            $ensureDir = function (string $path, int $parentId, string $name, int $permissions = 1) use (
                $storageId, $mimeDir, $mimeDirPart, $now
            ): int {
                $hash = md5($path);
                $this->db->insertIfNotExist('*PREFIX*filecache', [
                    'storage'          => $storageId,
                    'path'             => $path,
                    'path_hash'        => $hash,
                    'parent'           => $parentId,
                    'name'             => $name,
                    'mimetype'         => $mimeDir,
                    'mimepart'         => $mimeDirPart,
                    'size'             => 0,
                    'mtime'            => $now,
                    'storage_mtime'    => $now,
                    'encrypted'        => 0,
                    'unencrypted_size' => 0,
                    'etag'             => bin2hex(random_bytes(16)),
                    'permissions'      => $permissions,
                    'checksum'         => '',
                ], ['storage', 'path_hash']);
                $qb = $this->db->getQueryBuilder();
                return (int) $qb->select('fileid')->from('filecache')
                    ->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->andWhere($qb->expr()->eq('path_hash', $qb->createNamedParameter($hash)))
                    ->executeQuery()->fetchOne();
            };

            $caseDirPerms = $this->profileHasWriteAccess($case->getAccessProfileId()) ? 7 : 1;
            $rootId  = $ensureDir('',                  -1,      '');
            $yearId  = $ensureDir($year,               $rootId, $year);
            $monthId = $ensureDir("$year/$month",      $yearId, $month);
            $dayId   = $ensureDir("$year/$month/$day", $monthId, $day);
            $ensureDir("$year/$month/$day/$caseNo",    $dayId,  $caseNo, $caseDirPerms);
        } catch (\Throwable $e) {
            $this->logger->warning('OpenCase: case filecache entry failed for case {id}: {msg}', [
                'id'  => $case->getId(),
                'msg' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update filecache ceiling permissions for every case and file belonging
     * to the given access profile.
     *
     * Called after a user-access grant or revoke so the virtual filesystem
     * reflects the new write-user state without waiting for the repair job.
     *
     * Ceiling rules (same as the stored procedure):
     *   - Case dir: permissions=7 if any write user exists, else 1
     *   - File row:  permissions=3 if any write user exists AND doc is not Final, else 1
     *
     * Updates are batched in chunks of 500 path_hashes to keep individual
     * UPDATE statements small and avoid long lock holds on oc_filecache.
     */
    public function updateCeilingPermissionsForProfile(int $profileId): void {
        try {
            $storageId = $this->getStorageId();
            if ($storageId === 0) {
                return;
            }

            $hasWriter    = $this->profileHasWriteAccess($profileId);
            $caseDirPerms = $hasWriter ? 7 : 1;
            $filePerms    = $hasWriter ? 3 : 1;

            // ── Case directory rows ────────────────────────────────────────
            $offset = 0;
            do {
                $qb   = $this->db->getQueryBuilder();
                $rows = $qb->select('case_number', 'created_at')
                    ->from('opencase_cases')
                    ->where($qb->expr()->eq('access_profile_id', $qb->createNamedParameter($profileId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->andWhere($qb->expr()->isNotNull('created_at'))
                    ->setMaxResults(500)
                    ->setFirstResult($offset)
                    ->executeQuery()
                    ->fetchAllAssociative();

                $hashes = [];
                foreach ($rows as $row) {
                    $dt = new \DateTime($row['created_at']);
                    $path = $dt->format('Y/m/d') . '/' . $row['case_number'];
                    $hashes[] = md5($path);
                }

                if (!empty($hashes)) {
                    $qb = $this->db->getQueryBuilder();
                    $qb->update('filecache')
                        ->set('permissions', $qb->createNamedParameter($caseDirPerms, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                        ->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                        ->andWhere($qb->expr()->in('path_hash', $qb->createNamedParameter($hashes, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
                        ->executeStatement();
                }

                $offset += 500;
            } while (count($rows) === 500);

            // ── File rows (respect Final-document override) ────────────────
            $offset = 0;
            do {
                $qb   = $this->db->getQueryBuilder();
                $rows = $qb->select('f.virtual_filename', 'c.case_number', 'c.created_at', 'ds.is_final')
                    ->from('opencase_files', 'f')
                    ->innerJoin('f', 'opencase_cases', 'c', $qb->expr()->eq('c.id', 'f.case_id'))
                    ->innerJoin('f', 'opencase_documents', 'd', $qb->expr()->eq('d.id', 'f.document_id'))
                    ->leftJoin('d', 'opencase_documentstatus', 'ds',
                        $qb->expr()->andX(
                            $qb->expr()->eq('ds.id', 'd.status'),
                            $qb->expr()->eq('ds.language', $qb->createNamedParameter('en'))
                        )
                    )
                    ->where($qb->expr()->eq('c.access_profile_id', $qb->createNamedParameter($profileId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->andWhere($qb->expr()->isNotNull('c.created_at'))
                    ->setMaxResults(500)
                    ->setFirstResult($offset)
                    ->executeQuery()
                    ->fetchAllAssociative();

                // Split rows by effective permissions (Final files always stay at 1)
                $writeHashes    = [];
                $readOnlyHashes = [];
                foreach ($rows as $row) {
                    $dt   = new \DateTime($row['created_at']);
                    $path = $dt->format('Y/m/d') . '/' . $row['case_number'] . '/' . $row['virtual_filename'];
                    $hash = md5($path);
                    if ($row['is_final']) {
                        $readOnlyHashes[] = $hash;
                    } else {
                        $writeHashes[] = $hash;
                    }
                }

                foreach ([[$filePerms, $writeHashes], [1, $readOnlyHashes]] as [$perms, $hashes]) {
                    if (empty($hashes)) {
                        continue;
                    }
                    $qb = $this->db->getQueryBuilder();
                    $qb->update('filecache')
                        ->set('permissions', $qb->createNamedParameter($perms, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                        ->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                        ->andWhere($qb->expr()->in('path_hash', $qb->createNamedParameter($hashes, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
                        ->executeStatement();
                }

                $offset += 500;
            } while (count($rows) === 500);
        } catch (\Throwable $e) {
            $this->logger->warning('OpenCase: ceiling permissions update failed for profile {id}: {msg}', [
                'id'  => $profileId,
                'msg' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delete ancestor directory rows that have become empty after a file was
     * removed. Walks the given paths from most-specific to least-specific and
     * stops as soon as one still has children (its parents must too).
     *
     * @param string[] $paths Ordered most-specific → least-specific
     */
    private function pruneEmptyAncestors(int $storageId, array $paths): void {
        foreach ($paths as $path) {
            $hash = md5($path);

            // Resolve fileid for this directory
            $qb     = $this->db->getQueryBuilder();
            $fileId = $qb->select('fileid')->from('filecache')
                ->where($qb->expr()->eq('storage',   $qb->createNamedParameter($storageId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('path_hash', $qb->createNamedParameter($hash)))
                ->executeQuery()->fetchOne();

            if ($fileId === false) {
                continue; // already absent
            }
            $fileId = (int) $fileId;

            // Count remaining children
            $qb         = $this->db->getQueryBuilder();
            $childCount = (int) $qb->select($qb->createFunction('COUNT(*)'))
                ->from('filecache')
                ->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('parent', $qb->createNamedParameter($fileId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeQuery()->fetchOne();

            if ($childCount > 0) {
                break; // Still has children; parents cannot be empty either
            }

            // Empty — remove it
            $qb = $this->db->getQueryBuilder();
            $qb->delete('filecache')
                ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeStatement();
        }
    }

    /**
     * Return the numeric_id of the shared 'opencase' storage row, or 0 if it
     * does not exist yet.
     */
    private function getStorageId(): int {
        $qb  = $this->db->getQueryBuilder();
        $row = $qb->select('numeric_id')->from('storages')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter('opencase')))
            ->executeQuery()->fetchOne();
        return $row === false ? 0 : (int) $row;
    }

    /**
     * Return true if the given access profile has at least one user with
     * 'write' access level assigned.
     */
    private function profileHasWriteAccess(int $profileId): bool {
        $qb    = $this->db->getQueryBuilder();
        $count = (int) $qb->select($qb->createFunction('COUNT(*)'))
            ->from('opencase_user_access')
            ->where($qb->expr()->eq('access_profile_id', $qb->createNamedParameter($profileId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('access_level', $qb->createNamedParameter('write')))
            ->executeQuery()->fetchOne();
        return $count > 0;
    }

    /**
     * Build the physical storage path for a file.
     *
     * Layout: {baseDir}/{year}/{month}/{case_id}/{document_id}/{storage_filename}
     *
     * Year and month come from the case's created_at (immutable), which limits
     * the number of entries per directory at scale (~4M cases / 12 months ≈ 333k/month).
     *
     * @throws NotFoundException if the case has no creation date
     */
    private function buildPhysicalPath(FileEntity $entity, CaseEntity $case): string {
        $year  = $case->getCreatedYear();
        $month = $case->getCreatedMonth();
        if ($year === '') {
            throw new NotFoundException("Case {$case->getId()} has no creation date");
        }
        return sprintf(
            '%s/%s/%s/%d/%d/%s',
            $this->baseDir,
            $year,
            $month,
            $entity->getCaseId(),
            $entity->getDocumentId(),
            $entity->getStorageFilename()
        );
    }

    /**
     * Return the actual last-modified time of the physical file on disk.
     *
     * `updated_at` in oc_opencase_files is only written by file_put_contents()
     * (the direct-API upload path). WebDAV/Collabora writes go through fopen()
     * and never update the DB column, so updated_at can be stale. Reading
     * filemtime() from the physical file is always accurate.
     *
     * Falls back to updated_at if the physical file is not accessible.
     */
    public function getPhysicalMtime(FileEntity $entity): \DateTimeInterface {
        $case = $this->caseMapper->findById($entity->getCaseId());
        if ($case !== null) {
            try {
                $path  = $this->buildPhysicalPath($entity, $case);
                $mtime = file_exists($path) ? filemtime($path) : false;
                if ($mtime !== false) {
                    return (new \DateTime())->setTimestamp($mtime);
                }
            } catch (\Throwable) {
                // fall through to updated_at
            }
        }
        return $entity->getUpdatedAt() ?? new \DateTime();
    }

    /**
     * Return true if the document's current status has is_final=1.
     */
    private function isDocumentFinal(int $documentId): bool {
        $qb     = $this->db->getQueryBuilder();
        $result = $qb->select('ds.is_final')
            ->from('opencase_documents', 'd')
            ->innerJoin('d', 'opencase_documentstatus', 'ds',
                $qb->expr()->andX(
                    $qb->expr()->eq('ds.id', 'd.status'),
                    $qb->expr()->eq('ds.language', $qb->createNamedParameter('en'))
                )
            )
            ->where($qb->expr()->eq('d.id', $qb->createNamedParameter($documentId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->executeQuery();
        $row = $result->fetchOne();
        $result->closeCursor();
        return $row !== false && (bool)$row;
    }

    private function generateUuid(): string {
        return bin2hex(random_bytes(16));
    }
}
