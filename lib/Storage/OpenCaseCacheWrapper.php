<?php

declare(strict_types=1);

namespace OCA\OpenCase\Storage;

use OC\Files\Cache\Wrapper\CacheWrapper;
use OC\Files\Search\SearchComparison;
use OCA\OpenCase\Service\PermissionService;
use OCP\Files\Cache\ICache;
use OCP\Files\Cache\ICacheEntry;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchOperator;
use OCP\IUser;

/**
 * OpenCaseCacheWrapper — per-user filter for oc_filecache queries.
 *
 * The shared OpenCaseStorage keeps ONE filecache row per file (no per-user
 * duplication). This wrapper sits between NC's DAV/Files layer and the raw
 * filecache, filtering and masking entries so each user only sees what their
 * access profiles permit.
 *
 * Why this is needed:
 *   NC's PROPFIND, search, and file browser go through ICache directly (not
 *   through IStorage::opendir). Filtering at the storage level alone is not
 *   enough — the cache must be filtered too.
 *
 * Virtual tree (6 depth levels, 0-indexed by path part count):
 *
 *   depth 0  ''                                  root
 *   depth 1  {year}                              year directory
 *   depth 2  {year}/{month}                      month directory
 *   depth 3  {year}/{month}/{day}                day directory
 *   depth 4  {year}/{month}/{day}/{case_number}  case directory
 *   depth 5  {year}/{month}/{day}/{case}/{file}  file
 *
 * Strategy:
 *   - formatCacheEntry()      Per-entry filter + permission mask; used by
 *                             get() / getById() (single-entry lookups).
 *   - getFolderContentsById() Batch override for directory listings:
 *                             one DB query per depth level instead of N
 *                             individual queries for N entries.
 *
 * Caching within a request:
 *   Case IDs and denied filename sets are memoised in PHP arrays so that
 *   listing a case dir with 1000 files costs 2 DB queries (not 1000).
 */
class OpenCaseCacheWrapper extends CacheWrapper {

    /** Memoised case IDs: case_number → int|null */
    private array $caseIdCache = [];

    /** Memoised denied filenames: "$uid:$caseId" → string[] */
    private array $deniedCache = [];

    /** Memoised write access: "$uid:$caseId" → bool */
    private array $writeCache = [];

    /** Memoised Final-document filenames: caseId → string[] (flipped for O(1) lookup) */
    private array $finalCache = [];

    public function __construct(
        ICache $cache,
        private readonly IUser $user,
        private readonly PermissionService $permissionService,
    ) {
        parent::__construct($cache);
    }

    // ---------------------------------------------------------------
    // Global search exclusion
    // ---------------------------------------------------------------

    /**
     * Return a filter that matches no storage, excluding OpenCase files from
     * NC's core FilesSearchProvider (which shows raw virtual paths like
     * "in .Sager/2026/03/08/2026-005").
     *
     * Our OpenCaseFilesSearchProvider handles global search separately,
     * showing "OpenCase sagsnummer XXXX" as the subline instead.
     */
    public function getQueryFilterForStorage(): ISearchOperator {
        return new SearchComparison(ISearchComparison::COMPARE_EQUAL, 'storage', -1);
    }

    // ---------------------------------------------------------------
    // Single-entry filtering (used by get() / getById() via parent)
    //
    // NOTE: CacheWrapper::get() passes an ICacheEntry object here.
    // CacheEntry implements ArrayAccess so $entry['key'] works for
    // reads AND writes (offsetSet stores back into the internal array).
    // ---------------------------------------------------------------

    /**
     * Filter and permission-mask a single cache entry.
     *
     * Returns the (possibly modified) entry, or false to hide the entry.
     *
     * @param ICacheEntry $entry
     * @return ICacheEntry|false
     */
    protected function formatCacheEntry($entry) {
        $path  = $entry['path'] ?? '';
        $parts = ($path === '') ? [] : explode('/', $path);
        $depth = count($parts);

        $uid             = $this->user->getUID();
        $profileIds      = $this->permissionService->getAccessProfileIdsForUser($uid);
        $hasProfiles     = !empty($profileIds);
        $hasCaseAccess   = !$hasProfiles && $this->permissionService->userHasAnyCaseAccess($uid);
        $hasDirectAccess = $hasProfiles || $hasCaseAccess;
        $hasShares       = !$hasDirectAccess && $this->permissionService->userHasAnyFileShare($uid);

        if (!$hasDirectAccess && !$hasShares) {
            return false;
        }

        // Root/year/month/day (depths 0–3): must be resolvable so NC can walk
        // the path segment-by-segment to reach the case dir during the /f/{id}
        // Collabora open flow. Write bits are stripped — they are read-only
        // containers. getFolderContentsById() returns [] for these levels so
        // the Files app Folder Tree cannot enumerate their children.
        if ($depth < 4) {
            $entry['permissions'] &= ~(
                \OCP\Constants::PERMISSION_UPDATE |
                \OCP\Constants::PERMISSION_CREATE |
                \OCP\Constants::PERMISSION_DELETE
            );
            return $entry;
        }

        if ($depth === 4) {
            return $this->formatCaseEntry($entry, $parts, $uid);
        }

        return $this->formatFileEntry($entry, $parts, $uid);
    }

