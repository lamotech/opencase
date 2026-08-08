<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Manages the storage and application of SAML privilege constraints.
 *
 * Row count comparison (Token01.xml, ~80 KLE patterns per group):
 *   Old (pre-expanded UUIDs): 5 418 rows/user  → 27 M rows at 5 000 users
 *   New (stored patterns):      ~160 rows/user  →  0.8 M rows at 5 000 users
 *
 * Key design: kle_pattern is stored in SQL LIKE syntax ("27.%", "27.25.00", "%").
 * Matching reverses the usual LIKE direction: ':code LIKE stored_pattern'
 * or, for indexed lookups, the candidate patterns for a known code are
 * computed in PHP and queried with IN().
 *
 * Flow on each login (syncPrivileges):
 *   1. Save raw privilege groups to opencase_user_priv_groups.
 *   2. Convert KLE strings to LIKE patterns and write to opencase_user_priv_match
 *      (one row per sensitivity × kle_pattern × org tuple).
 *   3. Rebuild opencase_user_access rows with grant_source='privilege' by
 *      joining the match table against existing access profiles.
 *
 * Flow when a new access_profile is created (grantPrivilegedUsersToProfile):
 *   1. Derive candidate LIKE patterns from the profile's KLE code.
 *   2. Single indexed IN() query finds all matching users.
 *   3. Insert them into opencase_user_access.
 */
class PrivilegeService {

    public function __construct(private IDBConnection $db) {}

    // ─── Public API ──────────────────────────────────────────────────────────

    /**
     * Persist privilege groups from the SAML token and rebuild this user's
     * access-profile grants.
     *
     * @param string $userId  NC user ID (opencase_<uuid>)
     * @param array  $groups  Parsed privilege groups from getUserPrivilege():
     *                        [['privilege_type'=>'opencaseuser',
     *                          'foelsomhed'=>[uuid,...],
     *                          'kle'=>['00.*','27.25.00',...],
     *                          'orgenhed'=>[uuid,...]], ...]
     */
    public function syncPrivileges(string $userId, array $groups): void {
        $this->savePrivilegeGroups($userId, $groups);
        $this->rebuildMatchTable($userId, $groups);
        $this->rebuildUserAccess($userId);
    }

    /**
     * When a new access_profile is created, grant access to every user whose
     * SAML constraints cover it.  Safe to call even if the profile existed —
     * duplicate inserts are silently ignored.
     *
     * @param int         $profileId
     * @param string|null $orgUuid          null = profile has no org restriction
     * @param string      $classSubjectUuid
     * @param string      $sensitivityUuid
     */
    public function grantPrivilegedUsersToProfile(
        int $profileId,
        ?string $orgUuid,
        string $classSubjectUuid,
        string $sensitivityUuid
    ): void {
        $kleCode = $this->getKleCode($classSubjectUuid);
        if ($kleCode === null) {
            return;
        }

        $now = (new \DateTime())->format('Y-m-d H:i:s');

        // Write grants first, then read — unique constraint ensures write wins
        foreach (['write', 'read'] as $accessLevel) {
            $userIds = $this->findUsersMatchingProfile($orgUuid, $kleCode, $sensitivityUuid, $accessLevel);
            foreach ($userIds as $uid) {
                try {
                    $qb = $this->db->getQueryBuilder();
                    $qb->insert('opencase_user_access')
                        ->values([
                            'user_id'           => $qb->createNamedParameter($uid),
                            'access_profile_id' => $qb->createNamedParameter($profileId, IQueryBuilder::PARAM_INT),
                            'access_level'      => $qb->createNamedParameter($accessLevel),
                            'granted_at'        => $qb->createNamedParameter($now),
                            'granted_by'        => $qb->createNamedParameter('system'),
                            'grant_source'      => $qb->createNamedParameter('privilege'),
                        ]);
                    $qb->executeStatement();
                } catch (\Exception) {
                    // Duplicate (manual or write grant already exists) — leave it
                }
            }
        }
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function savePrivilegeGroups(string $userId, array $groups): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('opencase_user_priv_groups')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();

        $now = (new \DateTime())->format('Y-m-d H:i:s');
        foreach ($groups as $group) {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('opencase_user_priv_groups')
                ->values([
                    'user_id'        => $qb->createNamedParameter($userId),
                    'privilege_type' => $qb->createNamedParameter($group['privilege_type']),
                    'foelsomhed_raw' => $qb->createNamedParameter(
                        !empty($group['foelsomhed']) ? implode(',', $group['foelsomhed']) : null
                    ),
                    'kle_raw'        => $qb->createNamedParameter(
                        !empty($group['kle']) ? implode(',', $group['kle']) : null
                    ),
                    'orgenhed_raw'   => $qb->createNamedParameter(
                        !empty($group['orgenhed']) ? implode(',', $group['orgenhed']) : null
                    ),
                    'updated_at'     => $qb->createNamedParameter($now),
                ]);
            $qb->executeStatement();
        }
    }

