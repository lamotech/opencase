<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for opencase_case_users — direct per-user access grants on cases.
 *
 * @extends QBMapper<CaseUserEntity>
 */
class CaseUserMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_case_users', CaseUserEntity::class);
    }

    // ---------------------------------------------------------------
    // Lookups
    // ---------------------------------------------------------------

    /**
     * All grants for a case (including expired).
     *
     * @return CaseUserEntity[]
     */
    public function findByCaseId(int $caseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
            ->orderBy('granted_at', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * All active (non-expired) grants for a case.
     *
     * @return CaseUserEntity[]
     */
    public function findActiveByCaseId(int $caseId): array {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $qb  = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('expires_at'),
                $qb->expr()->gt('expires_at', $qb->createNamedParameter($now)),
            ))
            ->orderBy('granted_at', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find a specific grant for a (case, user) pair. Returns null if not found.
     */
    public function findByCaseAndUser(int $caseId, string $userId): ?CaseUserEntity {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    // ---------------------------------------------------------------
    // Write operations
    // ---------------------------------------------------------------

    /**
     * Create or update a direct access grant.
     *
     * If a grant already exists for this (case, user) pair it is updated in-place.
     * Use this for both initial grants and changes to access level / expiry.
     */
    public function upsertGrant(
        int $caseId,
        string $userId,
        bool $canWrite,
        string $grantedBy,
        ?\DateTime $expiresAt = null,
    ): CaseUserEntity {
        $existing = $this->findByCaseAndUser($caseId, $userId);

        if ($existing !== null) {
            $existing->setCanWrite($canWrite);
            $existing->setGrantedBy($grantedBy);
            $existing->setGrantedAt(new \DateTime());
            $existing->setExpiresAt($expiresAt);
            return $this->update($existing);
        }

        $entity = new CaseUserEntity();
        $entity->setCaseId($caseId);
        $entity->setUserId($userId);
        $entity->setCanWrite($canWrite);
        $entity->setGrantedBy($grantedBy);
        $entity->setGrantedAt(new \DateTime());
        $entity->setExpiresAt($expiresAt);

        return $this->insert($entity);
    }

    /**
     * Remove a direct access grant. No-op if the grant does not exist.
     */
    public function revokeGrant(int $caseId, string $userId): void {
        $existing = $this->findByCaseAndUser($caseId, $userId);
        if ($existing !== null) {
            $this->delete($existing);
        }
    }

    /**
     * All grants for a given user, joined with basic case info.
     *
     * Returns plain associative arrays with keys:
     *   case_id, case_number, title, can_write, granted_at, expires_at
     */
    public function findGrantedCasesByUserId(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select([
                'cu.case_id',
                'cu.can_write',
                'cu.granted_at',
                'cu.expires_at',
                'c.case_number',
                'c.title',
            ])
            ->from($this->getTableName(), 'cu')
            ->innerJoin('cu', 'opencase_cases', 'c',
                $qb->expr()->eq('c.id', 'cu.case_id'))
            ->where($qb->expr()->eq('cu.user_id', $qb->createNamedParameter($userId)))
            ->orderBy('cu.granted_at', 'DESC');

        $result = $qb->executeQuery();
        $rows   = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    /**
     * Delete all expired grants. Returns the number of rows removed.
     * Intended for a background cleanup job.
     */
    public function deleteExpiredGrants(): int {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $qb  = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->isNotNull('expires_at'))
            ->andWhere($qb->expr()->lte('expires_at', $qb->createNamedParameter($now)));

        return $qb->executeStatement();
    }
}
