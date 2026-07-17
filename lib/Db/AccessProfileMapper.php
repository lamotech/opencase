<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for the opencase_access_profiles table.
 *
 * Access profiles are the unique combinations of
 * (org_uuid, class_subject_uuid, sensitivity) that define
 * who can see what.  Both UUIDs are FKs into their respective lookup tables.
 *
 * Methods used during write operations (findOrCreate, findByTuple) accept
 * UUIDs directly — callers should resolve human-readable values first via
 * OrganisationMapper and ClassificationSubjectMapper.
 *
 * @extends QBMapper<AccessProfile>
 */
class AccessProfileMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_access_profiles', AccessProfile::class);
    }

    /**
     * Find an access profile by ID, returning null if not found.
     */
    public function findById(int $id): ?AccessProfile {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));

        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Find an access profile by its unique tuple.
     *
     * @param string $orgUuid          UUID from opencase_org
     * @param string $classSubjectUuid UUID from opencase_class_subject
     * @param string $sensitivityUuid  UUID from opencase_sensitivity
     */
    public function findByTuple(string $orgUuid, string $classSubjectUuid, string $sensitivityUuid): ?AccessProfile {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('org_uuid', $qb->createNamedParameter($orgUuid)))
            ->andWhere($qb->expr()->eq('class_subject_uuid', $qb->createNamedParameter($classSubjectUuid)))
            ->andWhere($qb->expr()->eq('sensitivity_uuid', $qb->createNamedParameter($sensitivityUuid)));

        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Find or create an access profile for the given tuple.
     * Used during case creation to ensure the profile exists.
     *
     * @param string $orgUuid          UUID from opencase_org (resolve the name first)
     * @param string $classSubjectUuid UUID from opencase_class_subject (resolve the code first)
     * @param string $sensitivityUuid  UUID from opencase_sensitivity (resolve the key first)
     */
    public function findOrCreate(string $orgUuid, string $classSubjectUuid, string $sensitivityUuid): AccessProfile {
        $existing = $this->findByTuple($orgUuid, $classSubjectUuid, $sensitivityUuid);
        if ($existing !== null) {
            return $existing;
        }

        $profile = new AccessProfile();
        $profile->setOrgUuid($orgUuid);
        $profile->setClassSubjectUuid($classSubjectUuid);
        $profile->setSensitivityUuid($sensitivityUuid);
        $profile->setCreatedAt(new \DateTime());

        return $this->insert($profile);
    }

    /**
     * Get distinct organisation names that appear in the given profile IDs.
     * Joins with opencase_org to return human-readable names.
     *
     * @param int[] $profileIds
     * @return string[]
     */
    public function getDistinctOrganisations(array $profileIds): array {
        if (empty($profileIds)) {
            return [];
        }

        $qb    = $this->db->getQueryBuilder();
        $nameFn = $qb->createFunction('o.org_name');
        $qb->select('o.org_name')
            ->from($this->getTableName(), 'ap')
            ->innerJoin('ap', 'opencase_org', 'o', $qb->expr()->eq('ap.org_uuid', 'o.org_uuid'))
            ->where($qb->expr()->in(
                'ap.id',
                $qb->createNamedParameter($profileIds, IQueryBuilder::PARAM_INT_ARRAY)
            ))
            ->groupBy($nameFn)
            ->orderBy($nameFn, 'ASC');

        $result = $qb->executeQuery();
        $orgs   = [];
        while ($row = $result->fetch()) {
            $orgs[] = $row['org_name'];
        }
        $result->closeCursor();

        return $orgs;
    }

    /**
     * Get all profiles matching a given organisation name (for admin filtering).
     *
     * @return AccessProfile[]
     */
    public function findByOrganisation(string $orgName, ?int $limit = null, ?int $offset = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('ap.id', 'ap.org_uuid', 'ap.class_subject_uuid', 'ap.sensitivity_uuid', 'ap.created_at')
            ->from($this->getTableName(), 'ap')
            ->innerJoin('ap', 'opencase_org', 'o', $qb->expr()->eq('ap.org_uuid', 'o.org_uuid'))
            ->where($qb->expr()->eq('o.org_name', $qb->createNamedParameter($orgName)))
            ->orderBy($qb->createFunction('ap.class_subject_uuid'), 'ASC')
            ->addOrderBy($qb->createFunction('ap.sensitivity_uuid'), 'ASC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }
        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities($qb);
    }

    /**
     * Return a profile-ID → class_subject_uuid map for the given profile IDs.
     *
     * @param int[] $profileIds
     * @return array<int, string>  profileId => classSubjectUuid
     */
    public function getClassSubjectUuidsByProfileIds(array $profileIds): array {
        if (empty($profileIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'class_subject_uuid')
            ->from($this->getTableName())
            ->where($qb->expr()->in(
                'id',
                $qb->createNamedParameter($profileIds, IQueryBuilder::PARAM_INT_ARRAY)
            ));

        $result = $qb->executeQuery();
        $map    = [];
        while ($row = $result->fetch()) {
            $map[(int)$row['id']] = $row['class_subject_uuid'];
        }
        $result->closeCursor();

        return $map;
    }

    /**
     * Return a profile-ID → sensitivity_uuid map for the given profile IDs.
     *
     * @param int[] $profileIds
     * @return array<int, string>  profileId => sensitivityUuid
     */
    public function getSensitivityUuidsByProfileIds(array $profileIds): array {
        if (empty($profileIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'sensitivity_uuid')
            ->from($this->getTableName())
            ->where($qb->expr()->in(
                'id',
                $qb->createNamedParameter($profileIds, IQueryBuilder::PARAM_INT_ARRAY)
            ));

        $result = $qb->executeQuery();
        $map    = [];
        while ($row = $result->fetch()) {
            $map[(int)$row['id']] = $row['sensitivity_uuid'];
        }
        $result->closeCursor();

        return $map;
    }

    /**
     * Return the IDs of all access profiles with a given classification subject UUID.
     *
     * @return int[]
     */
    public function getProfileIdsByClassSubjectUuid(string $classSubjectUuid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('class_subject_uuid', $qb->createNamedParameter($classSubjectUuid)));

        $result = $qb->executeQuery();
        $ids    = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['id'];
        }
        $result->closeCursor();
        return $ids;
    }

    /**
     * Return the IDs of all access profiles with a given sensitivity UUID.
     *
     * @return int[]
     */
    public function getProfileIdsBySensitivityUuid(string $sensitivityUuid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('sensitivity_uuid', $qb->createNamedParameter($sensitivityUuid)));

        $result = $qb->executeQuery();
        $ids    = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['id'];
        }
        $result->closeCursor();
        return $ids;
    }
}
