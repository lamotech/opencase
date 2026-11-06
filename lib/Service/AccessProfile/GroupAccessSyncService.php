<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\AccessProfile;

use OCA\OpenCase\Db\AccessProfile;
use OCA\OpenCase\Db\AccessProfileMapper;
use OCA\OpenCase\Db\UserAccessMapper;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\FileService;
use OCA\OpenCase\Service\PermissionService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Keeps opencase_user_access in step with Nextcloud group membership for
 * 'group' access profiles.
 *
 * Members of a profile's group get a grant_source='group' row with 'write'
 * access. Rows from other sources (manual, privilege) are never touched: a
 * user who already has a row for the profile keeps it, and is not given an
 * additional group row (the table is unique on user_id + access_profile_id).
 *
 * Membership is read through IGroupManager rather than oc_group_user so that
 * every group backend (database, LDAP, SAML, ...) works.
 *
 * In group mode (accessprofile_type = 'group'), groups named with the access
 * group prefix (see AccessGroupNaming) are registered as access groups
 * automatically when they are created, when a user is added to them, and by
 * syncAll() — so groups provisioned from e.g. Microsoft Entra ID at login
 * need no manual registration.
 *
 * Kept current by GroupAccessListener (membership events, and a per-user
 * reconcile at every login) and reconciled by
 * GroupAccessSyncJob / occ opencase:access:sync-groups, which also catch
 * changes made by backends that do not fire events (e.g. LDAP).
 */
class GroupAccessSyncService {

    public const GRANT_SOURCE = 'group';
    public const ACCESS_LEVEL = 'write';

    private const CHUNK_SIZE = 1000;

    public function __construct(
        private IDBConnection $db,
        private IGroupManager $groupManager,
        private AccessProfileMapper $accessProfileMapper,
        private PermissionService $permissionService,
        private FileService $fileService,
        private Configuration $configuration,
        private AccessGroupNaming $accessGroupNaming,
        private UserAccessMapper $userAccessMapper,
        private LoggerInterface $logger,
    ) {}

    /**
     * Register the group as access group if it qualifies (group mode, name
     * starts with the access group prefix) and has no profile yet, granting
     * the profile to its current members.
     *
     * @return AccessProfile|null The new profile, or null when nothing was registered
     */
    public function registerGroup(IGroup $group): ?AccessProfile {
        if (!$this->isGroupMode()
            || !$this->accessGroupNaming->isAccessGroup($group)
            || $this->accessProfileMapper->findByGroupId($group->getGID()) !== null) {
            return null;
        }
        $profile = $this->accessProfileMapper->findOrCreateByGroup($group->getGID());
        $this->syncProfile($profile);
        return $profile;
    }

    /**
     * Register every qualifying group that has no access profile yet.
     *
     * @return int Number of groups registered
     */
    public function registerMatchingGroups(): int {
        if (!$this->isGroupMode()) {
            return 0;
        }
        $registered = 0;
        foreach ($this->groupManager->search('') as $group) {
            if ($this->registerGroup($group) !== null) {
                $registered++;
            }
        }
        return $registered;
    }

    /**
     * A group was created: register it as access group if it qualifies.
     */
    public function groupCreated(string $groupId): void {
        if ($this->accessProfileMapper->findByGroupId($groupId)?->isGroupDeleted()) {
            $this->logger->warning('OpenCase: group "{group}" was created with the id of a deleted access group. It gets no access to that group\'s cases until an administrator adds it under Adgangsgrupper.', [
                'app'   => 'opencase',
                'group' => $groupId,
            ]);
            return;
        }
        $group = $this->groupManager->get($groupId);
        if ($group !== null) {
            $this->registerGroup($group);
        }
    }

