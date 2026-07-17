<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<UserRole>
 */
class UserRoleMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_user_roles', UserRole::class);
    }

    /**
     * Check whether a user has a specific role, identified by role name.
     * Performs a single JOIN query — safe for use on every request.
     */
    public function userHasRoleByName(string $userId, string $roleName): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('ur.id')
            ->from($this->getTableName(), 'ur')
            ->innerJoin('ur', 'opencase_roles', 'r', $qb->expr()->eq('ur.role_id', 'r.id'))
            ->where($qb->expr()->eq('ur.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('r.name', $qb->createNamedParameter($roleName)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return $row !== false;
    }

    /** @return UserRole[] */
    public function findByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        return $this->findEntities($qb);
    }

    public function assignRole(string $userId, int $roleId, ?string $assignedBy = null): UserRole {
        // Idempotent — skip if already assigned
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('role_id', $qb->createNamedParameter($roleId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $existing = $result->fetch();
        $result->closeCursor();

        if ($existing !== false) {
            $entity = new UserRole();
            $entity->setId((int)$existing['id']);
            return $entity;
        }

        $entity = new UserRole();
        $entity->setUserId($userId);
        $entity->setRoleId($roleId);
        $entity->setAssignedAt(new \DateTime());
        $entity->setAssignedBy($assignedBy);
        return $this->insert($entity);
    }

    public function revokeRole(string $userId, int $roleId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('role_id', $qb->createNamedParameter($roleId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
