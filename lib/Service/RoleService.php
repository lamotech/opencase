<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\RoleMapper;
use OCA\OpenCase\Db\UserRoleMapper;

class RoleService {

    /**
     * Maps a privilege group's type (as stored in opencase_user_priv_groups /
     * carried in a SAML privilege token) to the coarse app role it implies.
     * Mirrors SamlController::syncOpenCaseRoles()'s derivation for SAML logins.
     */
    private const PRIVILEGE_TYPE_TO_ROLE = [
        'opencaseuser'                  => 'User',
        'opencasereaduser'               => 'User',
        'opencaseadministrator'          => 'Administrator',
        'opencasetemplateadministrator'  => 'Template Designer',
    ];

    public function __construct(
        private UserRoleMapper $userRoleMapper,
        private RoleMapper $roleMapper,
    ) {}

    public function userHasRole(string $userId, string $roleName): bool {
        return $this->userRoleMapper->userHasRoleByName($userId, $roleName);
    }

    /**
     * Derive the coarse 'User' / 'Administrator' / 'Template Designer' roles
     * from a user's privilege groups and assign/revoke them to match.
     *
     * Local Nextcloud users only get their privilege groups (opencaseuser,
     * opencaseadministrator, ...) through the admin settings "Rettigheder"
     * tab, which never goes through the SAML login flow that normally keeps
     * these roles in sync — so this must be called explicitly whenever a
     * local user's privilege groups change, or they'll never gain app access
     * (nav icon, RoleMiddleware) despite having privileges assigned.
     *
     * @param array<int, array{privilege_type: string}> $groups
     */
    public function syncRolesFromPrivilegeGroups(string $userId, array $groups): void {
        $desiredRoles = [];
        foreach ($groups as $group) {
            $roleName = self::PRIVILEGE_TYPE_TO_ROLE[$group['privilege_type']] ?? null;
            if ($roleName !== null) {
                $desiredRoles[$roleName] = true;
            }
        }

        foreach (['User', 'Administrator', 'Template Designer'] as $roleName) {
            if (!empty($desiredRoles[$roleName])) {
                $this->assignRoleByName($userId, $roleName);
            } else {
                $this->revokeRoleByName($userId, $roleName);
            }
        }
    }

    /**
     * Assign a role to a user by role name. No-op if already assigned.
     */
    public function assignRoleByName(string $userId, string $roleName, ?string $assignedBy = null): void {
        $role = $this->roleMapper->findByName($roleName);
        if ($role === null) {
            throw new \RuntimeException("Role '$roleName' does not exist.");
        }
        $this->userRoleMapper->assignRole($userId, $role->getId(), $assignedBy);
    }

    /**
     * Revoke a role from a user by role name. No-op if not assigned.
     */
    public function revokeRoleByName(string $userId, string $roleName): void {
        $role = $this->roleMapper->findByName($roleName);
        if ($role === null) {
            return;
        }
        $this->userRoleMapper->revokeRole($userId, $role->getId());
    }
}
