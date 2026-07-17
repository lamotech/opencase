<?php

declare(strict_types=1);

namespace OCA\OpenCase\Storage;

use OC\Files\Storage\Wrapper\Wrapper;
use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Service\PermissionService;
use OCP\Files\Cache\ICache;
use OCP\Files\Storage\IStorage;
use OCP\IUser;

/**
 * OpenCasePermissionWrapper — per-user ACL enforcement layer.
 *
 * Wraps the shared OpenCaseStorage with user-specific access control.
 * Every user gets their own wrapper instance pointing at the SAME underlying
 * OpenCaseStorage (same storageId = 'opencase', same oc_filecache rows).
 *
 * Virtual tree (path depth = number of parts):
 *
 *   depth 0  ''                                  root
 *   depth 1  {year}                              year directory
 *   depth 2  {year}/{month}                      month directory
 *   depth 3  {year}/{month}/{day}                day directory
 *   depth 4  {year}/{month}/{day}/{case_number}  case directory
 *   depth 5  {year}/{month}/{day}/{case}/{file}  file
 *
 * Responsibilities:
 *  - opendir()        Filter directory listing to entries this user may see
 *  - getPermissions() Return read/write flags based on user's access profile
 *  - fopen()          Guard file I/O against the user's actual permissions
 *  - getOwner()       Report the mount user as owner (mimics Group Folders)
 *  - getCache()       Return OpenCaseCacheWrapper so PROPFIND is also filtered
 *
 * All path-parsing and DB look-ups use PermissionService and CaseMapper.
 * No business logic lives here — only access-control decisions.
 */
class OpenCasePermissionWrapper extends Wrapper {

    /** Cached access profile IDs for the current user (request-scoped). */
    private ?array $profileIds = null;

    private IUser $user;
    private PermissionService $permissionService;
    private CaseMapper $caseMapper;

    public function __construct(array $params) {
        parent::__construct($params);
        $this->user              = $params['user'];              // IUser
        $this->permissionService = $params['permissionService']; // PermissionService
        $this->caseMapper        = $params['caseMapper'];        // CaseMapper
    }

    // Keep same storageId as the shared storage — crucial for cache lookups.
    public function getId(): string {
        return $this->storage->getId(); // 'opencase'
    }

    /**
     * Override getScanner() to pass $this (the permission wrapper) as the storage
     * argument, not the inner OpenCaseStorage. This ensures that when NC's Scanner
     * calls $storage->getPermissions($path) during a file scan it goes through
     * our wrapper — which returns READ-only for files in Final documents — instead
     * of OpenCaseStorage::getPermissions() which always returns READ|UPDATE.
     *
     * Without this, the NC cache watcher triggers a rescan on every file access
     * (because hasUpdated() returns true for directories) and the scanner resets
     * filecache permissions back to 3 on every open.
     */
    public function getScanner(string $path = '', ?\OCP\Files\Storage\IStorage $storage = null): \OCP\Files\Cache\IScanner {
        return $this->storage->getScanner($path, $storage ?? $this);
    }

    // ---------------------------------------------------------------
    // Owner — the mount user, consistent with Group Folders pattern
    // ---------------------------------------------------------------

    public function getOwner(string $path): string|false {
        return $this->user->getUID();
    }

    // ---------------------------------------------------------------
    // Cache — wrap with per-user filtering
    // ---------------------------------------------------------------

    public function getCache(string $path = '', ?IStorage $storage = null): ICache {
        $inner = $this->storage->getCache($path, $storage ?? $this->storage);
        return new OpenCaseCacheWrapper($inner, $this->user, $this->permissionService);
    }

    // ---------------------------------------------------------------
    // Directory listing — filter to what this user may see
    // ---------------------------------------------------------------

    public function opendir(string $path) {
        $handle = $this->storage->opendir($path);
        if ($handle === false) {
            return false;
        }

        $entries = [];
        while (($entry = readdir($handle)) !== false) {
            if ($entry !== '.' && $entry !== '..') {
                $entries[] = $entry;
            }
        }
        closedir($handle);

        $filtered = $this->filterEntries($path, $entries);
        return IteratorDirectory::wrap($filtered);
    }

    // ---------------------------------------------------------------
    // Permissions — per-user access level
    // ---------------------------------------------------------------

    public function getPermissions(string $path): int {
        $normalized = trim($path, '/');
        $parts      = ($normalized === '') ? [] : explode('/', $normalized);
        $depth      = count($parts);

        // Root/year/month/day (depths 0–3): READ only. Must be resolvable so
        // NC can walk the path during the /f/{id} → Collabora open flow.
        // getFolderContentsById() returns [] for these levels so the Files app
        // Folder Tree cannot enumerate their children (browsing blocked).
        if ($depth < 4) {
            return \OCP\Constants::PERMISSION_READ;
        }

        if ($depth === 4) {
            return $this->caseDirPermissions($this->user->getUID(), $parts[3]);
        }

        // depth 5 = actual file: enforce per-user ACL
        return $this->filePermissions($this->user->getUID(), $parts[3], $parts[4]);
    }

