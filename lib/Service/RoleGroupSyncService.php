<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\AccessProfile;
use OCA\OpenCase\Db\Role;
use OCA\OpenCase\Db\RoleMapper;
use OCA\OpenCase\Db\UserRole;
use OCA\OpenCase\Db\UserRoleMapper;
use OCA\OpenCase\Service\AccessProfile\AccessProfileTypeResolver;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Gives OpenCase roles to the members of the Nextcloud groups mapped to them
 * — e.g. groups provisioned from Microsoft Entra ID at OIDC login.
 *
 * The roles are the rows of opencase_roles, so a new role works without code
 * changes. The mapping is stored in the 'role_groups' config as JSON:
 *   {"User": ["gid", ...], "Administrator": [...], ...}
 * Until the mapping is saved, every role has its default group:
 * DEFAULT_GROUP_PREFIX + role name, e.g. "OpenCase Role - Process Designer".
 * These only give roles with Grupper: with FK Adgang roles come from the
 * token, and the mapping can't be seen there. Once saved, a role only has the
 * groups saved for it. A mapped group need not exist yet: groups provisioned
 * at login (e.g. from Entra ID) are created when their first member logs in,
 * and the role is granted from then on.
 *
 * Group-derived roles are opencase_user_roles rows with
 * grant_source = 'group'. Roles from the SAML token or "Rettigheder"
 * (grant_source = 'privilege') are never touched; a user can hold a role
 * from both sources.
 *
 * Kept current by GroupAccessListener (membership events) and reconciled by
 * GroupAccessSyncJob / occ opencase:access:sync-groups.
 */
class RoleGroupSyncService {

    public const CONFIG_KEY = 'role_groups';
    public const DEFAULT_GROUP_PREFIX = 'OpenCase Role - ';

    /** Nextcloud's limit on group ids (oc_groups.gid). */
    private const MAX_GROUP_ID_LENGTH = 64;

    public function __construct(
        private Configuration $configuration,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private RoleMapper $roleMapper,
        private UserRoleMapper $userRoleMapper,
    ) {}

    /**
     * The role names, from opencase_roles, in id order.
     *
     * @return list<string>
     */
    public function roleNames(): array {
        return array_map(fn(Role $role) => $role->getName(), $this->roleMapper->findAll());
    }

    /**
     * The role → group ids mapping, with every role present (in id order).
     *
     * @return array<string, list<string>>
     */
    public function getMapping(): array {
        $value   = $this->configuration->getConfigValue(self::CONFIG_KEY);
        $stored  = $value === null ? null : json_decode($value, true);
        $mapping = [];
        foreach ($this->roleNames() as $role) {
            $groupIds = is_array($stored)
                ? (is_array($stored[$role] ?? null) ? $stored[$role] : [])
                : [self::DEFAULT_GROUP_PREFIX . $role];
            $mapping[$role] = array_values(array_unique(array_map('strval', $groupIds)));
        }
        return $mapping;
    }

    /**
     * The mapping that gives roles: getMapping(), without the default groups
     * under FK Adgang.
     *
     * @return array<string, list<string>>
     */
    private function grantingMapping(): array {
        $mapping = $this->getMapping();
        $isGroupMode = $this->configuration->getConfigValue(AccessProfileTypeResolver::CONFIG_KEY, AccessProfile::TYPE_FK) === AccessProfile::TYPE_GROUP;
        if ($isGroupMode || $this->configuration->getConfigValue(self::CONFIG_KEY) !== null) {
            return $mapping;
        }
        return array_map(fn(array $groupIds) => [], $mapping);
    }

    /**
     * Replace the mapping and reconcile all group-derived roles with it.
     *
     * Groups that do not exist yet are allowed (see class comment).
     *
     * @param array<string, mixed> $mapping role name => list of group ids
     * @throws \InvalidArgumentException for an unknown role or an invalid group id
     */
    public function setMapping(array $mapping): void {
        $roles = $this->roleNames();
        $clean = [];
        foreach ($mapping as $role => $groupIds) {
            if (!in_array($role, $roles, true)) {
                throw new \InvalidArgumentException("Unknown role: {$role}");
            }
            if (!is_array($groupIds)) {
                throw new \InvalidArgumentException("Groups for role {$role} must be a list");
            }
            $groupIds = array_map(fn($groupId) => trim((string)$groupId), $groupIds);
            foreach ($groupIds as $groupId) {
                if ($groupId === '' || mb_strlen($groupId) > self::MAX_GROUP_ID_LENGTH) {
                    throw new \InvalidArgumentException("Invalid group: '{$groupId}' (1-" . self::MAX_GROUP_ID_LENGTH . ' characters)');
                }
            }
            $clean[$role] = array_values(array_unique($groupIds));
        }

        $this->configuration->setConfigValue(self::CONFIG_KEY, json_encode($clean, JSON_UNESCAPED_UNICODE));
        $this->syncAll();
    }

