<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for the opencase_sensitivity table.
 *
 * The table uses uuid (VARCHAR 64) as its primary key rather than an
 * auto-increment integer. All custom CRUD methods use uuid directly;
 * the inherited QBMapper insert/update/delete are not used.
 *
 * Note: the column "key" is a MySQL reserved word and is quoted as needed
 * via the NC QueryBuilder's identifier quoting.
 *
 * @extends QBMapper<Sensitivity>
 */
class SensitivityMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_sensitivity', Sensitivity::class);
    }

    /**
     * Find a sensitivity level by its UUID.
     */
    public function findByUuid(string $uuid): ?Sensitivity {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Find a sensitivity level by its key.
     */
    public function findByKey(string $key): ?Sensitivity {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('key', $qb->createNamedParameter($key)));

        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Find a sensitivity level by key, creating it (with a new UUID) if absent.
     *
     * Auto-created entries use the key as both key and title.
     */
    public function findOrCreateByKey(string $key): Sensitivity {
        $existing = $this->findByKey($key);
        if ($existing !== null) {
            return $existing;
        }

        $uuid = bin2hex(random_bytes(16));

        $qb = $this->db->getQueryBuilder();
        $qb->insert($this->getTableName())
            ->values([
                'uuid'        => $qb->createNamedParameter($uuid),
                'key'         => $qb->createNamedParameter($key),
                'title'       => $qb->createNamedParameter($key),
                'description' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
                'active'      => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
            ]);
        $qb->executeStatement();

        return $this->findByUuid($uuid);
    }

    /**
     * Return all active sensitivity levels ordered by title.
     *
     * @return Sensitivity[]
     */
    public function findAllActive(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->orderBy('title', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Return a UUID → Sensitivity map for the given UUIDs.
     *
     * @param string[] $uuids
     * @return array<string, Sensitivity>  uuid => Sensitivity
     */
    public function getByUuids(array $uuids): array {
        if (empty($uuids)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in(
                'uuid',
                $qb->createNamedParameter($uuids, IQueryBuilder::PARAM_STR_ARRAY)
            ));

        $entities = $this->findEntities($qb);
        $map = [];
        foreach ($entities as $entity) {
            $map[$entity->getUuid()] = $entity;
        }
        return $map;
    }
}