    /**
     * Reconcile one profile's group grants with its group's current members.
     * A profile whose group no longer exists loses all its group grants.
     *
     * @return array{added: int, removed: int}
     */
    public function syncProfile(AccessProfile $profile): array {
        $groupId = $profile->getGroupId();
        if ($groupId === null || $groupId === '' || $profile->isGroupDeleted()) {
            return ['added' => 0, 'removed' => 0];
        }
        $profileId = $profile->getId();

        $group   = $this->groupManager->get($groupId);
        $members = $group === null ? [] : array_map(fn(IUser $u) => $u->getUID(), $group->getUsers());
        $members = array_fill_keys($members, true);

        $existing = $this->getGrantSources($profileId);

        $toAdd    = array_keys(array_diff_key($members, $existing));
        $toRemove = array_keys(array_filter(
            $existing,
            fn(string $source, int|string $uid) => $source === self::GRANT_SOURCE && !isset($members[$uid]),
            ARRAY_FILTER_USE_BOTH
        ));

        if (empty($toAdd) && empty($toRemove)) {
            return ['added' => 0, 'removed' => 0];
        }

        $this->db->beginTransaction();
        try {
            foreach ($toAdd as $uid) {
                $this->insertGrant((string)$uid, $profileId);
            }
            $this->deleteGrants($profileId, array_map('strval', $toRemove));
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->afterChange([$profileId], array_merge($toAdd, $toRemove));

        return ['added' => count($toAdd), 'removed' => count($toRemove)];
    }

    /**
     * Register qualifying groups, then reconcile every 'group' access profile.
     *
     * @return array{registered: int, profiles: int, added: int, removed: int}
     */
    public function syncAll(): array {
        $totals = ['registered' => $this->registerMatchingGroups(), 'profiles' => 0, 'added' => 0, 'removed' => 0];
        foreach ($this->accessProfileMapper->findGroupProfiles() as $profile) {
            $result = $this->syncProfile($profile);
            $totals['profiles']++;
            $totals['added']   += $result['added'];
            $totals['removed'] += $result['removed'];
        }
        return $totals;
    }

    /**
     * A user was added to a group: grant the group's profile, if it has one.
     * A qualifying group without a profile is registered first (which grants
     * all its members, this user included).
     */
    public function userAddedToGroup(string $groupId, string $userId): void {
        $profile = $this->accessProfileMapper->findByGroupId($groupId);
        if ($profile === null) {
            $group = $this->groupManager->get($groupId);
            if ($group !== null) {
                $this->registerGroup($group);
            }
            return;
        }
        if ($profile->isGroupDeleted() || isset($this->getGrantSources($profile->getId(), $userId)[$userId])) {
            return;
        }
        $this->insertGrant($userId, $profile->getId());
        $this->afterChange([$profile->getId()], [$userId]);
    }

    /**
     * A user was removed from a group: revoke the group grant for its profile.
     */
    public function userRemovedFromGroup(string $groupId, string $userId): void {
        $profile = $this->accessProfileMapper->findByGroupId($groupId);
        if ($profile === null) {
            return;
        }
        if ($this->deleteGrants($profile->getId(), [$userId]) > 0) {
            $this->afterChange([$profile->getId()], [$userId]);
        }
    }

    /**
     * Reconcile one user's group grants with their current groups — used at
     * login, when the identity provider (e.g. Microsoft Entra ID) may have
     * changed the user's groups. Qualifying groups without a profile are
     * registered first.
     */
    public function syncUser(IUser $user): void {
        $userId = $user->getUID();

        $profileIds = [];
        foreach ($this->groupManager->getUserGroups($user) as $group) {
            $profile = $this->accessProfileMapper->findByGroupId($group->getGID()) ?? $this->registerGroup($group);
            if ($profile !== null && !$profile->isGroupDeleted()) {
                $profileIds[$profile->getId()] = true;
            }
        }

        $granted = $this->getUserGrantSources($userId);
        $changed = [];
        foreach (array_keys($profileIds) as $profileId) {
            // A row from another source (manual, privilege) is kept as is.
            if (!isset($granted[$profileId])) {
                $this->insertGrant($userId, $profileId);
                $changed[] = $profileId;
            }
        }
        foreach ($granted as $profileId => $source) {
            if ($source === self::GRANT_SOURCE && !isset($profileIds[$profileId])
                && $this->deleteGrants($profileId, [$userId]) > 0) {
                $changed[] = $profileId;
            }
        }

        if (!empty($changed)) {
            $this->afterChange($changed, [$userId]);
        }
    }

    /**
     * A group was deleted: revoke all group grants for its profile. A profile
     * with cases is kept, so they stay attached to it, but marked: group ids
     * are names, so a new group with the same id may be someone else's and
     * must not get the cases. Only an administrator can connect it again
     * (GroupAccessProfileType::register). Without cases the profile goes, so
     * a new group is registered as usual.
     *
     * Backends that don't fire the event (e.g. LDAP) never get here, nor does
     * a group that is only unreachable for a while.
     */
    public function groupDeleted(string $groupId): void {
        $profile = $this->accessProfileMapper->findByGroupId($groupId);
        if ($profile === null) {
            return;
        }
        $userIds = array_keys(array_filter(
            $this->getGrantSources($profile->getId()),
            fn(string $source) => $source === self::GRANT_SOURCE
        ));
        if (!$this->accessProfileMapper->isUsedByCases($profile->getId())) {
            foreach ($this->userAccessMapper->revokeAllForProfile($profile->getId()) as $userId) {
                $this->permissionService->invalidateUserCache($userId);
            }
            $this->accessProfileMapper->delete($profile);
            return;
        }
        $profile->setGroupDeleted(1);
        $this->accessProfileMapper->update($profile);
        if ($this->deleteGrants($profile->getId(), null) > 0) {
            $this->afterChange([$profile->getId()], $userIds);
        }
    }

    /**
     * A user was deleted: remove all of their group grants.
     */
    public function userDeleted(string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('access_profile_id')
            ->from('opencase_user_access')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('grant_source', $qb->createNamedParameter(self::GRANT_SOURCE)));
        $result     = $qb->executeQuery();
        $profileIds = [];
        while ($row = $result->fetch()) {
            $profileIds[] = (int)$row['access_profile_id'];
        }
        $result->closeCursor();

        if (empty($profileIds)) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->delete('opencase_user_access')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('grant_source', $qb->createNamedParameter(self::GRANT_SOURCE)));
        $qb->executeStatement();

