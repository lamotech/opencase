<?php

declare(strict_types=1);

namespace OCA\OpenCase\Storage;

use OC\Files\Storage\Common;
use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\FileMapper;
use OCA\OpenCase\Event\FileUpdatedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Cache\ICache;
use OCP\Files\Cache\IScanner;
use OCP\Files\Storage\IStorage;
use Psr\Log\LoggerInterface;

/**
 * OpenCaseStorage — shared virtual storage backend for case & document management.
 *
 * This class presents the FULL virtual folder hierarchy to Nextcloud, with NO
 * per-user filtering. All users share a single storage (storageId = 'opencase'),
 * giving one row per file in oc_filecache instead of one row per user per file.
 *
 * Virtual tree:
 *
 *   /                                        (root)
 *   └── {year}/                              e.g. 2026/
 *       └── {month}/                         e.g. 03/
 *           └── {day}/                       e.g. 01/
 *               └── {case_number}/           e.g. 2026-001/
 *                   └── {filename}.{ext}     e.g. Contract.docx
 *
 * Paths are derived from cases.created_at (immutable), so renaming a case
 * or changing its organisation never changes its virtual path.
 *
 * Per-user access control is enforced by OpenCasePermissionWrapper (storage
 * operations) and OpenCaseCacheWrapper (cache/PROPFIND operations), which wrap
 * this class at mount time. This class never checks user identity.
 *
 * Physical files are stored on disk at:
 *   {baseDir}/{case_id}/{document_id}/{file_uuid}.{ext}
 */
class OpenCaseStorage extends Common {

    /** @var string Base directory where physical files are stored on disk */
    private string $baseDir;

    /** @var CaseMapper */
    private CaseMapper $caseMapper;

    /** @var FileMapper */
    private FileMapper $fileMapper;

    /** @var IEventDispatcher */
    private IEventDispatcher $eventDispatcher;

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /**
     * In-memory cache for resolved virtual paths within this request.
     * Maps normalised virtual path → resolved metadata array, or null if not found.
     *
     * @var array<string, array|null>
     */
    private array $pathCache = [];

    public function __construct(array $params) {
        parent::__construct($params);

        $this->baseDir         = $params['baseDir'] ?? '';
        $this->caseMapper      = $params['caseMapper'];
        $this->fileMapper      = $params['fileMapper'];
        $this->eventDispatcher = $params['eventDispatcher'];
        $this->logger          = $params['logger'];
    }

    // ---------------------------------------------------------------
    // Storage identity — ONE shared ID for all users
    // ---------------------------------------------------------------

    public function getId(): string {
        return 'opencase';
    }

    // ---------------------------------------------------------------
    // Path resolution
    //
    // Virtual path components (no user filter — wrapper handles that):
    //   ""                              root  → list all years
    //   "2026"                          year  → list all months
    //   "2026/03"                       month → list all days
    //   "2026/03/01"                    day   → list all case numbers
    //   "2026/03/01/2026-001"           case  → list all files
    //   "2026/03/01/2026-001/file.docx" file
    // ---------------------------------------------------------------

    /**
     * Parse a virtual path into its structural components.
     *
     * @return array{type: string, year?: string, month?: string, day?: string,
     *               case_number?: string, case_id?: int,
     *               file_id?: int, filename?: string,
     *               physical_path?: string}|null
     */
    private function resolvePath(string $path): ?array {
        $path = $this->normalizePath($path);

        if (array_key_exists($path, $this->pathCache)) {
            return $this->pathCache[$path];
        }

        $resolved             = $this->doResolvePath($path);
        $this->pathCache[$path] = $resolved;
        return $resolved;
    }

    private function doResolvePath(string $path): ?array {
        if ($path === '') {
            return ['type' => 'root'];
        }

        $parts = explode('/', $path);

        switch (count($parts)) {
            case 1:
                // Year level
                if (!preg_match('/^\d{4}$/', $parts[0])) {
                    return null;
                }
                return ['type' => 'year', 'year' => $parts[0]];

            case 2:
                // Month level
                [$year, $month] = $parts;
                if (!preg_match('/^\d{4}$/', $year) || !preg_match('/^\d{2}$/', $month)) {
                    return null;
                }
                return ['type' => 'month', 'year' => $year, 'month' => $month];

            case 3:
                // Day level
                [$year, $month, $day] = $parts;
                if (!preg_match('/^\d{4}$/', $year)
                    || !preg_match('/^\d{2}$/', $month)
                    || !preg_match('/^\d{2}$/', $day)) {
                    return null;
                }
                return ['type' => 'day', 'year' => $year, 'month' => $month, 'day' => $day];

            case 4:
                // Case level — resolve via case number
                [$year, $month, $day, $caseNumber] = $parts;
                $case = $this->caseMapper->findByCaseNumber($caseNumber);
                if ($case === null) {
                    return null;
                }
                return [
                    'type'        => 'case',
                    'year'        => $year,
                    'month'       => $month,
                    'day'         => $day,
                    'case_number' => $caseNumber,
                    'case_id'     => $case->getId(),
                ];

            case 5:
                // File level
                [$year, $month, $day, $caseNumber, $filename] = $parts;
                $case = $this->caseMapper->findByCaseNumber($caseNumber);
                if ($case === null) {
                    return null;
                }
                $file = $this->fileMapper->findByVirtualNameNoAuth($case->getId(), $filename);
                if ($file === null) {
                    return null;
                }
                return [
                    'type'          => 'file',
                    'year'          => $year,
                    'month'         => $month,
                    'day'           => $day,
                    'case_number'   => $caseNumber,
                    'case_id'       => $case->getId(),
                    'file_id'       => $file->getId(),
                    'filename'      => $filename,
                    'physical_path' => $this->physicalPath(
                        $file,
                        $case->getCreatedYear(),
                        $case->getCreatedMonth()
                    ),
                ];

            default:
                return null;
        }
    }