    // ---------------------------------------------------------------
    // Batch directory listing (main optimisation for PROPFIND)
    // ---------------------------------------------------------------

    /**
     * Return filtered + masked children of a directory (by its fileid).
     *
     * Overrides the parent to enable batch DB queries per depth level,
     * avoiding N round-trips for N entries in a directory.
     *
     * @return ICacheEntry[]
     */
    public function getFolderContentsById($fileId, $mimeTypeFilter = null): array {
        // Fetch raw ICacheEntry[] from the underlying cache (no filtering yet)
        $raw = $this->cache->getFolderContentsById($fileId, $mimeTypeFilter);
        if (empty($raw)) {
            return [];
        }

        $uid             = $this->user->getUID();
        $profileIds      = $this->permissionService->getAccessProfileIdsForUser($uid);
        $hasProfiles     = !empty($profileIds);
        $hasCaseAccess   = !$hasProfiles && $this->permissionService->userHasAnyCaseAccess($uid);
        $hasDirectAccess = $hasProfiles || $hasCaseAccess;
        $hasShares       = !$hasDirectAccess && $this->permissionService->userHasAnyFileShare($uid);

        if (!$hasDirectAccess && !$hasShares) {
            return [];
        }

        // Determine depth of children from the first entry's path.
        // ICacheEntry implements ArrayAccess so $entry['path'] calls offsetGet().
        $samplePath = $raw[0]['path'] ?? '';
        $depth      = ($samplePath === '') ? 0 : (substr_count($samplePath, '/') + 1);

        /*
            DIRECTORY LISTING STRATEGY:
            Can you block it entirely? 
            Yes — change line 163-165 to default => [] (drop the case 5). Effect per client:

            Client	Impact
            Files app (URL navigation):	Case dir shows empty — users can't browse files there
            Collabora/WOPI:	Not broken — opens file by numeric ID via WOPI, never uses directory listing
            WebDAV direct path: (/dav/files/user/.Sager/.../file.docx)	Not broken — resolves by path, not listing
            /f/{id} open flow	Collabora still opens; Files app context shows empty folder (minor UX quirk)
            My recommendation: Keep the current behavior. 
            The listing is already ACL-filtered — it's not a security gap, just a feature (authorized users can browse their files). Blocking it gains nothing security-wise and introduces a minor UX issue with the Collabora context view.

            If you do want to block it for UX reasons 
            (force users to go through the OpenCase UI only), 
            the change is minimal — but verify the Collabora open-file flow still works as expected in your environment since the /f/{id} redirect behavior can vary slightly between NC versions.
        */

        // Only file entries (depth 5) are exposed. Directory listings are
        // suppressed entirely so the Files app folder tree cannot be browsed.
        return array_values(match ($depth) {
            5       => $this->batchFilterFiles($raw, $uid),
            default => [],
        });
    }

    // ---------------------------------------------------------------
    // Batch filters (one query per directory listing)
    // ---------------------------------------------------------------

    /** Filter year dirs: keep those the user has ≥1 accessible case in. */
    private function batchFilterYears(array $entries, string $uid): array {
        return array_filter(
            $entries,
            fn($e) => $this->permissionService->userHasYearAccess($uid, (string)$e['name'])
        );
    }

    /** Filter month dirs: keep those with ≥1 accessible case. */
    private function batchFilterMonths(array $entries, string $uid): array {
        return array_filter($entries, function ($e) use ($uid) {
            $parts = explode('/', (string)$e['path']);
            return count($parts) >= 2
                && $this->permissionService->userHasMonthAccess($uid, $parts[0], $parts[1]);
        });
    }