        $this->afterChange($profileIds, [$userId]);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function isGroupMode(): bool {
        return $this->configuration->getConfigValue(AccessProfileTypeResolver::CONFIG_KEY, AccessProfile::TYPE_FK) === AccessProfile::TYPE_GROUP;
    }

    /**
     * user_id => grant_source for the profile's rows, optionally for one user.
     *
     * @return array<string, string>
     */
    private function getGrantSources(int $profileId, ?string $userId = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id', 'grant_source')
            ->from('opencase_user_access')
            ->where($qb->expr()->eq('access_profile_id', $qb->createNamedParameter($profileId, IQueryBuilder::PARAM_INT)));
        if ($userId !== null) {
            $qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        }

        $result  = $qb->executeQuery();
        $sources = [];
        while ($row = $result->fetch()) {
            $sources[(string)$row['user_id']] = (string)$row['grant_source'];
        }
        $result->closeCursor();

        return $sources;
    }

    /**
     * access_profile_id => grant_source for the user's rows.
     *
     * @return array<int, string>
     */
    private function getUserGrantSources(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('access_profile_id', 'grant_source')
            ->from('opencase_user_access')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $result  = $qb->executeQuery();
        $sources = [];
        while ($row = $result->fetch()) {
            $sources[(int)$row['access_profile_id']] = (string)$row['grant_source'];
        }
        $result->closeCursor();

        return $sources;
    }

    private function insertGrant(string $userId, int $profileId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('opencase_user_access')
            ->values([
                'user_id'           => $qb->createNamedParameter($userId),
                'access_profile_id' => $qb->createNamedParameter($profileId, IQueryBuilder::PARAM_INT),
                'access_level'      => $qb->createNamedParameter(self::ACCESS_LEVEL),
                'granted_at'        => $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s')),
                'granted_by'        => $qb->createNamedParameter('system'),
                'grant_source'      => $qb->createNamedParameter(self::GRANT_SOURCE),
            ]);
        $qb->executeStatement();
    }

    /**
     * Delete group grants for a profile — for the given users, or all of them
     * when $userIds is null.
     *
     * @param string[]|null $userIds
     * @return int Rows deleted
     */
    private function deleteGrants(int $profileId, ?array $userIds): int {
        if ($userIds === null) {
            return $this->deleteGrantsQuery($profileId)->executeStatement();
        }

        $deleted = 0;
        foreach (array_chunk($userIds, self::CHUNK_SIZE) as $chunk) {
            $qb = $this->deleteGrantsQuery($profileId);
            $qb->andWhere($qb->expr()->in('user_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
            $deleted += $qb->executeStatement();
        }
        return $deleted;
    }

    private function deleteGrantsQuery(int $profileId): IQueryBuilder {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('opencase_user_access')
            ->where($qb->expr()->eq('access_profile_id', $qb->createNamedParameter($profileId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('grant_source', $qb->createNamedParameter(self::GRANT_SOURCE)));
        return $qb;
    }

    /**
     * Same follow-up as the admin grant/revoke endpoints: drop the users'
     * cached profile lists and refresh the profiles' filecache ceilings.
     *
     * @param int[]    $profileIds
     * @param string[] $userIds
     */
    private function afterChange(array $profileIds, array $userIds): void {
        foreach (array_unique($userIds) as $userId) {
            $this->permissionService->invalidateUserCache((string)$userId);
        }
        foreach (array_unique($profileIds) as $profileId) {
            $this->fileService->updateCeilingPermissionsForProfile($profileId);
        }
    }
}
