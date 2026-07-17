<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\AccessProfileMapper;
use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\CaseUserEntity;
use OCA\OpenCase\Db\CaseUserMapper;
use OCA\OpenCase\Db\FileShareMapper;
use OCA\OpenCase\Db\UserAccessMapper;
use OCP\IDBConnection;
use OCP\IMemcache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Central permission service for OpenCase.
 *
 * Access resolution order for a case:
 *   1. Responsible user  — cases.responsible_user_id = userId → always write
 *   2. Direct grant      — opencase_case_users row (can expire)
 *   3. Profile-based     — user holds the case's access_profile_id via opencase_user_access
 *
 * Caching strategy:
 *   - User access profile sets are cached in Nextcloud's distributed cache
 *     (memcached/redis) with a TTL of 5 minutes.
 *   - Cache is invalidated when user access assignments change.
 */
class PermissionService {

    private const CACHE_PREFIX = 'opencase/perms/';
    private const CACHE_TTL = 300; // 5 minutes

    private ?IMemcache $cache;

    public function __construct(
        private UserAccessMapper $userAccessMapper,
        private AccessProfileMapper $accessProfileMapper,
        private CaseMapper $caseMapper,
        private CaseUserMapper $caseUserMapper,
        private FileShareMapper $fileShareMapper,
        private IDBConnection $db,
        private ICacheFactory $cacheFactory,
        private LoggerInterface $logger,
    ) {
        $this->cache = $cacheFactory->isAvailable()
            ? $cacheFactory->createDistributed(self::CACHE_PREFIX)
            : null;
    }

    // ---------------------------------------------------------------
    // User → Access Profiles
    // ---------------------------------------------------------------

    /**
     * Get all access profile IDs a user has been granted.
     *
     * @return int[]
     */
    public function getAccessProfileIdsForUser(string $userId): array {
        $cacheKey = 'user_profiles:' . $userId;

        /*
        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return json_decode($cached, true);
            }
        }
*/
        $profileIds = $this->userAccessMapper->getProfileIdsForUser($userId);
/*
        if ($this->cache !== null) {
            $this->cache->set($cacheKey, json_encode($profileIds), self::CACHE_TTL);
        }
*/
        return $profileIds;
    }

    /**
     * Get the access level (read/write) a user has for a specific profile.
     *
     * @return string|null 'read', 'write', or null if no access
     */
    public function getUserAccessLevel(string $userId, int $accessProfileId): ?string {
        return $this->userAccessMapper->getAccessLevel($userId, $accessProfileId);
    }

    /**
     * Invalidate the cached access profiles for a user.
     * Call this when their access assignments change.
     */
    public function invalidateUserCache(string $userId): void {
        if ($this->cache !== null) {
            $this->cache->remove('user_profiles:' . $userId);
        }
    }

    // ---------------------------------------------------------------
    // Organisation access (UI helper — not related to virtual FS paths)
    // ---------------------------------------------------------------

    /**
     * Get distinct organisation names visible to a set of access profiles.
     *
     * @param int[] $accessProfileIds
     * @return string[]
     */
    public function getAccessibleOrganisations(array $accessProfileIds): array {
        if (empty($accessProfileIds)) {
            return [];
        }
        return $this->accessProfileMapper->getDistinctOrganisations($accessProfileIds);
    }

    // ---------------------------------------------------------------
    // Case-level permission checks
    // ---------------------------------------------------------------

    /**
     * Check if a user can read a specific case.
     *
     * Access is granted when any of the following is true:
     *   - The user is the responsible user for the case
     *   - The user has an active direct grant in opencase_case_users
     *   - The user holds the case's access profile via opencase_user_access
     */
    public function userHasReadAccessToCase(string $userId, int $caseId): bool {
        $case = $this->caseMapper->findById($caseId);
        if ($case === null) {
            return false;
        }

        // Responsible user always has access
        if ($case->getResponsibleUserId() === $userId) {
            return true;
        }

        // Active direct grant
        $grant = $this->caseUserMapper->findByCaseAndUser($caseId, $userId);
        if ($grant !== null && !$grant->isExpired()) {
            return true;
        }

        // Profile-based
        return $this->getUserAccessLevel($userId, $case->getAccessProfileId()) !== null;
    }

