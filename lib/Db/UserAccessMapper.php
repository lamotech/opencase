<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for the opencase_user_access table.
 *
 * Manages the many-to-many relationship between users and access profiles.
 * Each row grants a user either 'read' or 'write' access to a profile.
 *
 * At scale: a user might have 50-500 access profiles.
 * The total table size will be (num_users × avg_profiles_per_user),
 * e.g. 5,000 users × 200 profiles = 1M rows — well within indexed query range.
 *
 * @extends QBMapper<UserAccess>
 */
class UserAccessMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_user_access', UserAccess::class);
    }

    /**
     * Get all access profile IDs assigned to a user.
     *
     * @return int[]
     */
    public function getProfileIdsForUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('access_profile_id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['access_profile_id'];
        }
        $result->closeCursor();

        return $ids;
    }

    /**
     * Get the access level (read/write) a user has for a specific profile.
     *
     * @return string|null 'read', 'write', or null if no access
     */
    public function getAccessLevel(string $userId, int $accessProfileId): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('access_level')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('access_profile_id', $qb->createNamedParameter($accessProfileId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return $row ? $row['access_level'] : null;
    }

    /**
     * Get all access profile IDs where the user has write access.
     *
     * @return int[]
     */
    public function getWriteProfileIdsForUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('access_profile_id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('access_level', $qb->createNamedParameter('write')));

        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['access_profile_id'];
        }
        $result->closeCursor();

        return $ids;
    }

    /**
     * Grant a user access to a profile. Updates the level if already assigned.
     */
    public function grantAccess(string $userId, int $accessProfileId, string $level, ?string $grantedBy = null): UserAccess {
        // Check for existing assignment
        $existing = $this->findByUserAndProfile($userId, $accessProfileId);
        if ($existing !== null) {
            $existing->setAccessLevel($level);
            $existing->setGrantedAt(new \DateTime());
            $existing->setGrantedBy($grantedBy);
            return $this->update($existing);
        }

        $entity = new UserAccess();
        $entity->setUserId($userId);
        $entity->setAccessProfileId($accessProfileId);
        $entity->setAccessLevel($level);
        $entity->setGrantedAt(new \DateTime());
        $entity->setGrantedBy($grantedBy);

        return $this->insert($entity);
    }

    /**
     * Revoke a user's access to a profile.
     */
    public function revokeAccess(string $userId, int $accessProfileId): void {
        $existing = $this->findByUserAndProfile($userId, $accessProfileId);
        if ($existing !== null) {
            $this->delete($existing);
        }
    }

    /**
     * Revoke all access for a user (e.g. when user leaves the organisation).
     */
    public function revokeAllForUser(string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();
    }

    /**
     * Get all users who have access to a specific profile.
     *
     * @return UserAccess[]
     */
    public function getUsersForProfile(int $accessProfileId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('access_profile_id', $qb->createNamedParameter($accessProfileId, IQueryBuilder::PARAM_INT)));

        return $this->findEntities($qb);
    }

    /**
     * Bulk grant: assign a user to multiple profiles at once.
     * Efficient for onboarding where a user gets a role-based set of profiles.
     *
     * @param int[] $profileIds
     */
    public function bulkGrant(string $userId, array $profileIds, string $level, ?string $grantedBy = null): void {
        if (empty($profileIds)) {
            return;
        }

        // Get existing assignments to avoid duplicates
        $existingIds = $this->getProfileIdsForUser($userId);
        $existingSet = array_flip($existingIds);

        foreach ($profileIds as $profileId) {
            if (isset($existingSet[$profileId])) {
                // Update existing
                $this->grantAccess($userId, $profileId, $level, $grantedBy);
            } else {
                // Insert new
                $entity = new UserAccess();
                $entity->setUserId($userId);
                $entity->setAccessProfileId($profileId);
                $entity->setAccessLevel($level);
                $entity->setGrantedAt(new \DateTime());
                $entity->setGrantedBy($grantedBy);
                $this->insert($entity);
            }
        }
    }

    /**
     * Find a specific user-profile assignment.
     */
    private function findByUserAndProfile(string $userId, int $accessProfileId): ?UserAccess {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('access_profile_id', $qb->createNamedParameter($accessProfileId, IQueryBuilder::PARAM_INT)));

        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return null;
        }
    }
}