    private function normalizePath(string $path): string {
        return trim(preg_replace('#/+#', '/', $path), '/');
    }

    /**
     * Build the physical storage path for a file.
     *
     * Layout: {baseDir}/{year}/{month}/{case_id}/{document_id}/{storage_filename}
     *
     * Year and month come from the case's created_at (immutable), giving a two-level
     * date prefix that limits the number of entries per directory at scale.
     * The case entity is always loaded before this is called in doResolvePath().
     */
    private function physicalPath($fileEntity, string $year, string $month): string {
        return sprintf(
            '%s/%s/%s/%d/%d/%s',
            $this->baseDir,
            $year,
            $month,
            $fileEntity->getCaseId(),
            $fileEntity->getDocumentId(),
            $fileEntity->getStorageFilename()
        );
    }

    // ---------------------------------------------------------------
    // Directory listing — returns ALL entries (no user filter)
    // ---------------------------------------------------------------

    public function opendir(string $path) {
        $resolved = $this->resolvePath($path);
        if ($resolved === null) {
            return false;
        }

        $entries = match ($resolved['type']) {
            'root'  => $this->caseMapper->getAllCreatedYears(),
            'year'  => $this->caseMapper->getAllCreatedMonthsForYear($resolved['year']),
            'month' => $this->caseMapper->getAllCreatedDaysForYearMonth(
                            $resolved['year'], $resolved['month']
                        ),
            'day'   => array_map(
                            fn($c) => $c->getCaseNumber(),
                            $this->caseMapper->findAllByCreatedDate(
                                $resolved['year'], $resolved['month'], $resolved['day']
                            )
                        ),
            'case'  => array_map(
                            fn($f) => $f->getVirtualFilename(),
                            $this->fileMapper->findByCaseId($resolved['case_id'])
                        ),
            default => null,
        };

        if ($entries === null) {
            return false;
        }

        return IteratorDirectory::wrap($entries);
    }

    // ---------------------------------------------------------------
    // Stat / type checks
    // ---------------------------------------------------------------

    public function stat(string $path): array|false {
        $resolved = $this->resolvePath($path);
        if ($resolved === null) {
            return false;
        }

        if ($resolved['type'] === 'file') {
            $physicalPath = $resolved['physical_path'];
            if (file_exists($physicalPath)) {
                $stat = stat($physicalPath);
                return ['mtime' => $stat['mtime'], 'size' => $stat['size']];
            }
            // File is in DB but physical file is missing — return synthetic values
            // so stat() stays consistent with file_exists() (which checks DB only).
            return ['mtime' => time(), 'size' => 0];
        }

        return ['mtime' => time(), 'size' => 0];
    }

    public function filetype(string $path): string|false {
        $resolved = $this->resolvePath($path);
        if ($resolved === null) {
            return false;
        }
        return $resolved['type'] === 'file' ? 'file' : 'dir';
    }

    public function file_exists(string $path): bool {
        return $this->resolvePath($path) !== null;
    }

    public function is_dir(string $path): bool {
        $resolved = $this->resolvePath($path);
        return $resolved !== null && $resolved['type'] !== 'file';
    }

    public function is_file(string $path): bool {
        $resolved = $this->resolvePath($path);
        return $resolved !== null && $resolved['type'] === 'file';
    }

    public function filesize(string $path): int|float|false {
        $resolved = $this->resolvePath($path);
        if ($resolved === null) {
            return false;
        }
        if ($resolved['type'] !== 'file') {
            return 0; // virtual directories always report size 0
        }
        $p = $resolved['physical_path'];
        // Return 0 when physical file is absent — false would be bound as '' by
        // NC's QueryBuilder (PARAM_STR default) and rejected by MySQL strict mode.
        return file_exists($p) ? filesize($p) : 0;
    }

    // ---------------------------------------------------------------
    // File I/O — no permission checks (enforced by wrapper)
    // ---------------------------------------------------------------