    /**
     * Check if a user can write to a specific case.
     *
     * Write access is granted when any of the following is true:
     *   - The user is the responsible user for the case
     *   - The user has an active direct grant with can_write = true
     *   - The user holds the case's access profile with access_level = 'write'
     */
    public function userHasWriteAccessToCase(string $userId, int $caseId): bool {
        $case = $this->caseMapper->findById($caseId);
        if ($case === null) {
            return false;
        }

        // Responsible user always has write access
        if ($case->getResponsibleUserId() === $userId) {
            return true;
        }

        // Active direct grant with write permission
        $grant = $this->caseUserMapper->findByCaseAndUser($caseId, $userId);
        if ($grant !== null && !$grant->isExpired() && $grant->getCanWrite()) {
            return true;
        }

        // Profile-based write
        return $this->getUserAccessLevel($userId, $case->getAccessProfileId()) === 'write';
    }

    // ---------------------------------------------------------------
    // Responsible user management
    // ---------------------------------------------------------------

    /**
     * Assign a new responsible user to a case.
     *
     * The responsible user always has read+write access regardless of
     * the case's access profile. There is exactly one responsible user
     * per case at any time.
     */
    public function setResponsibleUser(int $caseId, string $userId): void {
        $case = $this->caseMapper->findById($caseId);
        if ($case === null) {
            return;
        }
        $case->setResponsibleUserId($userId);
        $this->caseMapper->update($case);
    }

    // ---------------------------------------------------------------
    // Direct grant management
    // ---------------------------------------------------------------

    /**
     * Grant a user direct access to a case.
     *
     * If a grant already exists for this user it is updated in-place.
     *
     * @param \DateTime|null $expiresAt  null = permanent grant
     */
    public function grantUserAccess(
        int $caseId,
        string $userId,
        bool $canWrite,
        string $grantedBy,
        ?\DateTime $expiresAt = null,
    ): CaseUserEntity {
        return $this->caseUserMapper->upsertGrant($caseId, $userId, $canWrite, $grantedBy, $expiresAt);
    }

    public function findGrant(int $caseId, string $userId): ?CaseUserEntity {
        return $this->caseUserMapper->findByCaseAndUser($caseId, $userId);
    }

    /**
     * Revoke a user's direct access grant.
     * No-op if no grant exists. Does not affect responsible_user_id.
     */
    public function revokeUserAccess(int $caseId, string $userId): void {
        $this->caseUserMapper->revokeGrant($caseId, $userId);
    }

    /**
     * Return all active direct grants for a case (excludes expired).
     *
     * @return CaseUserEntity[]
     */
    public function getActiveCaseGrants(int $caseId): array {
        return $this->caseUserMapper->findActiveByCaseId($caseId);
    }

    /**
     * Return all direct grants for a case including expired ones.
     * Useful for audit views.
     *
     * @return CaseUserEntity[]
     */
    public function getAllCaseGrants(int $caseId): array {
        return $this->caseUserMapper->findByCaseId($caseId);
    }

    // ---------------------------------------------------------------
    // Document-level permission checks (with per-user overrides)
    // ---------------------------------------------------------------

    /**
     * Check if a user can read a specific document.
     *
     * Resolution order:
     *   1. Check for explicit per-user document override (grant or deny)
     *   2. Fall back to case-level access
     */
    public function userHasReadAccessToDocument(string $userId, int $documentId): bool {
        // Check per-user document override first
        $override = $this->getDocumentOverride($userId, $documentId);
        if ($override !== null) {
            return $override !== 'deny';
        }

        // Fall back to case-level access
        $caseId = $this->getCaseIdForDocument($documentId);
        if ($caseId === null) {
            return false;
        }

        if ($this->userHasReadAccessToCase($userId, $caseId)) {
            return true;
        }

        // Document-level file share recipients
        return $this->userHasFileShareForDocument($userId, $documentId);
    }