    /** Filter day dirs: keep those with ≥1 accessible case. */
    private function batchFilterDays(array $entries, string $uid): array {
        return array_filter($entries, function ($e) use ($uid) {
            $parts = explode('/', (string)$e['path']);
            return count($parts) >= 3
                && $this->permissionService->userHasDayAccess($uid, $parts[0], $parts[1], $parts[2]);
        });
    }

    /**
     * Filter case dirs and mask write permission where needed.
     *
     * One batch DB query resolves all case numbers in this day.
     */
    private function batchFilterCases(array $entries, string $uid): array {
        if (empty($entries)) {
            return [];
        }

        // All entries share the same year/month/day — extract from the first path.
        $firstPath = reset($entries)['path'] ?? '';
        $parts     = explode('/', $firstPath, 5);
        if (count($parts) < 4) {
            return [];
        }
        [$year, $month, $day] = $parts;

        // Case dir name == case number directly
        $caseNumbers = array_map(fn($e) => (string)$e['name'], $entries);
        $accessMap   = $this->permissionService->filterAccessibleCaseDirsOnDate(
            $uid, $year, $month, $day, $caseNumbers
        );
        // $accessMap: case_number → 'read'|'write'

        $result = [];
        foreach ($entries as $entry) {
            $level = $accessMap[(string)$entry['name']] ?? null;
            if ($level === null) {
                continue; // not accessible
            }
            if ($level !== 'write') {
                $entry['permissions'] &= ~(
                    \OCP\Constants::PERMISSION_UPDATE |
                    \OCP\Constants::PERMISSION_CREATE |
                    \OCP\Constants::PERMISSION_DELETE
                );
            }
            $result[] = $entry;
        }
        return $result;
    }

    /**
     * Filter files in a case dir and mask write permission where needed.
     *
     * One case-access check + one denied-list query for all files.
     */
    private function batchFilterFiles(array $entries, string $uid): array {
        if (empty($entries)) {
            return [];
        }

        // All files share the same parent case — extract case_number from path.
        $firstPath = reset($entries)['path'] ?? '';
        $parts     = explode('/', $firstPath, 6);
        if (count($parts) < 5) {
            return [];
        }
        $caseNumber = $parts[3];

        $caseId = $this->getCaseIdMemo($caseNumber);
        if ($caseId === null) {
            return [];
        }

        if ($this->permissionService->userHasReadAccessToCase($uid, $caseId)) {
            $canWrite   = $this->getWriteMemo($uid, $caseId);
            $denied     = array_flip($this->getDeniedMemo($uid, $caseId));
            $finalFiles = $this->getFinalMemo($caseId);

            $result = [];
            foreach ($entries as $entry) {
                $name = (string)$entry['name'];
                if (isset($denied[$name])) {
                    continue;
                }
                if (!$canWrite || isset($finalFiles[$name])) {
                    $entry['permissions'] &= ~(
                        \OCP\Constants::PERMISSION_UPDATE |
                        \OCP\Constants::PERMISSION_CREATE |
                        \OCP\Constants::PERMISSION_DELETE
                    );
                }
                $result[] = $entry;
            }
            return $result;
        }

        // Share-recipient path: expose only specifically shared files.
        $sharedFiles = $this->permissionService->getShareRecipientVisibleFilenames($uid, $caseId);
        if (empty($sharedFiles)) {
            return [];
        }

        $result = [];
        foreach ($entries as $entry) {
            $name = (string)$entry['name'];
            if (!isset($sharedFiles[$name])) {
                continue;
            }
            // File shares grant access independently of the case's profile-based
            // ceiling (opencase_file_shares works "without case access" by design),
            // so the UPDATE bit must be set from the share itself, not just masked
            // out of whatever the ceiling happens to already contain.
            $sharePerms = $sharedFiles[$name];
            $entry['permissions'] = ($entry['permissions'] & ~(
                \OCP\Constants::PERMISSION_UPDATE |
                \OCP\Constants::PERMISSION_CREATE |
                \OCP\Constants::PERMISSION_DELETE
            )) | ($sharePerms & \OCP\Constants::PERMISSION_UPDATE);
            $result[] = $entry;
        }
        return $result;
    }

    // ---------------------------------------------------------------
    // Single-entry helpers (called from formatCacheEntry)
    // ---------------------------------------------------------------

