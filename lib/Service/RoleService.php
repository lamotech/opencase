<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\RoleMapper;
use OCA\OpenCase\Db\UserRoleMapper;

class RoleService {

    public function __construct(
        private UserRoleMapper $userRoleMapper,
        private RoleMapper $roleMapper,
    ) {}

    public function userHasRole(string $userId, string $roleName): bool {
        return $this->userRoleMapper->userHasRoleByName($userId, $roleName);
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