    /**
     * Return true if the user has at least one active file share for any file
     * belonging to this document. Works for both document-level shares
     * (document_id set on the share row) and individual file-level shares
     * (document_id NULL, but the file itself belongs to the document).
     */
    private function userHasFileShareForDocument(string $userId, int $documentId): bool {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $qb  = $this->db->getQueryBuilder();
        $qb->select($qb->expr()->literal(1))
            ->from('opencase_file_shares', 'fs')
            ->innerJoin('fs', 'opencase_files', 'f', $qb->expr()->eq('fs.file_id', 'f.id'))
            ->where($qb->expr()->eq('f.document_id', $qb->createNamedParameter($documentId, $qb::PARAM_INT)))
            ->andWhere($qb->expr()->eq('fs.shared_with', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('fs.expires_at'),
                $qb->expr()->gt('fs.expires_at', $qb->createNamedParameter($now)),
            ))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row    = $result->fetchOne();
        $result->closeCursor();
        return $row !== false;
    }

    /**
     * Check if a user can write to a specific document.
     */
    public function userHasWriteAccessToDocument(string $userId, int $documentId): bool {
        $override = $this->getDocumentOverride($userId, $documentId);
        if ($override !== null) {
            return $override === 'write';
        }

        $caseId = $this->getCaseIdForDocument($documentId);
        if ($caseId === null) {
            return false;
        }

        return $this->userHasWriteAccessToCase($userId, $caseId);
    }

