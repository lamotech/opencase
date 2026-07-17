<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for the opencase_org table.
 *
 * The table uses org_uuid (VARCHAR 64) as its primary key rather than an
 * auto-increment integer. All custom CRUD methods use org_uuid directly;
 * the inherited QBMapper insert/update/delete are not used.
 *
 * @extends QBMapper<Organisation>
 */
class OrganisationMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_org', Organisation::class);
    }

    /**
     * Find an organisation by its UUID.
     */
    public function findByUuid(string $uuid): ?Organisation {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('org_uuid', $qb->createNamedParameter($uuid)));

        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Find an organisation by its exact name.
     */
    public function findByName(string $name): ?Organisation {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('org_name', $qb->createNamedParameter($name)));

        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Find an organisation by name, creating it (with a new UUID) if absent.
     *
     * Auto-created organisations are active root organisations (no parent).
     */
    public function findOrCreateByName(string $name): Organisation {
        $existing = $this->findByName($name);
        if ($existing !== null) {
            return $existing;
        }

        $uuid = bin2hex(random_bytes(16));

        $qb = $this->db->getQueryBuilder();
        $qb->insert($this->getTableName())
            ->values([
                'org_uuid'        => $qb->createNamedParameter($uuid),
                'org_name'        => $qb->createNamedParameter($name),
                'org_parent_uuid' => $qb->createNamedParameter(''),
                'active'          => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
            ]);
        $qb->executeStatement();

        return $this->findByUuid($uuid);
    }

    /**
     * Find the top-level (root) organisation — the first active org with no parent.
     */
    public function findTopLevel(): ?Organisation {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('org_parent_uuid', $qb->createNamedParameter('')))
            ->orderBy('org_name', 'ASC')
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Search active organisations by name (case-insensitive substring match).
     * Returns up to $limit results ordered by name.
     *
     * @return Organisation[]
     */
    public function searchByName(string $query, int $limit = 20): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->like(
                $qb->createFunction('LOWER(org_name)'),
                $qb->createNamedParameter('%' . strtolower($query) . '%')
            ))
            ->orderBy('org_name', 'ASC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    /**
     * Return all active organisations ordered by name.
     *
     * @return Organisation[]
     */
    public function findAllActive(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->orderBy('org_name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Return a UUID → name map for the given UUIDs.
     *
     * @param string[] $uuids
     * @return array<string, string>  uuid => org_name
     */
    public function getNamesByUuids(array $uuids): array {
        if (empty($uuids)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('org_uuid', 'org_name')
            ->from($this->getTableName())
            ->where($qb->expr()->in(
                'org_uuid',
                $qb->createNamedParameter($uuids, IQueryBuilder::PARAM_STR_ARRAY)
            ));

        $result = $qb->executeQuery();
        $map = [];
        while ($row = $result->fetch()) {
            $map[$row['org_uuid']] = $row['org_name'];
        }
        $result->closeCursor();

        return $map;
    }
}
