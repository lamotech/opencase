<?php

declare(strict_types=1);

namespace OCA\OpenCase\Versions;

use OCA\Files_Versions\Versions\IVersion;
use OCA\Files_Versions\Versions\IVersionBackend;
use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\FileMapper;
use OCA\OpenCase\Db\FileVersionEntity;
use OCA\OpenCase\Db\FileVersionMapper;
use OCA\OpenCase\Service\CaseService;
use OCA\OpenCase\Storage\OpenCaseStorage;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Version backend for OpenCase files.
 *
 * Stores file versions in the shared physical storage area instead of per-user
 * files_versions directories. This keeps all OpenCase data — both live files
 * and their version history — under the same base directory (opencase_files),
 * and ensures that a single version copy is shared across all users who can
 * access the file.
 *
 * Version layout on disk:
 *   {baseDir}/{year}/{month}/{case_id}/{doc_id}/_versions/{timestamp}
 *
 * where year/month come from the case's created_at date (same as the live file),
 * and {timestamp} is the file's mtime at the moment the version was captured.
 *
 * Version metadata is also persisted in oc_opencase_fileversions so the UI can
 * display version history without scanning the filesystem.
 *
 * The backend is registered in appinfo/info.xml under <versions> and is picked
 * up automatically by files_versions at boot time. It is selected over the
 * default LegacyVersionsBackend because OpenCaseStorage is a more specific
 * type than the fallback IStorage.
 */
class OpenCaseVersionsBackend implements IVersionBackend {

    private string $baseDir;

    public function __construct(
        private CaseMapper $caseMapper,
        private FileMapper $fileMapper,
        private FileVersionMapper $fileVersionMapper,
        private IUserManager $userManager,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
        $defaultDir    = rtrim($config->getSystemValueString('datadirectory', ''), '/') . '/opencase_storage';
        $this->baseDir = $config->getAppValue('opencase', 'storage_base_dir', $defaultDir);
    }

    // ---------------------------------------------------------------
    // IVersionBackend
    // ---------------------------------------------------------------

    public function useBackendForStorage(IStorage $storage): bool {
        return $storage->instanceOfStorage(OpenCaseStorage::class);
    }

    /**
     * Called by files_versions before a file write (BeforeNodeWrittenEvent).
     * Copies the current physical file to the _versions subdirectory and
     * records the version in oc_opencase_fileversions.
     */
    public function createVersion(IUser $user, FileInfo $file): void {
        $parts = $this->parseInternalPath($file->getInternalPath());
        if ($parts === null) {
            return;
        }

        [$year, $month, $caseNumber, $virtualFilename] = $parts;

        $case = $this->caseMapper->findByCaseNumber($caseNumber);
        if ($case === null) {
            $this->logger->warning('OpenCase versions: case not found for case_number {cn}', ['cn' => $caseNumber]);
            return;
        }

        $fileEntity = $this->fileMapper->findByVirtualNameNoAuth($case->getId(), $virtualFilename);
        if ($fileEntity === null) {
            $this->logger->warning('OpenCase versions: file entity not found for {fn} in case {cid}', [
                'fn' => $virtualFilename, 'cid' => $case->getId(),
            ]);
            return;
        }

        $physicalPath = $this->buildPhysicalPath($year, $month, $case->getId(), $fileEntity->getDocumentId(), $fileEntity->getStorageFilename());
        if (!file_exists($physicalPath)) {
            return; // nothing to version yet (first upload)
        }

        $versionsDir = $this->versionsDir($year, $month, $case->getId(), $fileEntity->getDocumentId());
        if (!is_dir($versionsDir) && !mkdir($versionsDir, 0770, true) && !is_dir($versionsDir)) {
            $this->logger->error('OpenCase versions: failed to create versions directory {dir}', ['dir' => $versionsDir]);
            return;
        }

        $timestamp = $file->getMtime() ?: time();
        $versionPath = $versionsDir . '/' . $timestamp;

        if (!file_exists($versionPath)) {
            if (!copy($physicalPath, $versionPath)) {
                $this->logger->error('OpenCase versions: failed to copy file to version path {path}', ['path' => $versionPath]);
                return;
            }
        }

        $snapshotAuthor = $fileEntity->getLastModifiedBy() ?: $fileEntity->getCreatedBy();

        $this->saveVersionRecord(
            $fileEntity->getId(),
            $timestamp,
            $file->getSize(),
            $file->getMimetype(),
            $snapshotAuthor,
        );

        // Update last_modified_by to the writer of the new content
        $fileEntity->setLastModifiedBy($user->getUID());
        $this->fileMapper->update($fileEntity);
    }