    public function isReadable(string $path): bool {
        return ($this->getPermissions($path) & \OCP\Constants::PERMISSION_READ) !== 0;
    }

    public function isUpdatable(string $path): bool {
        return ($this->getPermissions($path) & \OCP\Constants::PERMISSION_UPDATE) !== 0;
    }

    // ---------------------------------------------------------------
    // File I/O — guard with permission check before delegating
    // ---------------------------------------------------------------

    public function fopen(string $path, string $mode) {
        $isWrite = in_array($mode, ['w', 'wb', 'w+', 'a', 'ab', 'r+', 'rb+'], true);
        $needed  = $isWrite
            ? \OCP\Constants::PERMISSION_UPDATE
            : \OCP\Constants::PERMISSION_READ;

        if (($this->getPermissions($path) & $needed) === 0) {
            return false;
        }

        return $this->storage->fopen($path, $mode);
    }

    public function file_put_contents(string $path, mixed $data): int|float|false {
        if (!($this->getPermissions($path) & \OCP\Constants::PERMISSION_UPDATE)) {
            return false;
        }
        return $this->storage->file_put_contents($path, $data);
    }

    public function touch(string $path, ?int $mtime = null): bool {
        if (!($this->getPermissions($path) & \OCP\Constants::PERMISSION_UPDATE)) {
            return false;
        }
        return $this->storage->touch($path, $mtime);
    }

    // ---------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------

    private function getProfileIds(): array {
        if ($this->profileIds === null) {
            $this->profileIds = $this->permissionService
                ->getAccessProfileIdsForUser($this->user->getUID());
        }
        return $this->profileIds;
    }

    /**
     * Filter a list of child names at `$parentPath` to those visible to the user.
     *
     * @param string   $parentPath  Normalised parent path ('' = root)
     * @param string[] $entries     Raw child names from the underlying storage
     * @return string[]
     */
    private function filterEntries(string $parentPath, array $entries): array {
        $uid             = $this->user->getUID();
        $hasProfiles     = !empty($this->getProfileIds());
        $hasCaseAccess   = !$hasProfiles && $this->permissionService->userHasAnyCaseAccess($uid);
        $hasDirectAccess = $hasProfiles || $hasCaseAccess;
        $hasShares       = !$hasDirectAccess && $this->permissionService->userHasAnyFileShare($uid);

        if (!$hasDirectAccess && !$hasShares) {
            return [];
        }

        $parentPath = trim($parentPath, '/');
        $depth      = ($parentPath === '') ? 0 : (substr_count($parentPath, '/') + 1);

        // Build the set of allowed entries, combining profile/case-based and share-based results.
        $allowed = [];

        switch ($depth) {
            case 0: // root → filter year dirs
                if ($hasDirectAccess) {
                    foreach ($entries as $e) {
                        if ($this->permissionService->userHasYearAccess($uid, $e)) {
                            $allowed[$e] = true;
                        }
                    }
                }
                if ($hasShares) {
                    foreach ($this->permissionService->getShareRecipientVisibleYears($uid) as $yr) {
                        $allowed[$yr] = true;
                    }
                }
                break;

            case 1: // year → filter month dirs
                if ($hasDirectAccess) {
                    foreach ($entries as $month) {
                        if ($this->permissionService->userHasMonthAccess($uid, $parentPath, $month)) {
                            $allowed[$month] = true;
                        }
                    }
                }
                if ($hasShares) {
                    foreach ($this->permissionService->getShareRecipientVisibleMonths($uid, $parentPath) as $mo) {
                        $allowed[$mo] = true;
                    }
                }
                break;

            case 2: // month → filter day dirs
                if ($hasDirectAccess) {
                    foreach ($this->filterDays($uid, $parentPath, $entries) as $d) {
                        $allowed[$d] = true;
                    }
                }
                if ($hasShares) {
                    [$year, $month] = explode('/', $parentPath, 2);
                    foreach ($this->permissionService->getShareRecipientVisibleDays($uid, $year, $month) as $d) {
                        $allowed[$d] = true;
                    }
                }
                break;

            case 3: // day → filter case dirs
                if ($hasDirectAccess) {
                    foreach ($this->filterCaseDirs($uid, $parentPath, $entries) as $cn) {
                        $allowed[$cn] = true;
                    }
                }
                if ($hasShares) {
                    [$year, $month, $day] = explode('/', $parentPath, 3);
                    foreach ($this->permissionService->getShareRecipientVisibleCases($uid, $year, $month, $day) as $cn) {
                        $allowed[$cn] = true;
                    }
                }
                break;

            case 4: // case dir → filter files
                if ($hasDirectAccess) {
                    foreach ($this->filterFiles($uid, $parentPath, $entries) as $f) {
                        $allowed[$f] = true;
                    }
                }
                if ($hasShares) {
                    $parts      = explode('/', $parentPath, 4);
                    $caseNumber = $parts[3] ?? '';
                    $caseId     = $this->permissionService->getCaseIdByCaseNumber($caseNumber);
                    if ($caseId !== null) {
                        // Only add share-based files when the user lacks direct case access.
                        $userHasCaseReadAccess = $hasDirectAccess
                            && $this->permissionService->userHasReadAccessToCase($uid, $caseId);
                        if (!$userHasCaseReadAccess) {
                            foreach (array_keys($this->permissionService->getShareRecipientVisibleFilenames($uid, $caseId)) as $fn) {
                                $allowed[$fn] = true;
                            }
                        }
                    }
                }
                break;

            default:
                return [];
        }

        return array_values(array_filter($entries, fn($e) => isset($allowed[$e])));
    }