    public function fopen(string $path, string $mode) {
        $resolved = $this->resolvePath($path);
        if ($resolved === null || $resolved['type'] !== 'file') {
            return false;
        }

        $physicalPath = $resolved['physical_path'];
        $isWrite      = in_array($mode, ['w', 'wb', 'w+', 'a', 'ab', 'r+', 'rb+'], true);

        if (!$isWrite && !file_exists($physicalPath)) {
            return false;
        }

        if ($isWrite) {
            $dir = dirname($physicalPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0770, true);
            }
        }

        return fopen($physicalPath, $mode);
    }

    public function file_get_contents(string $path): string|false {
        $handle = $this->fopen($path, 'r');
        if ($handle === false) {
            return false;
        }
        $content = stream_get_contents($handle);
        fclose($handle);
        return $content;
    }

    public function file_put_contents(string $path, mixed $data): int|float|false {
        $resolved = $this->resolvePath($path);
        if ($resolved === null || $resolved['type'] !== 'file') {
            return false;
        }

        $physicalPath = $resolved['physical_path'];
        $dir          = dirname($physicalPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }

        $result = file_put_contents($physicalPath, $data);

        if ($result !== false) {
            $this->fileMapper->updateFileStats($resolved['file_id'], (int)$result, time());
            $this->eventDispatcher->dispatchTyped(new FileUpdatedEvent($resolved['file_id']));
        }

        return $result;
    }

    // ---------------------------------------------------------------
    // Modification operations — not supported via the virtual filesystem
    // ---------------------------------------------------------------

    public function mkdir(string $path): bool  { return false; }
    public function rmdir(string $path): bool  { return false; }
    public function unlink(string $path): bool { return false; }
    public function rename(string $source, string $target): bool { return false; }
    public function copy(string $source, string $target): bool   { return false; }

    public function touch(string $path, ?int $mtime = null): bool {
        $resolved = $this->resolvePath($path);
        if ($resolved === null || $resolved['type'] !== 'file') {
            return false;
        }
        return touch($resolved['physical_path'], $mtime);
    }

    // ---------------------------------------------------------------
    // Permissions — return maximum possible; wrapper enforces per-user
    // ---------------------------------------------------------------

    public function getPermissions(string $path): int {
        $resolved = $this->resolvePath($path);
        if ($resolved === null) {
            return 0;
        }
        if ($resolved['type'] !== 'file') {
            return \OCP\Constants::PERMISSION_READ;
        }
        // Maximum possible for files: read + update (no create/delete via FS)
        return \OCP\Constants::PERMISSION_READ | \OCP\Constants::PERMISSION_UPDATE;
    }

    // ---------------------------------------------------------------
    // Owner — not set here; the permission wrapper returns the mount user
    // ---------------------------------------------------------------

    public function getOwner(string $path): string|false {
        return false;
    }

    // ---------------------------------------------------------------
    // ETag and change detection
    // ---------------------------------------------------------------

    public function getETag(string $path): string|false {
        $resolved = $this->resolvePath($path);
        if ($resolved === null) {
            return false;
        }

        if ($resolved['type'] === 'file') {
            $physicalPath = $resolved['physical_path'];
            if (!file_exists($physicalPath)) {
                return false;
            }
            $stat = stat($physicalPath);
            return md5($stat['mtime'] . ':' . $stat['size']);
        }

        // Virtual directories: time-slotted ETag (refreshes every 60 s)
        return md5($path . ':' . (int)(time() / 60));
    }

    // ---------------------------------------------------------------
    // MIME type
    // ---------------------------------------------------------------

    public function getMimeType(string $path): string|false {
        $resolved = $this->resolvePath($path);
        if ($resolved === null) {
            return false;
        }
        if ($resolved['type'] !== 'file') {
            return 'httpd/unix-directory';
        }
        return \OCP\Server::get(\OCP\Files\IMimeTypeDetector::class)->detectPath($resolved['filename']);
    }

    // ---------------------------------------------------------------
    // Scanner and cache
    // ---------------------------------------------------------------

    public function getScanner(string $path = '', ?IStorage $storage = null): IScanner {
        return new OpenCaseScanner($storage ?? $this);
    }

    public function getCache(string $path = '', ?IStorage $storage = null): ICache {
        return parent::getCache($path, $storage ?? $this);
    }

    // ---------------------------------------------------------------
    // Storage properties
    // ---------------------------------------------------------------

    public function needsPartFile(): bool { return false; }
    public function isLocal(): bool       { return false; }

    public function hasUpdated(string $path, int $time): bool {
        $resolved = $this->resolvePath($path);
        if ($resolved === null) {
            return false;
        }
        if ($resolved['type'] === 'file') {
            $p = $resolved['physical_path'];
            return file_exists($p) && filemtime($p) > $time;
        }
        // Virtual directories never change externally — the filecache is the
        // authoritative source for their contents. Returning true here would
        // trigger a full rescan on every directory access, which resets
        // filecache permissions via Scanner::scanFile() using the raw storage.
        return false;
    }
}