    private function formatCaseEntry(ICacheEntry $entry, array $parts, string $uid): ICacheEntry|false {
        // parts = [year, month, day, case_number]
        $caseNumber = $parts[3];
        $caseId     = $this->getCaseIdMemo($caseNumber);

        if ($caseId === null) {
            return false;
        }

        if ($this->permissionService->userHasReadAccessToCase($uid, $caseId)) {
            if (!$this->getWriteMemo($uid, $caseId)) {
                $entry['permissions'] &= ~(
                    \OCP\Constants::PERMISSION_UPDATE |
                    \OCP\Constants::PERMISSION_CREATE |
                    \OCP\Constants::PERMISSION_DELETE
                );
            }
            return $entry;
        }

        // Share-recipient: readable if they have any file share inside this case.
        if ($this->permissionService->userHasFileShareInCase($uid, $caseId)) {
            $entry['permissions'] &= ~(
                \OCP\Constants::PERMISSION_UPDATE |
                \OCP\Constants::PERMISSION_CREATE |
                \OCP\Constants::PERMISSION_DELETE
            );
            return $entry;
        }

        return false;
    }

    private function formatFileEntry(ICacheEntry $entry, array $parts, string $uid): ICacheEntry|false {
        // parts = [year, month, day, case_number, filename]
        $caseNumber = $parts[3];
        $filename   = $parts[4];
        $caseId     = $this->getCaseIdMemo($caseNumber);

        if ($caseId === null) {
            return false;
        }

        if ($this->permissionService->userHasReadAccessToCase($uid, $caseId)) {
            $denied = array_flip($this->getDeniedMemo($uid, $caseId));
            if (isset($denied[$filename])) {
                return false;
            }

            $finalFiles = $this->getFinalMemo($caseId);
            if (!$this->getWriteMemo($uid, $caseId) || isset($finalFiles[$filename])) {
                $entry['permissions'] &= ~(
                    \OCP\Constants::PERMISSION_UPDATE |
                    \OCP\Constants::PERMISSION_CREATE |
                    \OCP\Constants::PERMISSION_DELETE
                );
            }
            return $entry;
        }

        // Share-recipient path: check for a direct file share. File shares grant
        // access independently of the case's profile-based ceiling, so the UPDATE
        // bit must be set from the share itself, not just masked out of whatever
        // the ceiling happens to already contain.
        $sharePerms = $this->permissionService->getFileSharePermissionsByFilename($uid, $caseId, $filename);
        if ($sharePerms === 0) {
            return false;
        }
        $entry['permissions'] = ($entry['permissions'] & ~(
            \OCP\Constants::PERMISSION_UPDATE |
            \OCP\Constants::PERMISSION_CREATE |
            \OCP\Constants::PERMISSION_DELETE
        )) | ($sharePerms & \OCP\Constants::PERMISSION_UPDATE);
        return $entry;
    }

    // ---------------------------------------------------------------
    // Memoisation helpers (request-scoped caches)
    // ---------------------------------------------------------------

    private function getCaseIdMemo(string $caseNumber): ?int {
        if (!array_key_exists($caseNumber, $this->caseIdCache)) {
            $this->caseIdCache[$caseNumber] = $this->permissionService
                ->getCaseIdByCaseNumber($caseNumber);
        }
        return $this->caseIdCache[$caseNumber];
    }

    private function getDeniedMemo(string $uid, int $caseId): array {
        $key = "$uid:$caseId";
        if (!array_key_exists($key, $this->deniedCache)) {
            $this->deniedCache[$key] = $this->permissionService
                ->getDeniedFilenamesForUser($uid, $caseId);
        }
        return $this->deniedCache[$key];
    }

    private function getWriteMemo(string $uid, int $caseId): bool {
        $key = "$uid:$caseId";
        if (!array_key_exists($key, $this->writeCache)) {
            $this->writeCache[$key] = $this->permissionService
                ->userHasWriteAccessToCase($uid, $caseId);
        }
        return $this->writeCache[$key];
    }

    /**
     * Return a flipped array of Final-document filenames for a case (O(1) lookup).
     *
     * @return array<string, int>  filename → 0 (values are irrelevant; isset() is used)
     */
    private function getFinalMemo(int $caseId): array {
        if (!array_key_exists($caseId, $this->finalCache)) {
            $filenames = $this->permissionService
                ->getFinalDocumentFilenamesForCase($caseId);
            $this->finalCache[$caseId] = array_flip($filenames);
        }
        return $this->finalCache[$caseId];
    }
}