    /**
     * Filter day dirs in a month path (e.g. "2026/03").
     *
     * @param string   $yearMonth  "year/month" path
     * @param string[] $days       Day dir names (e.g. "01", "15")
     * @return string[]
     */
    private function filterDays(string $uid, string $yearMonth, array $days): array {
        [$year, $month] = explode('/', $yearMonth, 2);
        return array_values(array_filter(
            $days,
            fn($day) => $this->permissionService->userHasDayAccess($uid, $year, $month, $day)
        ));
    }

    /**
     * Filter case dir names (case numbers) in a day path (e.g. "2026/03/01").
     *
     * @param string   $dayPath     "year/month/day" path
     * @param string[] $caseNumbers Virtual case dir names == case numbers
     * @return string[]
     */
    private function filterCaseDirs(string $uid, string $dayPath, array $caseNumbers): array {
        [$year, $month, $day] = explode('/', $dayPath, 3);
        $accessMap = $this->permissionService->filterAccessibleCaseDirsOnDate(
            $uid, $year, $month, $day, $caseNumbers
        );
        return array_values(array_keys($accessMap));
    }

    /**
     * Filter file names in a case dir, removing any with explicit deny overrides.
     *
     * @param string   $casePath  "year/month/day/case_number" path
     * @param string[] $files     Virtual filenames
     * @return string[]
     */
    private function filterFiles(string $uid, string $casePath, array $files): array {
        $parts      = explode('/', $casePath, 4);
        $caseNumber = $parts[3] ?? '';

        $caseId = $this->permissionService->getCaseIdByCaseNumber($caseNumber);

        if ($caseId === null) {
            return [];
        }
        if (!$this->permissionService->userHasReadAccessToCase($uid, $caseId)) {
            return [];
        }

        $denied = array_flip($this->permissionService->getDeniedFilenamesForUser($uid, $caseId));
        return array_values(array_filter($files, fn($f) => !isset($denied[$f])));
    }

    /** Permissions for a case directory. */
    private function caseDirPermissions(string $uid, string $caseNumber): int {
        $caseId = $this->permissionService->getCaseIdByCaseNumber($caseNumber);
        if ($caseId === null) {
            return 0;
        }
        if ($this->permissionService->userHasReadAccessToCase($uid, $caseId)) {
            return \OCP\Constants::PERMISSION_READ;
        }
        // Allow traversal when the user has a file-level share inside this case.
        if ($this->permissionService->userHasFileShareInCase($uid, $caseId)) {
            return \OCP\Constants::PERMISSION_READ;
        }
        return 0;
    }

    /** Permissions for a file. */
    private function filePermissions(string $uid, string $caseNumber, string $filename): int {
        $caseId = $this->permissionService->getCaseIdByCaseNumber($caseNumber);
        if ($caseId === null) {
            return 0;
        }

        // Case-level access (responsible user / direct grant / profile).
        if ($this->permissionService->userHasReadAccessToCase($uid, $caseId)) {
            $perms = \OCP\Constants::PERMISSION_READ;
            if (
                !$this->permissionService->isFileInFinalDocument($caseNumber, $filename)
                && $this->permissionService->userHasWriteAccessToCase($uid, $caseId)
            ) {
                $perms |= \OCP\Constants::PERMISSION_UPDATE;
            }
            return $perms;
        }

        // File-level share access — user has no case access but may have a direct share.
        return $this->permissionService->getFileSharePermissionsByFilename($uid, $caseId, $filename);
    }
}