    /**
     * Rebuild opencase_user_priv_match for this user.
     *
     * Processes both opencaseuser (write) and opencasereaduser (read) groups.
     * When the same (sensitivity × kle_pattern × org) tuple appears in both,
     * write wins — only one row is stored per unique tuple.
     */
    private function rebuildMatchTable(string $userId, array $groups): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('opencase_user_priv_match')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();

        // key => [$sens, $pattern, $org, $accessLevel]
        // Write groups are processed first so write beats read on the same key.
        $tuples = [];
        foreach (['opencaseuser' => 'write', 'opencasereaduser' => 'read'] as $type => $level) {
            foreach ($groups as $group) {
                if ($group['privilege_type'] !== $type) {
                    continue;
                }

                $sensitivities = $group['foelsomhed'] ?? [];
                $klePatterns   = array_map(
                    [$this, 'toSqlLikePattern'],
                    $group['kle'] ?? []
                );
                $orgValues = !empty($group['orgenhed']) ? $group['orgenhed'] : [''];

                foreach ($sensitivities as $sens) {
                    foreach ($klePatterns as $pattern) {
                        foreach ($orgValues as $org) {
                            $key = "$sens|$pattern|$org";
                            // Only set if not already covered by a write group
                            if (!isset($tuples[$key])) {
                                $tuples[$key] = [$sens, $pattern, $org, $level];
                            }
                        }
                    }
                }
            }
        }

        if (empty($tuples)) {
            return;
        }

