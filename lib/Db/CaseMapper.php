<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for the opencase_cases table.
 *
 * Virtual filesystem resolution methods are date-based: the virtual path
 * for a case is {year}/{month}/{day}/{case_number}, derived from created_at.
 * This makes paths immutable regardless of changes to title or organisation.
 *
 * Expected scale: ~2 million cases.
 *
 * @extends QBMapper<CaseEntity>
 */
class CaseMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_cases', CaseEntity::class);
    }

    // ---------------------------------------------------------------
    // Single-entity lookups
    // ---------------------------------------------------------------

    /**
     * Find a case by its primary key.
     */
    public function findById(int $id): ?CaseEntity {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Find a case by its human-readable case number.
     * Case numbers are globally unique within the installation.
     */
    public function findByCaseNumber(string $caseNumber): ?CaseEntity {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('case_number', $qb->createNamedParameter($caseNumber)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Find a case by its UUID.
     */
    public function findByUuid(string $uuid): ?CaseEntity {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Find all direct children of a case (cases whose parent_case_id equals $parentId).
     *
     * @return CaseEntity[]
     */
    public function findByParentId(int $parentId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('parent_case_id', $qb->createNamedParameter($parentId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find the inbox case for an organisation, or null if none exists yet.
     */
    public function findInboxByOrgUuid(string $orgUuid): ?CaseEntity {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('org_uuid', $qb->createNamedParameter($orgUuid)))
            ->andWhere($qb->expr()->eq('is_inbox', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    // ---------------------------------------------------------------
    // Virtual filesystem path resolution — date-based
    //
    // The virtual tree is: {year}/{month}/{day}/{case_number}
    // where year/month/day come from created_at (immutable).
    // These methods are called by OpenCaseStorage to resolve the tree.
    //
    // All date filtering uses half-open [start, end) ranges on created_at
    // rather than DATE_FORMAT(created_at, …) = ?.  MariaDB cannot use an
    // index for a function of a column, so the DATE_FORMAT form scanned the
    // whole table (millions of rows) on every directory listing; the range
    // form is served by the oc_case_inbox_created (is_inbox, created_at)
    // index.  The yearRange()/monthRange()/dayRange() helpers below build
    // the bounds.
    //
    // The three "which years/months/days exist" queries were GROUP BYs over
    // the same full scan.  They are now probe loops: at most 12 months or 31
    // days, each a single index dive that stops at the first matching row.
    // ---------------------------------------------------------------

    /**
     * All distinct creation years in the system (no user filter).
     *
     * @return string[] e.g. ['2026', '2025']
     */
    public function getAllCreatedYears(): array {
        $bounds = $this->createdAtYearBounds();
        if ($bounds === null) {
            return [];
        }
        [$firstYear, $lastYear] = $bounds;

        $years = [];
        for ($year = $lastYear; $year >= $firstYear; $year--) {
            [$start, $end] = $this->yearRange((string)$year);
            if ($this->anyCaseInRange($start, $end)) {
                $years[] = (string)$year;
            }
        }

        return $years;
    }

    /**
     * All distinct creation months (zero-padded) for cases in the given year.
     *
     * @return string[] e.g. ['01', '03', '12']
     */
    public function getAllCreatedMonthsForYear(string $year): array {
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $month = str_pad((string)$m, 2, '0', STR_PAD_LEFT);
            [$start, $end] = $this->monthRange($year, $month);
            if ($this->anyCaseInRange($start, $end)) {
                $months[] = $month;
            }
        }

        return $months;
    }

    /**
     * All distinct creation days (zero-padded) for cases in the given year+month.
     *
     * @return string[] e.g. ['01', '05', '17']
     */
    public function getAllCreatedDaysForYearMonth(string $year, string $month): array {
        $daysInMonth = (int)(new \DateTime("$year-$month-01"))->format('t');

        $days = [];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $day = str_pad((string)$d, 2, '0', STR_PAD_LEFT);
            [$start, $end] = $this->dayRange($year, $month, $day);
            if ($this->anyCaseInRange($start, $end)) {
                $days[] = $day;
            }
        }

        return $days;
    }

    /**
     * Find all cases created on a specific date (no user filter).
     *
     * @return CaseEntity[]
     */
    public function findAllByCreatedDate(
        string $year,
        string $month,
        string $day,
        ?int $limit = 500,
    ): array {
        [$start, $end] = $this->dayRange($year, $month, $day);

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('is_inbox', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($start)))
            ->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($end)))
            ->orderBy('case_number', 'ASC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $this->findEntities($qb);
    }

    /**
     * Earliest and latest creation year of any non-inbox case.
     *
     * Both bounds are single index dives on (is_inbox, created_at) — the
     * query stops at the first row in either direction.
     *
     * @return array{int, int}|null  [first year, last year], or null when empty
     */
    private function createdAtYearBounds(): ?array {
        $first = $this->boundaryYear('ASC');
        if ($first === null) {
            return null;
        }
        $last = $this->boundaryYear('DESC');

        return [$first, $last ?? $first];
    }

    /** @param 'ASC'|'DESC' $direction */
    private function boundaryYear(string $direction): ?int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('created_at')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('is_inbox', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', $direction)
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $value  = $result->fetchOne();
        $result->closeCursor();

        if ($value === false || $value === null) {
            return null;
        }

        return (int)(new \DateTime((string)$value))->format('Y');
    }

    /**
     * Whether any non-inbox case was created within [start, end).
     */
    private function anyCaseInRange(string $start, string $end): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->expr()->literal(1))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('is_inbox', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($start)))
            ->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($end)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $found  = $result->fetchOne();
        $result->closeCursor();

        return $found !== false;
    }

    /**
     * Batch-filter case numbers to those accessible by a specific user on a given date.
     *
     * Returns case_number → access_level ('read'|'write') for each accessible case.
     * Used by the cache wrapper for efficient batch directory listing at level 4.
     *
     * Three access paths are merged (write beats read):
     *   1. Profile-based access via opencase_user_access
     *   2. Responsible user (responsible_user_id = :userId → always write)
     *   3. Direct grant in opencase_case_users (can_write determines level)
     *
     * @param string[] $caseNumbers
     * @return array<string, string>  case_number → 'read'|'write'
     */
    public function getAccessibleCaseNumbersOnDate(
        string $year,
        string $month,
        string $day,
        array $caseNumbers,
        string $userId,
    ): array {
        if (empty($caseNumbers)) {
            return [];
        }

        [$start, $end] = $this->dayRange($year, $month, $day);
        $map = [];

        // --- Path 1: profile-based ---
        $qb = $this->db->getQueryBuilder();
        $qb->select('c.case_number', 'ua.access_level')
            ->from($this->getTableName(), 'c')
            ->innerJoin('c', 'opencase_user_access', 'ua',
                $qb->expr()->eq('ua.access_profile_id', 'c.access_profile_id'))
            ->where($qb->expr()->eq('c.is_inbox', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gte('c.created_at', $qb->createNamedParameter($start)))
            ->andWhere($qb->expr()->lt('c.created_at', $qb->createNamedParameter($end)))
            ->andWhere($qb->expr()->in(
                'c.case_number',
                $qb->createNamedParameter($caseNumbers, IQueryBuilder::PARAM_STR_ARRAY)
            ))
            ->andWhere($qb->expr()->eq('ua.user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            if (($map[$row['case_number']] ?? null) !== 'write') {
                $map[$row['case_number']] = $row['access_level'];
            }
        }
        $result->closeCursor();

        // --- Path 2: responsible user (range condition → uses composite index) ---
        $qb = $this->db->getQueryBuilder();
        $qb->select('case_number')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('responsible_user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('is_inbox', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($start)))
            ->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($end)))
            ->andWhere($qb->expr()->in(
                'case_number',
                $qb->createNamedParameter($caseNumbers, IQueryBuilder::PARAM_STR_ARRAY)
            ));

        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $map[$row['case_number']] = 'write'; // responsible user always gets write
        }
        $result->closeCursor();

        // --- Path 3: direct grants ---
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $qb  = $this->db->getQueryBuilder();
        $qb->select('c.case_number', 'cu.can_write')
            ->from($this->getTableName(), 'c')
            ->innerJoin('c', 'opencase_case_users', 'cu',
                $qb->expr()->eq('cu.case_id', 'c.id'))
            ->where($qb->expr()->eq('cu.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('c.is_inbox', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gte('c.created_at', $qb->createNamedParameter($start)))
            ->andWhere($qb->expr()->lt('c.created_at', $qb->createNamedParameter($end)))
            ->andWhere($qb->expr()->in(
                'c.case_number',
                $qb->createNamedParameter($caseNumbers, IQueryBuilder::PARAM_STR_ARRAY)
            ))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('cu.expires_at'),
                $qb->expr()->gt('cu.expires_at', $qb->createNamedParameter($now)),
            ));

        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $level = $row['can_write'] ? 'write' : 'read';
            if (($map[$row['case_number']] ?? null) !== 'write') {
                $map[$row['case_number']] = $level;
            }
        }
        $result->closeCursor();

        return $map;
    }

    // ---------------------------------------------------------------
    // Date-level access checks (used by PermissionService)
    //
    // Each check tests three independent access paths in order:
    //   1. Responsible user   — cases.responsible_user_id = :userId
    //                           Uses composite index (responsible_user_id, created_at).
    //   2. Direct grant       — opencase_case_users.user_id = :userId (non-expired)
    //   3. Profile-based      — opencase_user_access JOIN (existing logic)
    //
    // All three paths use date-range conditions (>= / <) so MariaDB can use
    // an index range scan on created_at.
    // ---------------------------------------------------------------

    /**
     * Check if a user has any accessible case created in the given year.
     */
    public function userHasYearAccess(string $userId, string $year): bool {
        [$start, $end] = $this->yearRange($year);
        return $this->userHasAccessInRange($userId, $start, $end);
    }

    /**
     * Check if a user has any accessible case created in the given year+month.
     */
    public function userHasMonthAccess(string $userId, string $year, string $month): bool {
        [$start, $end] = $this->monthRange($year, $month);
        return $this->userHasAccessInRange($userId, $start, $end);
    }

    /**
     * Check if a user has any accessible case created on the given date.
     */
    public function userHasDayAccess(string $userId, string $year, string $month, string $day): bool {
        [$start, $end] = $this->dayRange($year, $month, $day);
        return $this->userHasAccessInRange($userId, $start, $end);
    }

    /**
     * Shared implementation for the three date-level access checks.
     *
     * @param string $start  Inclusive lower bound (Y-m-d H:i:s)
     * @param string $end    Exclusive upper bound (Y-m-d H:i:s)
     */
    private function userHasAccessInRange(
        string $userId,
        string $start,
        string $end,
    ): bool {
        // --- Path 1: responsible user (composite index hit) ---
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->expr()->literal(1))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('responsible_user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('is_inbox', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($start)))
            ->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($end)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $found  = $result->fetchOne();
        $result->closeCursor();
        if ($found !== false) {
            return true;
        }

        // --- Path 2: direct grant (indexed on user_id, joined to date range) ---
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $qb  = $this->db->getQueryBuilder();
        $qb->select($qb->expr()->literal(1))
            ->from('opencase_case_users', 'cu')
            ->innerJoin('cu', $this->getTableName(), 'c',
                $qb->expr()->eq('cu.case_id', 'c.id'))
            ->where($qb->expr()->eq('cu.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('c.is_inbox', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gte('c.created_at', $qb->createNamedParameter($start)))
            ->andWhere($qb->expr()->lt('c.created_at', $qb->createNamedParameter($end)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('cu.expires_at'),
                $qb->expr()->gt('cu.expires_at', $qb->createNamedParameter($now)),
            ))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $found  = $result->fetchOne();
        $result->closeCursor();
        if ($found !== false) {
            return true;
        }

        // --- Path 3: profile-based ---
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->expr()->literal(1))
            ->from($this->getTableName(), 'c')
            ->innerJoin('c', 'opencase_user_access', 'ua',
                $qb->expr()->eq('ua.access_profile_id', 'c.access_profile_id'))
            ->where($qb->expr()->eq('c.is_inbox', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gte('c.created_at', $qb->createNamedParameter($start)))
            ->andWhere($qb->expr()->lt('c.created_at', $qb->createNamedParameter($end)))
            ->andWhere($qb->expr()->eq('ua.user_id', $qb->createNamedParameter($userId)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $found  = $result->fetchOne();
        $result->closeCursor();
        return $found !== false;
    }

    // ---------------------------------------------------------------
    // Date range helpers
    // ---------------------------------------------------------------

    /** @return array{string, string} [inclusive start, exclusive end] */
    private function yearRange(string $year): array {
        return [
            $year . '-01-01 00:00:00',
            ((int)$year + 1) . '-01-01 00:00:00',
        ];
    }

    /** @return array{string, string} */
    private function monthRange(string $year, string $month): array {
        $dt = new \DateTime("$year-$month-01");
        $dt->modify('+1 month');
        return [
            "$year-$month-01 00:00:00",
            $dt->format('Y-m-d') . ' 00:00:00',
        ];
    }

    /** @return array{string, string} */
    private function dayRange(string $year, string $month, string $day): array {
        $dt = new \DateTime("$year-$month-$day");
        $dt->modify('+1 day');
        return [
            "$year-$month-$day 00:00:00",
            $dt->format('Y-m-d') . ' 00:00:00',
        ];
    }

    // ---------------------------------------------------------------
    // Dashboard widget queries
    // ---------------------------------------------------------------

    /**
     * Find cases where the user is the responsible person and the status is not closed.
     * Used by the "Mine sager" dashboard widget and the /my-cases view.
     *
     * @return CaseEntity[]
     */
    public function findResponsibleNotClosed(
        string $userId,
        int $limit = 8,
        int $offset = 0,
    ): array {
        $openStatusIds = $this->getOpenStatusIds();
        if (empty($openStatusIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('responsible_user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->in('status_id', $qb->createNamedParameter($openStatusIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->orderBy('updated_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities($qb);
    }

    /**
     * Count cases where the user is the responsible person and the status is not closed.
     */
    public function countResponsibleNotClosed(string $userId): int {
        $openStatusIds = $this->getOpenStatusIds();
        if (empty($openStatusIds)) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->func()->count('id'), 'cnt')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('responsible_user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->in('status_id', $qb->createNamedParameter($openStatusIds, IQueryBuilder::PARAM_INT_ARRAY)));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Return the IDs of all non-closed case statuses.
     * Avoids JOIN duplicates from the multi-language status table.
     *
     * @return int[]
     */
    private function getOpenStatusIds(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('opencase_casestatus')
            ->where($qb->expr()->eq('is_closed', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->groupBy('id');

        $result = $qb->executeQuery();
        $ids    = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['id'];
        }
        $result->closeCursor();
        return $ids;
    }

    // ---------------------------------------------------------------
    // Export (ExportClosedCasesJob)
    // ---------------------------------------------------------------

    /**
     * Find cases whose status is closed and that have not been exported yet.
     * Used by ExportClosedCasesJob / CaseExportService.
     *
     * @return CaseEntity[]
     */
    public function findClosedUnexported(int $limit = 50): array {
        $closedStatusIds = $this->getClosedStatusIds();
        if (empty($closedStatusIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('status_id', $qb->createNamedParameter($closedStatusIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->isNull('exported_at'))
            ->orderBy('id', 'ASC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    /**
     * Return the IDs of all closed case statuses.
     * Avoids JOIN duplicates from the multi-language status table.
     *
     * @return int[]
     */
    private function getClosedStatusIds(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('opencase_casestatus')
            ->where($qb->expr()->eq('is_closed', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->groupBy('id');

        $result = $qb->executeQuery();
        $ids    = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['id'];
        }
        $result->closeCursor();
        return $ids;
    }

    // ---------------------------------------------------------------
    // Query helpers for other services (CaseService, SearchService, etc.)
    // These are not used by the virtual filesystem layer.
    // ---------------------------------------------------------------

    /**
     * Count accessible cases per organisation/year (for UI stats).
     * Joins opencase_org to return human-readable org names as keys.
     *
     * Scoped to the profile-based access path only, matching the previous
     * behaviour when this took a pre-resolved list of profile IDs.
     *
     * @return array<string, array<string, int>> e.g. ['Børn og Unge' => ['2024' => 312]]
     */
    public function countByOrgAndYear(string $userId): array {
        $qb     = $this->db->getQueryBuilder();
        $nameFn = $qb->createFunction('o.org_name');
        $yearFn = $qb->createFunction('c.year');
        $qb->select('o.org_name', 'c.year')
            ->selectAlias($qb->func()->count('*'), 'cnt')
            ->from($this->getTableName(), 'c')
            ->innerJoin('c', 'opencase_org', 'o', $qb->expr()->eq('c.org_uuid', 'o.org_uuid'))
            ->where($qb->expr()->eq('c.is_inbox', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere($this->profileAccessExists($qb, $userId))
            ->groupBy($nameFn, $yearFn)
            ->orderBy($nameFn)
            ->addOrderBy($yearFn, 'DESC');

        $result = $qb->executeQuery();
        $counts = [];
        while ($row = $result->fetch()) {
            $counts[$row['org_name']][$row['year']] = (int)$row['cnt'];
        }
        $result->closeCursor();

        return $counts;
    }

    /**
     * Find cases with optional filters, scoped to all access paths for the user.
     *
     * Access paths (OR):
     *   - cases.responsible_user_id = userId
     *   - active row in opencase_case_users for userId (via LEFT JOIN)
     *   - matching row in opencase_user_access for userId (profile-based)
     *
     * @param string|null $orgUuid  Filter by org UUID
     * @return CaseEntity[]
     */
    public function findWithFilters(
        string $userId,
        ?string $orgUuid = null,
        ?string $year = null,
        ?int $statusId = null,
        int $limit = 50,
        int $offset = 0,
        ?string $search = null,
        ?string $classificationFacetUuid = null,
        ?int $insightLevelId = null,
        ?string $responsibleUserId = null,
        ?string $classificationCode = null,
        ?string $sensitivityKey = null,
        ?int $casetypeId = null,
    ): array {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $qb  = $this->db->getQueryBuilder();
        $qb->select('c.*')
            ->from($this->getTableName(), 'c')
            ->leftJoin('c', 'opencase_case_users', 'cu', $qb->expr()->andX(
                $qb->expr()->eq('cu.case_id', 'c.id'),
                $qb->expr()->eq('cu.user_id', $qb->createNamedParameter($userId)),
                $qb->expr()->orX(
                    $qb->expr()->isNull('cu.expires_at'),
                    $qb->expr()->gt('cu.expires_at', $qb->createNamedParameter($now)),
                ),
            ))
            ->orderBy('c.updated_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        // where() must come before all andWhere() calls — calling where() after andWhere()
        // replaces the accumulated conditions in the Doctrine query builder.
        $qb->where($qb->expr()->orX(...$this->buildAccessOr($qb, $userId)));
        $qb->andWhere($qb->expr()->eq('c.is_inbox', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

        // Join-based filters: must be added after where() so andWhere() appends correctly.
        $needsProfileJoin = ($classificationCode !== null && $classificationCode !== '')
            || ($sensitivityKey !== null && $sensitivityKey !== '');
        if ($needsProfileJoin) {
            $qb->innerJoin('c', 'opencase_access_profiles', 'ap_f',
                $qb->expr()->eq('ap_f.id', 'c.access_profile_id'));
        }
        if ($classificationCode !== null && $classificationCode !== '') {
            $qb->innerJoin('ap_f', 'opencase_class_subject', 'cs_f',
                $qb->expr()->eq('ap_f.class_subject_uuid', 'cs_f.uuid'));
            $qb->andWhere($qb->expr()->eq('cs_f.code', $qb->createNamedParameter($classificationCode)));
        }
        if ($sensitivityKey !== null && $sensitivityKey !== '') {
            $qb->innerJoin('ap_f', 'opencase_sensitivity', 'sens_f',
                $qb->expr()->eq('ap_f.sensitivity_uuid', 'sens_f.uuid'));
            $qb->andWhere($qb->expr()->eq('sens_f.key', $qb->createNamedParameter($sensitivityKey)));
        }

        if ($orgUuid !== null) {
            $qb->andWhere($qb->expr()->eq('c.org_uuid', $qb->createNamedParameter($orgUuid)));
        }
        if ($year !== null) {
            $qb->andWhere($qb->expr()->eq('c.year', $qb->createNamedParameter($year)));
        }
        if ($statusId !== null) {
            $qb->andWhere($qb->expr()->eq('c.status_id', $qb->createNamedParameter($statusId, IQueryBuilder::PARAM_INT)));
        }
        if ($search !== null && trim($search) !== '') {
            $likeParam = $qb->createNamedParameter('%' . $this->db->escapeLikeParameter(trim($search)) . '%');
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->iLike('c.title', $likeParam),
                $qb->expr()->iLike('c.case_number', $likeParam),
            ));
        }
        if ($classificationFacetUuid !== null && $classificationFacetUuid !== '') {
            $qb->andWhere($qb->expr()->eq('c.classification_facet_uuid', $qb->createNamedParameter($classificationFacetUuid)));
        }
        if ($insightLevelId !== null) {
            $qb->andWhere($qb->expr()->eq('c.insight_level_id', $qb->createNamedParameter($insightLevelId, IQueryBuilder::PARAM_INT)));
        }
        if ($responsibleUserId !== null && $responsibleUserId !== '') {
            $qb->andWhere($qb->expr()->eq('c.responsible_user_id', $qb->createNamedParameter($responsibleUserId)));
        }
        if ($casetypeId !== null) {
            $qb->andWhere($qb->expr()->eq('c.casetype_id', $qb->createNamedParameter($casetypeId, IQueryBuilder::PARAM_INT)));
        }

        return $this->findEntities($qb);
    }

    /**
     * Count cases with optional filters, scoped to all access paths for the user.
     *
     * @param string|null $orgUuid  Filter by org UUID
     */
    public function countWithFilters(
        string $userId,
        ?string $orgUuid = null,
        ?string $year = null,
        ?int $statusId = null,
        ?string $search = null,
        ?string $classificationFacetUuid = null,
        ?int $insightLevelId = null,
        ?string $responsibleUserId = null,
        ?string $classificationCode = null,
        ?string $sensitivityKey = null,
        ?int $casetypeId = null,
    ): int {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $qb  = $this->db->getQueryBuilder();
        // DISTINCT because a case could theoretically match multiple OR arms
        $qb->selectAlias($qb->createFunction('COUNT(DISTINCT c.id)'), 'cnt')
            ->from($this->getTableName(), 'c')
            ->leftJoin('c', 'opencase_case_users', 'cu', $qb->expr()->andX(
                $qb->expr()->eq('cu.case_id', 'c.id'),
                $qb->expr()->eq('cu.user_id', $qb->createNamedParameter($userId)),
                $qb->expr()->orX(
                    $qb->expr()->isNull('cu.expires_at'),
                    $qb->expr()->gt('cu.expires_at', $qb->createNamedParameter($now)),
                ),
            ));

        // where() must come before all andWhere() calls — calling where() after andWhere()
        // replaces the accumulated conditions in the Doctrine query builder.
        $qb->where($qb->expr()->orX(...$this->buildAccessOr($qb, $userId)));
        $qb->andWhere($qb->expr()->eq('c.is_inbox', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

        // Join-based filters: must be added after where() so andWhere() appends correctly.
        $needsProfileJoin = ($classificationCode !== null && $classificationCode !== '')
            || ($sensitivityKey !== null && $sensitivityKey !== '');
        if ($needsProfileJoin) {
            $qb->innerJoin('c', 'opencase_access_profiles', 'ap_f',
                $qb->expr()->eq('ap_f.id', 'c.access_profile_id'));
        }
        if ($classificationCode !== null && $classificationCode !== '') {
            $qb->innerJoin('ap_f', 'opencase_class_subject', 'cs_f',
                $qb->expr()->eq('ap_f.class_subject_uuid', 'cs_f.uuid'));
            $qb->andWhere($qb->expr()->eq('cs_f.code', $qb->createNamedParameter($classificationCode)));
        }
        if ($sensitivityKey !== null && $sensitivityKey !== '') {
            $qb->innerJoin('ap_f', 'opencase_sensitivity', 'sens_f',
                $qb->expr()->eq('ap_f.sensitivity_uuid', 'sens_f.uuid'));
            $qb->andWhere($qb->expr()->eq('sens_f.key', $qb->createNamedParameter($sensitivityKey)));
        }

        if ($orgUuid !== null) {
            $qb->andWhere($qb->expr()->eq('c.org_uuid', $qb->createNamedParameter($orgUuid)));
        }
        if ($year !== null) {
            $qb->andWhere($qb->expr()->eq('c.year', $qb->createNamedParameter($year)));
        }
        if ($statusId !== null) {
            $qb->andWhere($qb->expr()->eq('c.status_id', $qb->createNamedParameter($statusId, IQueryBuilder::PARAM_INT)));
        }
        if ($search !== null && trim($search) !== '') {
            $likeParam = $qb->createNamedParameter('%' . $this->db->escapeLikeParameter(trim($search)) . '%');
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->iLike('c.title', $likeParam),
                $qb->expr()->iLike('c.case_number', $likeParam),
            ));
        }
        if ($classificationFacetUuid !== null && $classificationFacetUuid !== '') {
            $qb->andWhere($qb->expr()->eq('c.classification_facet_uuid', $qb->createNamedParameter($classificationFacetUuid)));
        }
        if ($insightLevelId !== null) {
            $qb->andWhere($qb->expr()->eq('c.insight_level_id', $qb->createNamedParameter($insightLevelId, IQueryBuilder::PARAM_INT)));
        }
        if ($responsibleUserId !== null && $responsibleUserId !== '') {
            $qb->andWhere($qb->expr()->eq('c.responsible_user_id', $qb->createNamedParameter($responsibleUserId)));
        }
        if ($casetypeId !== null) {
            $qb->andWhere($qb->expr()->eq('c.casetype_id', $qb->createNamedParameter($casetypeId, IQueryBuilder::PARAM_INT)));
        }

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Search cases by title or case number, scoped to all access paths for the user.
     *
     * @return CaseEntity[]
     */
    public function searchByTitleOrNumber(
        string $query,
        string $userId,
        int $limit = 50,
    ): array {
        if (trim($query) === '') {
            return [];
        }

        $now       = (new \DateTime())->format('Y-m-d H:i:s');
        $qb        = $this->db->getQueryBuilder();
        $likeParam = $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($query) . '%');

        $qb->select('c.*')
            ->from($this->getTableName(), 'c')
            ->leftJoin('c', 'opencase_case_users', 'cu', $qb->expr()->andX(
                $qb->expr()->eq('cu.case_id', 'c.id'),
                $qb->expr()->eq('cu.user_id', $qb->createNamedParameter($userId)),
                $qb->expr()->orX(
                    $qb->expr()->isNull('cu.expires_at'),
                    $qb->expr()->gt('cu.expires_at', $qb->createNamedParameter($now)),
                ),
            ))
            ->orderBy('c.updated_at', 'DESC')
            ->setMaxResults($limit);

        $qb->where($qb->expr()->orX(...$this->buildAccessOr($qb, $userId)));
        $qb->andWhere($qb->expr()->eq('c.is_inbox', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

        $qb->andWhere($qb->expr()->orX(
            $qb->expr()->iLike('c.title', $likeParam),
            $qb->expr()->iLike('c.case_number', $likeParam),
        ));

        return $this->findEntities($qb);
    }

    /**
     * Return the IDs of all inbox cases accessible to the user.
     *
     * Uses the same three-way access control as findWithFilters but
     * targets is_inbox = 1 rows only.
     *
     * @return int[]
     */
    public function findInboxCaseIdsForUser(string $userId): array {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $qb  = $this->db->getQueryBuilder();
        $qb->select('c.id')
            ->from($this->getTableName(), 'c')
            ->leftJoin('c', 'opencase_case_users', 'cu', $qb->expr()->andX(
                $qb->expr()->eq('cu.case_id', 'c.id'),
                $qb->expr()->eq('cu.user_id', $qb->createNamedParameter($userId)),
                $qb->expr()->orX(
                    $qb->expr()->isNull('cu.expires_at'),
                    $qb->expr()->gt('cu.expires_at', $qb->createNamedParameter($now)),
                ),
            ))
            ->where($qb->expr()->eq('c.is_inbox', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->orX(...$this->buildAccessOr($qb, $userId)));

        $result = $qb->executeQuery();
        $ids    = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['id'];
        }
        $result->closeCursor();
        return $ids;
    }

    /**
     * Build the OR conditions that cover all three user access paths.
     *
     * Must be called after the LEFT JOIN on opencase_case_users alias 'cu' has
     * been added to the query. The caller passes the IQueryBuilder so that any
     * named parameters registered here are bound to the same statement.
     *
     * @return \OCP\DB\QueryBuilder\ICompositeExpression[]|string[]
     */
    private function buildAccessOr(
        \OCP\DB\QueryBuilder\IQueryBuilder $qb,
        string $userId,
    ): array {
        return [
            // Responsible user — indexed column, always write access
            $qb->expr()->eq('c.responsible_user_id', $qb->createNamedParameter($userId)),
            // Active direct grant — LEFT JOIN with expiry filter in ON clause
            $qb->expr()->isNotNull('cu.case_id'),
            // Profile-based access
            $this->profileAccessExists($qb, $userId),
        ];
    }

    /**
     * Correlated EXISTS against opencase_user_access for the profile-based
     * access path.
     *
     * This replaces an earlier `c.access_profile_id IN (:profileIds)` where the
     * caller resolved every profile ID in PHP and inlined it. With 1000
     * organisations and 2000 KLE subjects a broadly privileged user can hold
     * six figures of profile IDs — enough to blow up the SQL text and make the
     * optimiser abandon the index. The EXISTS form keeps the work in the
     * database and is served by the oc_ua_user_profile (user_id,
     * access_profile_id) unique index, one index dive per candidate case.
     *
     * The named parameter is registered on the OUTER builder so it binds to the
     * statement that actually executes; only the SQL text comes from the
     * sub-builder.
     *
     * Requires the outer query to alias opencase_cases as 'c'.
     */
    private function profileAccessExists(
        \OCP\DB\QueryBuilder\IQueryBuilder $qb,
        string $userId,
    ): string {
        $sub = $this->db->getQueryBuilder();
        $sub->select($sub->expr()->literal(1))
            ->from('opencase_user_access', 'ua')
            ->where($sub->expr()->eq('ua.access_profile_id', 'c.access_profile_id'))
            ->andWhere($sub->expr()->eq('ua.user_id', $qb->createNamedParameter($userId)));

        return 'EXISTS (' . $sub->getSQL() . ')';
    }
}
