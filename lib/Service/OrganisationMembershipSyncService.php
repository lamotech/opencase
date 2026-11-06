<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\OrganisationRepository;
use OCA\OpenCase\Db\UserInfoMapper;
use OCA\OpenCase\Db\UserOrgMapper;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Organisation membership from Nextcloud groups — for customers without the
 * Serviceplatformen integration, where users log in with e.g. Microsoft
 * Entra ID and their department is an Entra group.
 *
 * An organisation's member group is opencase_org.member_group_id (the "group"
 * column of the organisation CSV import). For each member of that group the
 * service keeps, like a SAML login does for FK Adgang users:
 *   - an opencase_userorgs row with source 'group' (role Sagsbehandler);
 *   - membership of the organisation's own Nextcloud group (nc_group_id,
 *     org_<uuid>), which gives access to its team folder.
 *
 * Membership is explicit: a member of a parent organisation's group is not
 * a member of its sub-organisations (case scopes on an organisation already
 * cover its sub-units). Only active organisations count. Rows from the SAML
 * token or "Rettigheder" (source 'privilege') are never changed.
 *
 * Kept current by GroupAccessListener (membership events) and reconciled by
 * GroupAccessSyncJob / occ opencase:access:sync-groups, and after an
 * organisation import.
 */
class OrganisationMembershipSyncService {

    public const ROLE = 'Sagsbehandler';

    public function __construct(
        private OrganisationRepository $orgRepo,
        private UserOrgMapper $userOrgMapper,
        private UserInfoMapper $userInfoMapper,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
    ) {}

    public function isMemberGroup(string $groupId): bool {
        return $this->orgRepo->isMemberGroup($groupId);
    }

    /**
     * Reconcile one user's group-based memberships with their groups.
     */
    public function syncUser(string $userId): void {
        $user = $this->userManager->get($userId);
        if ($user === null) {
            $this->userOrgMapper->removeGroupMemberships($userId);
            return;
        }

        $userGroupIds = array_flip($this->groupManager->getUserGroupIds($user));
        $mapped  = $this->orgRepo->findActiveWithMemberGroup();
        $desired = array_filter($mapped, fn(array $org) => isset($userGroupIds[(string)$org['member_group_id']]));
        $desiredUuids = array_column($desired, 'org_uuid');

        $sources = $this->userOrgMapper->findSourcesForUserId($userId);

        if ($desired !== []) {
            $userUuid = $this->userInfoMapper->ensureForUser($user)->getUuid();
            foreach ($desired as $org) {
                $this->userOrgMapper->addGroupMembership($userUuid, $userId, (string)$org['org_uuid'], self::ROLE);
            }
        }

        // Group rows for organisations the user no longer gets from a group
        // (left the group, organisation deactivated or unmapped)
        $stale = array_keys(array_filter(
            $sources,
            fn(string $source, int|string $orgUuid) => $source === UserOrgMapper::SOURCE_GROUP && !in_array((string)$orgUuid, $desiredUuids, true),
            ARRAY_FILTER_USE_BOTH
        ));
        $this->userOrgMapper->removeGroupMemberships($userId, array_map('strval', $stale));

        // Team folder access via the organisation's own group. Only mapped
        // organisations are managed here; a membership the user also has
        // from their privileges is left alone.
        foreach ($mapped as $org) {
            $orgUuid = (string)$org['org_uuid'];
            $group   = !empty($org['nc_group_id']) ? $this->groupManager->get((string)$org['nc_group_id']) : null;
            if ($group === null) {
                continue;
            }
            if (in_array($orgUuid, $desiredUuids, true)) {
                if (!$group->inGroup($user)) {
                    $group->addUser($user);
                }
            } elseif (($sources[$orgUuid] ?? null) !== UserOrgMapper::SOURCE_PRIVILEGE && $group->inGroup($user)) {
                $group->removeUser($user);
            }
        }
    }

    /**
     * Reconcile everyone: members of a member group, and users with group
     * rows (who may have left one).
     *
     * @return array{users: int}
     */
    public function syncAll(): array {
        $userIds = array_fill_keys($this->userOrgMapper->findUserIdsWithGroupMemberships(), true);
        foreach ($this->orgRepo->findActiveWithMemberGroup() as $org) {
            foreach ($this->groupManager->get((string)$org['member_group_id'])?->getUsers() ?? [] as $user) {
                $userIds[$user->getUID()] = true;
            }
        }
        foreach (array_keys($userIds) as $userId) {
            $this->syncUser((string)$userId);
        }
        return ['users' => count($userIds)];
    }

    /**
     * A user was added to or removed from a group.
     */
    public function membershipChanged(string $groupId, IUser $user): void {
        if ($this->isMemberGroup($groupId)) {
            $this->syncUser($user->getUID());
        }
    }

    /**
     * A user was deleted: remove their group-based memberships.
     */
    public function userDeleted(string $userId): void {
        $this->userOrgMapper->removeGroupMemberships($userId);
    }
}