    private function getDocumentOverride(string $userId, int $documentId): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('access_level')
            ->from('opencase_doc_user_access')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('document_id', $qb->createNamedParameter($documentId)));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return $row ? $row['access_level'] : null;
    }

    private function getCaseIdForDocument(int $documentId): ?int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('case_id')
            ->from('opencase_documents')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($documentId)));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return $row ? (int)$row['case_id'] : null;
    }

    // ---------------------------------------------------------------
    // Shared-storage helpers (used by OpenCasePermissionWrapper and
    // OpenCaseCacheWrapper)
    //
    // Virtual path: {year}/{month}/{day}/{case_number}/{filename}
    // All date values come from cases.created_at (immutable).
    // ---------------------------------------------------------------

    /**
     * Check if a user has access to any case created in the given year.
     * Used to decide whether to show a year directory.
     */
    public function userHasYearAccess(string $userId, string $year): bool {
        return $this->caseMapper->userHasYearAccess($userId, $year);
    }

    /**
     * Check if a user has access to any case created in the given year+month.
     * Used to decide whether to show a month directory.
     */
    public function userHasMonthAccess(string $userId, string $year, string $month): bool {
        return $this->caseMapper->userHasMonthAccess($userId, $year, $month);
    }

    /**
     * Check if a user has access to any case created on the given date.
     * Used to decide whether to show a day directory.
     */
    public function userHasDayAccess(string $userId, string $year, string $month, string $day): bool {
        return $this->caseMapper->userHasDayAccess($userId, $year, $month, $day);
    }

    /**
     * Resolve a case number to its internal case ID.
     *
     * Returns null if the case does not exist (regardless of access).
     * The caller must separately verify user access.
     */
    public function getCaseIdByCaseNumber(string $caseNumber): ?int {
        $case = $this->caseMapper->findByCaseNumber($caseNumber);
        return $case?->getId();
    }

    /**
     * Batch-filter a list of case numbers to those the user can access on a given date.
     *
     * Returns a map of case_number → access_level ('read'|'write') for each
     * accessible case. Numbers absent from the map are not accessible.
     *
     * @param string[] $caseNumbers  Virtual dir names == case numbers, e.g. ["2026-001", ...]
     * @return array<string, string>  case_number → 'read'|'write'
     */
    public function filterAccessibleCaseDirsOnDate(
        string $userId,
        string $year,
        string $month,
        string $day,
        array $caseNumbers,
    ): array {
        if (empty($caseNumbers)) {
            return [];
        }

        return $this->caseMapper->getAccessibleCaseNumbersOnDate(
            $year, $month, $day, $caseNumbers, $userId
        );
    }

    /**
     * Get virtual filenames that are explicitly denied for a user in a case.
     *
     * A file is denied when its parent document has a per-user 'deny' override
     * in opencase_doc_user_access. These files are hidden from the user even
     * though the user has general case access.
     *
     * @return string[]
     */
    public function getDeniedFilenamesForUser(string $userId, int $caseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('f.virtual_filename')
            ->from('opencase_files', 'f')
            ->innerJoin('f', 'opencase_doc_user_access', 'dua',
                $qb->expr()->eq('dua.document_id', 'f.document_id'))
            ->where($qb->expr()->eq('f.case_id', $qb->createNamedParameter($caseId, $qb::PARAM_INT)))
            ->andWhere($qb->expr()->eq('dua.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('dua.access_level', $qb->createNamedParameter('deny')));

        $result = $qb->executeQuery();
        $denied = [];
        while ($row = $result->fetch()) {
            $denied[] = $row['virtual_filename'];
        }
        $result->closeCursor();

        return $denied;
    }

    /**
     * Get all virtual filenames in a case that belong to Final (is_final=1) documents.
     *
     * Used by OpenCaseCacheWrapper for batch directory listings.
     *
     * @return string[]
     */
    public function getFinalDocumentFilenamesForCase(int $caseId): array {
        $qb     = $this->db->getQueryBuilder();
        $result = $qb->select('f.virtual_filename')
            ->from('opencase_files', 'f')
            ->innerJoin('f', 'opencase_documents', 'd',
                $qb->expr()->eq('f.document_id', 'd.id'))
            ->innerJoin('d', 'opencase_documentstatus', 'ds',
                $qb->expr()->andX(
                    $qb->expr()->eq('ds.id', 'd.status'),
                    $qb->expr()->eq('ds.language', $qb->createNamedParameter('en'))
                ))
            ->where($qb->expr()->eq('f.case_id', $qb->createNamedParameter($caseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('ds.is_final', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->executeQuery();
        $filenames = [];
        while ($row = $result->fetch()) {
            $filenames[] = $row['virtual_filename'];
        }
        $result->closeCursor();
        return $filenames;
    }

    /**
     * Return true if the file (identified by case number + virtual filename) belongs
     * to a document whose status has is_final=1.
     *
     * Used by OpenCasePermissionWrapper::getPermissions() to prevent the NC scanner
     * from resetting filecache permissions to READ+UPDATE for Final-document files.
     */
    public function isFileInFinalDocument(string $caseNumber, string $virtualFilename): bool {
        $qb     = $this->db->getQueryBuilder();
        $result = $qb->select('ds.is_final')
            ->from('opencase_files', 'f')
            ->innerJoin('f', 'opencase_cases', 'c',
                $qb->expr()->eq('f.case_id', 'c.id'))
            ->innerJoin('f', 'opencase_documents', 'd',
                $qb->expr()->eq('f.document_id', 'd.id'))
            ->innerJoin('d', 'opencase_documentstatus', 'ds',
                $qb->expr()->andX(
                    $qb->expr()->eq('ds.id', 'd.status'),
                    $qb->expr()->eq('ds.language', $qb->createNamedParameter('en'))
                ))
            ->where($qb->expr()->eq('c.case_number', $qb->createNamedParameter($caseNumber)))
            ->andWhere($qb->expr()->eq('f.virtual_filename', $qb->createNamedParameter($virtualFilename)))
            ->executeQuery();
        $row = $result->fetchOne();
        $result->closeCursor();
        return $row !== false && (bool)$row;
    }

    // ---------------------------------------------------------------
    // Batch permission checks (for directory listings)
    // ---------------------------------------------------------------

    /**
     * Filter a list of case IDs to only those the user can read.
     * Optimised for bulk operations like directory listings.
     *
     * Checks all three access paths: responsible user, direct grants, profile-based.
     *
     * @param int[] $caseIds
     * @return int[]
     */
    public function filterReadableCases(string $userId, array $caseIds): array {
        if (empty($caseIds)) {
            return [];
        }

        $readable = [];

        // --- Path 1: responsible user ---
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('opencase_cases')
            ->where($qb->expr()->in('id', $qb->createNamedParameter($caseIds, $qb::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('responsible_user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $readable[(int)$row['id']] = true;
        }
        $result->closeCursor();

        // --- Path 2: active direct grants ---
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $qb  = $this->db->getQueryBuilder();
        $qb->select('case_id')
            ->from('opencase_case_users')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->in('case_id', $qb->createNamedParameter($caseIds, $qb::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('expires_at'),
                $qb->expr()->gt('expires_at', $qb->createNamedParameter($now)),
            ));

        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $readable[(int)$row['case_id']] = true;
        }
        $result->closeCursor();

        // --- Path 3: profile-based ---
        $profileIds = $this->getAccessProfileIdsForUser($userId);
        if (!empty($profileIds)) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')
                ->from('opencase_cases')
                ->where($qb->expr()->in('id', $qb->createNamedParameter($caseIds, $qb::PARAM_INT_ARRAY)))
                ->andWhere($qb->expr()->in('access_profile_id', $qb->createNamedParameter($profileIds, $qb::PARAM_INT_ARRAY)));

            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $readable[(int)$row['id']] = true;
            }
            $result->closeCursor();
        }

        return array_keys($readable);
    }

    /**
     * Return case IDs the user can access via responsible-user or direct-grant paths.
     * Used to supplement the ES access_profile_id filter with paths 1 and 2.
     *
     * @return int[]
     */
    public function getDirectAccessCaseIds(string $userId): array {
        $caseIds = [];

        // Path 1: responsible user
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('opencase_cases')
            ->where($qb->expr()->eq('responsible_user_id', $qb->createNamedParameter($userId)));
        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $caseIds[(int)$row['id']] = true;
        }
        $result->closeCursor();

        // Path 2: active direct grants
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $qb  = $this->db->getQueryBuilder();
        $qb->select('case_id')
            ->from('opencase_case_users')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('expires_at'),
                $qb->expr()->gt('expires_at', $qb->createNamedParameter($now)),
            ));
        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $caseIds[(int)$row['case_id']] = true;
        }
        $result->closeCursor();

        return array_keys($caseIds);
    }

    // ---------------------------------------------------------------
    // File-share permission checks
    // Used by OpenCaseMountProvider, OpenCasePermissionWrapper,
    // and OpenCaseCacheWrapper to allow share-recipient access.
    // ---------------------------------------------------------------

    /**
     * Check if a user has at least one active file share.
     * Used by MountProvider to decide whether to create a mount for this user.
     */
    public function userHasAnyFileShare(string $userId): bool {
        return $this->fileShareMapper->userHasAnyActiveShare($userId);
    }

    /**
     * Return true if the user is a responsible user or has an active direct grant
     * for at least one case. Used as a gate-check for users who have case access
     * without an explicit access profile (e.g. responsible users).
     */
    public function userHasAnyCaseAccess(string $userId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->expr()->literal(1))
            ->from('opencase_cases')
            ->where($qb->expr()->eq('responsible_user_id', $qb->createNamedParameter($userId)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row    = $result->fetchOne();
        $result->closeCursor();
        if ($row !== false) {
            return true;
        }

        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $qb  = $this->db->getQueryBuilder();
        $qb->select($qb->expr()->literal(1))
            ->from('opencase_case_users')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('expires_at'),
                $qb->expr()->gt('expires_at', $qb->createNamedParameter($now)),
            ))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row    = $result->fetchOne();
        $result->closeCursor();
        return $row !== false;
    }

    /**
     * Return the NC permissions integer for a specific file share.
     * Returns 0 if the user has no active share for this file.
     */
    public function getFileSharePermissionsForRecipient(string $userId, int $fileId): int {
        $share = $this->fileShareMapper->findActiveByFileAndUser($fileId, $userId);
        return $share?->getPermissions() ?? 0;
    }

    /**
     * Check if a user has an active file share for any file in the given case.
     * Used by PermissionWrapper to decide whether a case dir is traversable.
     */
    public function userHasFileShareInCase(string $userId, int $caseId): bool {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $qb  = $this->db->getQueryBuilder();
        $qb->select('fs.id')
            ->from('opencase_file_shares', 'fs')
            ->innerJoin('fs', 'opencase_files', 'f', $qb->expr()->eq('fs.file_id', 'f.id'))
            ->where($qb->expr()->eq('fs.shared_with', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('f.case_id', $qb->createNamedParameter($caseId, $qb::PARAM_INT)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('fs.expires_at'),
                $qb->expr()->gt('fs.expires_at', $qb->createNamedParameter($now)),
            ))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return $row !== false;
    }

    /**
     * Return the NC permissions for a file share identified by case + virtual filename.
     * Convenience wrapper used by the cache wrapper's single-entry path.
     */
    public function getFileSharePermissionsByFilename(string $userId, int $caseId, string $virtualFilename): int {
        $fileId = $this->getFileIdByCaseAndFilename($caseId, $virtualFilename);
        if ($fileId === null) {
            return 0;
        }
        return $this->getFileSharePermissionsForRecipient($userId, $fileId);
    }

    /**
     * Resolve an OpenCase file ID from a case ID + virtual filename.
     */
    public function getFileIdByCaseAndFilename(int $caseId, string $virtualFilename): ?int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('opencase_files')
            ->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, $qb::PARAM_INT)))
            ->andWhere($qb->expr()->eq('virtual_filename', $qb->createNamedParameter($virtualFilename)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return $row ? (int)$row['id'] : null;
    }

    // ---------------------------------------------------------------
    // Path-navigation helpers for share recipients
    //
    // These power the filterEntries() / getFolderContentsById() filtering
    // for users who only have file-level shares (no case/profile access).
    // All date extraction uses DATE_FORMAT via createFunction() because
    // NC's IFunctionBuilder has no date_format() method.
    // ---------------------------------------------------------------

    /**
     * Return distinct years that have files shared with this user.
     *
     * @return string[]  e.g. ['2026', '2025']
     */
    public function getShareRecipientVisibleYears(string $userId): array {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $qb  = $this->db->getQueryBuilder();
        $fn  = $qb->createFunction("DATE_FORMAT(c.created_at, '%Y')");

        $qb->selectAlias($fn, 'yr')
            ->from('opencase_file_shares', 'fs')
            ->innerJoin('fs', 'opencase_files', 'f', $qb->expr()->eq('fs.file_id', 'f.id'))
            ->innerJoin('f', 'opencase_cases', 'c', $qb->expr()->eq('f.case_id', 'c.id'))
            ->where($qb->expr()->eq('fs.shared_with', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('fs.expires_at'),
                $qb->expr()->gt('fs.expires_at', $qb->createNamedParameter($now)),
            ))
            ->groupBy($fn);

        $result = $qb->executeQuery();
        $years  = [];
        while ($row = $result->fetch()) {
            $years[] = (string)$row['yr'];
        }
        $result->closeCursor();

        return $years;
    }

    /**
     * Return distinct months (as 'MM') in a given year that have shared files.
     *
     * @return string[]  e.g. ['03', '07']
     */
    public function getShareRecipientVisibleMonths(string $userId, string $year): array {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $qb  = $this->db->getQueryBuilder();
        $fn  = $qb->createFunction("DATE_FORMAT(c.created_at, '%m')");

        $qb->selectAlias($fn, 'mo')
            ->from('opencase_file_shares', 'fs')
            ->innerJoin('fs', 'opencase_files', 'f', $qb->expr()->eq('fs.file_id', 'f.id'))
            ->innerJoin('f', 'opencase_cases', 'c', $qb->expr()->eq('f.case_id', 'c.id'))
            ->where($qb->expr()->eq('fs.shared_with', $qb->createNamedParameter($userId)))
            ->andWhere("DATE_FORMAT(c.created_at, '%Y') = " . $qb->createNamedParameter($year))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('fs.expires_at'),
                $qb->expr()->gt('fs.expires_at', $qb->createNamedParameter($now)),
            ))
            ->groupBy($fn);

        $result  = $qb->executeQuery();
        $months  = [];
        while ($row = $result->fetch()) {
            $months[] = (string)$row['mo'];
        }
        $result->closeCursor();

        return $months;
    }

    /**
     * Return distinct days (as 'DD') in a given year+month that have shared files.
     *
     * @return string[]  e.g. ['01', '15']
     */
    public function getShareRecipientVisibleDays(string $userId, string $year, string $month): array {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $qb  = $this->db->getQueryBuilder();
        $fn  = $qb->createFunction("DATE_FORMAT(c.created_at, '%d')");

        $qb->selectAlias($fn, 'dy')
            ->from('opencase_file_shares', 'fs')
            ->innerJoin('fs', 'opencase_files', 'f', $qb->expr()->eq('fs.file_id', 'f.id'))
            ->innerJoin('f', 'opencase_cases', 'c', $qb->expr()->eq('f.case_id', 'c.id'))
            ->where($qb->expr()->eq('fs.shared_with', $qb->createNamedParameter($userId)))
            ->andWhere("DATE_FORMAT(c.created_at, '%Y') = " . $qb->createNamedParameter($year))
            ->andWhere("DATE_FORMAT(c.created_at, '%m') = " . $qb->createNamedParameter($month))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('fs.expires_at'),
                $qb->expr()->gt('fs.expires_at', $qb->createNamedParameter($now)),
            ))
            ->groupBy($fn);

        $result = $qb->executeQuery();
        $days   = [];
        while ($row = $result->fetch()) {
            $days[] = (string)$row['dy'];
        }
        $result->closeCursor();

        return $days;
    }

    /**
     * Return distinct case numbers on a given date that contain shared files.
     *
     * @return string[]  e.g. ['2026-001', '2026-007']
     */
    public function getShareRecipientVisibleCases(string $userId, string $year, string $month, string $day): array {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $qb  = $this->db->getQueryBuilder();

        $qb->selectDistinct('c.case_number')
            ->from('opencase_file_shares', 'fs')
            ->innerJoin('fs', 'opencase_files', 'f', $qb->expr()->eq('fs.file_id', 'f.id'))
            ->innerJoin('f', 'opencase_cases', 'c', $qb->expr()->eq('f.case_id', 'c.id'))
            ->where($qb->expr()->eq('fs.shared_with', $qb->createNamedParameter($userId)))
            ->andWhere("DATE_FORMAT(c.created_at, '%Y') = " . $qb->createNamedParameter($year))
            ->andWhere("DATE_FORMAT(c.created_at, '%m') = " . $qb->createNamedParameter($month))
            ->andWhere("DATE_FORMAT(c.created_at, '%d') = " . $qb->createNamedParameter($day))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('fs.expires_at'),
                $qb->expr()->gt('fs.expires_at', $qb->createNamedParameter($now)),
            ));

        $result      = $qb->executeQuery();
        $caseNumbers = [];
        while ($row = $result->fetch()) {
            $caseNumbers[] = (string)$row['case_number'];
        }
        $result->closeCursor();

        return $caseNumbers;
    }

    /**
     * Return the virtual filenames (and their permissions) shared with a user in a case.
     *
     * @return array<string, int>  virtual_filename → permissions
     */
    public function getShareRecipientVisibleFilenames(string $userId, int $caseId): array {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $qb  = $this->db->getQueryBuilder();

        $qb->select('f.virtual_filename', 'fs.permissions')
            ->from('opencase_file_shares', 'fs')
            ->innerJoin('fs', 'opencase_files', 'f', $qb->expr()->eq('fs.file_id', 'f.id'))
            ->where($qb->expr()->eq('fs.shared_with', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('f.case_id', $qb->createNamedParameter($caseId, $qb::PARAM_INT)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('fs.expires_at'),
                $qb->expr()->gt('fs.expires_at', $qb->createNamedParameter($now)),
            ));

        $result = $qb->executeQuery();
        $files  = [];
        while ($row = $result->fetch()) {
            $files[(string)$row['virtual_filename']] = (int)$row['permissions'];
        }
        $result->closeCursor();

        return $files;
    }
}