    /**
     * Whether a group is mapped to any role.
     */
    public function isMappedGroup(string $groupId): bool {
        foreach ($this->getMapping() as $groupIds) {
            if (in_array($groupId, $groupIds, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reconcile one user's group-derived roles with their current groups.
     * Runs at every login, so it only writes what changed.
     */
    public function syncUser(string $userId): void {
        $user         = $this->userManager->get($userId);
        $userGroupIds = $user === null ? [] : $this->groupManager->getUserGroupIds($user);

        $roleIds = [];
        foreach ($this->roleMapper->findAll() as $role) {
            $roleIds[$role->getName()] = $role->getId();
        }
        $held = [];
        foreach ($this->userRoleMapper->findByUser($userId) as $userRole) {
            if ($userRole->getGrantSource() === UserRole::SOURCE_GROUP) {
                $held[$userRole->getRoleId()] = true;
            }
        }

        foreach ($this->grantingMapping() as $roleName => $groupIds) {
            $roleId = $roleIds[$roleName] ?? null;
            if ($roleId === null) {
                continue;
            }
            $should = array_intersect($groupIds, $userGroupIds) !== [];
            if ($should && !isset($held[$roleId])) {
                $this->userRoleMapper->assignRole($userId, $roleId, 'system', UserRole::SOURCE_GROUP);
            } elseif (!$should && isset($held[$roleId])) {
                $this->userRoleMapper->revokeRole($userId, $roleId, UserRole::SOURCE_GROUP);
            }
        }
    }

    /**
     * Reconcile every group-derived role with current group membership.
     *
     * @return array{added: int, removed: int}
     */
    public function syncAll(): array {
        $totals = ['added' => 0, 'removed' => 0];
        foreach ($this->grantingMapping() as $roleName => $groupIds) {
            $role = $this->roleMapper->findByName($roleName);
            if ($role === null) {
                continue;
            }

            $members = [];
            foreach ($groupIds as $groupId) {
                foreach ($this->groupManager->get($groupId)?->getUsers() ?? [] as $user) {
                    $members[$user->getUID()] = true;
                }
            }
            $current = array_fill_keys($this->userRoleMapper->findUserIdsByRoleAndSource($role->getId(), UserRole::SOURCE_GROUP), true);

            foreach (array_keys(array_diff_key($members, $current)) as $userId) {
                $this->userRoleMapper->assignRole((string)$userId, $role->getId(), 'system', UserRole::SOURCE_GROUP);
                $totals['added']++;
            }
            foreach (array_keys(array_diff_key($current, $members)) as $userId) {
                $this->userRoleMapper->revokeRole((string)$userId, $role->getId(), UserRole::SOURCE_GROUP);
                $totals['removed']++;
            }
        }
        return $totals;
    }

    /**
     * A user was added to or removed from a group.
     */
    public function membershipChanged(string $groupId, string $userId): void {
        if ($this->isMappedGroup($groupId)) {
            $this->syncUser($userId);
        }
    }

    /**
     * A mapped group was deleted: drop it from the mapping and reconcile.
     */
    public function groupDeleted(string $groupId): void {
        if (!$this->isMappedGroup($groupId)) {
            return;
        }
        $mapping = $this->getMapping();
        foreach ($mapping as $role => $groupIds) {
            $mapping[$role] = array_values(array_diff($groupIds, [$groupId]));
        }
        $this->configuration->setConfigValue(self::CONFIG_KEY, json_encode($mapping, JSON_UNESCAPED_UNICODE));
        $this->syncAll();
    }

    /**
     * A user was deleted: remove their group-derived roles.
     */
    public function userDeleted(string $userId): void {
        $this->userRoleMapper->revokeAllForUserBySource($userId, UserRole::SOURCE_GROUP);
    }
}
