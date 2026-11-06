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

    /**
     * Distinct user IDs that have at least one OpenCase role assigned.
     *
     * @return list<string>
     */
    public function findAllDistinctUserIds(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('user_id')
            ->from($this->getTableName())
            ->orderBy('user_id', 'ASC');

        $result = $qb->executeQuery();
        $userIds = [];
        while ($row = $result->fetch()) {
            $userIds[] = (string)$row['user_id'];
        }
        $result->closeCursor();

        return $userIds;
    }

    /** @return UserRole[] */
    public function findByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        return $this->findEntities($qb);
    }

    /**
     * Assign a role from the given source. Idempotent per source: a user can
     * hold the same role from several sources (see UserRole::SOURCE_*).
     */
    public function assignRole(string $userId, int $roleId, ?string $assignedBy = null, string $grantSource = UserRole::SOURCE_PRIVILEGE): UserRole {
        // Idempotent — skip if already assigned from this source
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('role_id', $qb->createNamedParameter($roleId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('grant_source', $qb->createNamedParameter($grantSource)))
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
        $entity->setGrantSource($grantSource);
        return $this->insert($entity);
    }

    /**
     * Revoke a role from the given source only; the same role held from
     * another source is kept.
     */
    public function revokeRole(string $userId, int $roleId, string $grantSource = UserRole::SOURCE_PRIVILEGE): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('role_id', $qb->createNamedParameter($roleId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('grant_source', $qb->createNamedParameter($grantSource)));
        $qb->executeStatement();
    }

    /**
     * User IDs holding the role from the given source.
     *
     * @return list<string>
     */
    public function findUserIdsByRoleAndSource(int $roleId, string $grantSource): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('role_id', $qb->createNamedParameter($roleId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('grant_source', $qb->createNamedParameter($grantSource)));

        $result  = $qb->executeQuery();
        $userIds = [];
        while ($row = $result->fetch()) {
            $userIds[] = (string)$row['user_id'];
        }
        $result->closeCursor();

        return $userIds;
    }

    /**
     * Revoke every role a user holds from the given source.
     */
    public function revokeAllForUserBySource(string $userId, string $grantSource): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('grant_source', $qb->createNamedParameter($grantSource)));
        $qb->executeStatement();
    }
}