        $this->db->beginTransaction();
        try {
            foreach ($tuples as [$sens, $pattern, $org, $accessLevel]) {
                try {
                    $qb = $this->db->getQueryBuilder();
                    $qb->insert('opencase_user_priv_match')
                        ->values([
                            'user_id'          => $qb->createNamedParameter($userId),
                            'sensitivity_uuid' => $qb->createNamedParameter($sens),
                            'kle_pattern'      => $qb->createNamedParameter($pattern),
                            'org_uuid'         => $qb->createNamedParameter($org),
                            'access_level'     => $qb->createNamedParameter($accessLevel),
                        ]);
                    $qb->executeStatement();
                } catch (\Exception) {
                    // Duplicate from overlapping privilege groups — safe to skip
                }
            }
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Rebuild privilege-sourced rows in opencase_user_access.
     *
     * Write grants are inserted first; read grants follow but are silently
     * ignored for profiles already covered by a write grant (unique constraint
     * on (user_id, access_profile_id) in opencase_user_access).
     */
    private function rebuildUserAccess(string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('opencase_user_access')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('grant_source', $qb->createNamedParameter('privilege')));
        $qb->executeStatement();

        $now = (new \DateTime())->format('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            foreach (['write', 'read'] as $accessLevel) {
                $profileIds = $this->fetchProfileIdsForUser($userId, $accessLevel);
                foreach ($profileIds as $profileId) {
                    try {
                        $qb = $this->db->getQueryBuilder();
                        $qb->insert('opencase_user_access')
                            ->values([
                                'user_id'           => $qb->createNamedParameter($userId),
                                'access_profile_id' => $qb->createNamedParameter($profileId, IQueryBuilder::PARAM_INT),
                                'access_level'      => $qb->createNamedParameter($accessLevel),
                                'granted_at'        => $qb->createNamedParameter($now),
                                'granted_by'        => $qb->createNamedParameter('system'),
                                'grant_source'      => $qb->createNamedParameter('privilege'),
                            ]);
                        $qb->executeStatement();
                    } catch (\Exception) {
                        // Write grant already covers this profile (or manual grant exists) — leave it
                    }
                }
            }
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Find profile IDs that match this user's privilege tuples for a given access level.
     *
     * @return int[]
     */
    private function fetchProfileIdsForUser(string $userId, string $accessLevel): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('ap.id')
            ->from('opencase_access_profiles', 'ap')
            ->innerJoin('ap', 'opencase_class_subject', 'cs',
                $qb->expr()->eq('cs.uuid', 'ap.class_subject_uuid')
            )
            ->innerJoin('ap', 'opencase_user_priv_match', 'upm',
                $qb->expr()->andX(
                    $qb->expr()->eq('upm.user_id', $qb->createNamedParameter($userId)),
                    $qb->expr()->eq('upm.sensitivity_uuid', 'ap.sensitivity_uuid'),
                    $qb->expr()->eq('upm.access_level', $qb->createNamedParameter($accessLevel)),
                    $qb->createFunction('cs.code LIKE upm.kle_pattern')
                )
            )
            ->where($qb->expr()->isNotNull('ap.sensitivity_uuid'))
            ->andWhere($qb->expr()->isNotNull('ap.class_subject_uuid'))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->eq('upm.org_uuid', $qb->createNamedParameter('')),
                $qb->expr()->eq('upm.org_uuid', 'ap.org_uuid')
            ));

        $result     = $qb->executeQuery();
        $profileIds = [];
        while ($row = $result->fetch()) {
            $profileIds[] = (int)$row['id'];
        }
        $result->closeCursor();

        return $profileIds;
    }

    /**
     * Find all user IDs whose privilege patterns cover a profile's KLE code
     * at the given access level.
     *
     * @return string[]
     */
    private function findUsersMatchingProfile(
        ?string $orgUuid,
        string $kleCode,
        string $sensitivityUuid,
        string $accessLevel
    ): array {
        $candidates = $this->candidatePatternsForCode($kleCode);

        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('user_id')
            ->from('opencase_user_priv_match')
            ->where($qb->expr()->eq('sensitivity_uuid', $qb->createNamedParameter($sensitivityUuid)))
            ->andWhere($qb->expr()->eq('access_level', $qb->createNamedParameter($accessLevel)))
            ->andWhere($qb->expr()->in(
                'kle_pattern',
                $qb->createNamedParameter($candidates, IQueryBuilder::PARAM_STR_ARRAY)
            ));

        if ($orgUuid === null) {
            $qb->andWhere($qb->expr()->eq('org_uuid', $qb->createNamedParameter('')));
        } else {
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->eq('org_uuid', $qb->createNamedParameter('')),
                $qb->expr()->eq('org_uuid', $qb->createNamedParameter($orgUuid))
            ));
        }

        $result  = $qb->executeQuery();
        $userIds = [];
        while ($row = $result->fetch()) {
            $userIds[] = $row['user_id'];
        }
        $result->closeCursor();

        return $userIds;
    }

    /**
     * Look up the KLE code string for a class_subject UUID.
     */
    private function getKleCode(string $classSubjectUuid): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('code')
            ->from('opencase_class_subject')
            ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($classSubjectUuid)));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return $row ? $row['code'] : null;
    }

    /**
     * Convert a token KLE pattern to SQL LIKE syntax.
     *
     *   "00.*"    → "00.%"
     *   "27.25.00" → "27.25.00"
     *   "*"        → "%"
     */
    private function toSqlLikePattern(string $pattern): string {
        $pattern = trim($pattern);
        if ($pattern === '*') {
            return '%';
        }
        if (str_ends_with($pattern, '.*')) {
            return substr($pattern, 0, -1) . '%';  // replace trailing * with %
        }
        return $pattern;
    }

    /**
     * Given a concrete KLE code, return every LIKE pattern that could match it.
     *
     * "27.25.00" → ["27.25.00", "27.25.%", "27.%", "%"]
     * "00.01.02" → ["00.01.02", "00.01.%", "00.%", "%"]
     */
    private function candidatePatternsForCode(string $code): array {
        $candidates = [$code, '%'];
        $parts = explode('.', $code);
        for ($i = 1; $i < count($parts); $i++) {
            $candidates[] = implode('.', array_slice($parts, 0, $i)) . '.%';
        }
        return array_unique($candidates);
    }
}