    /**
     * Returns all stored versions for the given file, newest first.
     *
     * @return OpenCaseVersion[]
     */
    public function getVersionsForFile(IUser $user, FileInfo $file): array {
        $parts = $this->parseInternalPath($file->getInternalPath());
        if ($parts === null) {
            return [];
        }

        [$year, $month, $caseNumber, $virtualFilename] = $parts;

        $case = $this->caseMapper->findByCaseNumber($caseNumber);
        if ($case === null) {
            return [];
        }

        $fileEntity = $this->fileMapper->findByVirtualNameNoAuth($case->getId(), $virtualFilename);
        if ($fileEntity === null) {
            return [];
        }

        $versionsDir = $this->versionsDir($year, $month, $case->getId(), $fileEntity->getDocumentId());
        if (!is_dir($versionsDir)) {
            return [];
        }

        // Build a map of timestamp → created_by from the DB records so that
        // each version correctly attributes the user who saved it, not the
        // user who is currently requesting the version list.
        $dbRecords  = $this->fileVersionMapper->findByFileId($fileEntity->getId());
        $authorById = [];
        foreach ($dbRecords as $rec) {
            $authorById[$rec->getTimestamp()] = $rec->getCreatedBy();
        }

        $versions = [];
        $entries  = scandir($versionsDir, SCANDIR_SORT_DESCENDING);
        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $timestamp = (int)$entry;
            if ($timestamp <= 0) {
                continue;
            }

            $versionPath = $versionsDir . '/' . $entry;
            $size        = filesize($versionPath);
            if ($size === false) {
                continue;
            }

            // Resolve the author IUser; fall back to the requestor if unknown.
            $authorId   = $authorById[$timestamp] ?? null;
            $authorUser = ($authorId !== null)
                ? ($this->userManager->get($authorId) ?? $user)
                : $user;

            $versions[] = new OpenCaseVersion(
                $timestamp,
                $file->getName(),
                $size,
                $file->getMimetype(),
                $versionPath,
                $file,
                $this,
                $authorUser,
            );
        }

        return $versions;
    }

    /**
     * Restores the given version by copying it back over the live file.
     */
    public function rollback(IVersion $version): bool {
        $versionPath = $version->getVersionPath();
        if (!file_exists($versionPath)) {
            return false;
        }

        $parts = $this->parseInternalPath($version->getSourceFile()->getInternalPath());
        if ($parts === null) {
            return false;
        }

        [$year, $month, $caseNumber, $virtualFilename] = $parts;

        $case = $this->caseMapper->findByCaseNumber($caseNumber);
        if ($case === null) {
            return false;
        }

        $fileEntity = $this->fileMapper->findByVirtualNameNoAuth($case->getId(), $virtualFilename);
        if ($fileEntity === null) {
            return false;
        }

        $physicalPath = $this->buildPhysicalPath($year, $month, $case->getId(), $fileEntity->getDocumentId(), $fileEntity->getStorageFilename());

        if (!copy($versionPath, $physicalPath)) {
            $this->logger->error('OpenCase versions: failed to rollback version {ts} for file {fid}', [
                'ts' => $version->getTimestamp(),
                'fid' => $fileEntity->getId(),
            ]);
            return false;
        }

        $this->fileMapper->updateFileStats($fileEntity->getId(), (int)filesize($physicalPath), time());

        return true;
    }

    /**
     * Opens the version file for reading (used by the WebDAV version endpoint).
     *
     * @return resource|false
     * @throws NotFoundException
     */
    public function read(IVersion $version) {
        $path = $version->getVersionPath();
        if (!file_exists($path)) {
            throw new NotFoundException("Version file not found: {$path}");
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new NotFoundException("Cannot open version file: {$path}");
        }

        return $handle;
    }

    /**
     * Not supported: version files are stored outside the NC virtual filesystem
     * so they cannot be returned as File nodes. Previews of versions are
     * therefore unavailable for OpenCase files.
     *
     * @throws NotFoundException always
     */
    public function getVersionFile(IUser $user, FileInfo $sourceFile, $revision): File {
        throw new NotFoundException('Version file nodes are not available for OpenCase storage');
    }

    public function getRevision(Node $node): int {
        return $node->getMTime();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Insert a version record into oc_opencase_fileversions.
     * Silently ignores duplicate (file_id, timestamp) conflicts.
     */
    private function saveVersionRecord(int $fileId, int $timestamp, int|float $size, string $mimeType, string $createdBy): void {
        try {
            $record = new FileVersionEntity();
            $record->setUuid(CaseService::generateUuid());
            $record->setFileId($fileId);
            $record->setTimestamp($timestamp);
            $record->setSize((int)$size);
            $record->setMimeType($mimeType);
            $record->setCreatedBy($createdBy);
            $record->setCreatedAt(new \DateTime());
            $this->fileVersionMapper->insert($record);
        } catch (\OCP\DB\Exception $e) {
            // Unique constraint violation: version already recorded (e.g. concurrent write)
            if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                $this->logger->error('OpenCase versions: failed to save version record: {msg}', ['msg' => $e->getMessage()]);
            }
        }
    }

    /**
     * Parse a virtual internal path (relative to the storage root) into its
     * structural components.
     *
     * Expected format: {year}/{month}/{day}/{case_number}/{virtual_filename}
     *
     * @return array{0: string, 1: string, 2: string, 3: string}|null
     *         [year, month, caseNumber, virtualFilename], or null if the path
     *         is not a valid 5-segment OpenCase file path.
     */
    private function parseInternalPath(string $internalPath): ?array {
        $parts = explode('/', trim($internalPath, '/'));
        if (count($parts) !== 5) {
            return null;
        }

        [$year, $month, /* $day */, $caseNumber, $virtualFilename] = $parts;

        if (!preg_match('/^\d{4}$/', $year) || !preg_match('/^\d{2}$/', $month)) {
            return null;
        }

        return [$year, $month, $caseNumber, $virtualFilename];
    }

    private function versionsDir(string $year, string $month, int $caseId, int $docId): string {
        return sprintf('%s/%s/%s/%d/%d/_versions', $this->baseDir, $year, $month, $caseId, $docId);
    }

    private function buildPhysicalPath(string $year, string $month, int $caseId, int $docId, string $storageFilename): string {
        return sprintf('%s/%s/%s/%d/%d/%s', $this->baseDir, $year, $month, $caseId, $docId, $storageFilename);
    }
}
