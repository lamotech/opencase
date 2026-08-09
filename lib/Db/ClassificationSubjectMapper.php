<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for the opencase_class_subject table.
 *
 * The table uses uuid (VARCHAR 64) as its primary key rather than an
 * auto-increment integer. All custom CRUD methods use uuid directly;
 * the inherited QBMapper insert/update/delete are not used.
 *
 * @extends QBMapper<ClassificationSubject>
 */
class ClassificationSubjectMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_class_subject', ClassificationSubject::class);
    }

    /**
     * Find a classification subject by its UUID.
     */
    public function findByUuid(string $uuid): ?ClassificationSubject {
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
     * Find a classification subject by its KLE code.
     */
    public function findByCode(string $code): ?ClassificationSubject {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('code', $qb->createNamedParameter($code)));

        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Find a classification subject by code, creating it (with a new UUID) if absent.
     *
     * Auto-created subjects use the code as both code and title.
     */
    public function findOrCreateByCode(string $code): ClassificationSubject {
        $existing = $this->findByCode($code);
        if ($existing !== null) {
            return $existing;
        }

        $uuid = bin2hex(random_bytes(16));

        $qb = $this->db->getQueryBuilder();
        $qb->insert($this->getTableName())
            ->values([
                'uuid'        => $qb->createNamedParameter($uuid),
                'code'        => $qb->createNamedParameter($code),
                'title'       => $qb->createNamedParameter($code),
                'description' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
                'active'      => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
            ]);
        $qb->executeStatement();

        return $this->findByUuid($uuid);
    }

    /**
     * Return all active classification subjects ordered by code.
     *
     * @return ClassificationSubject[]
     */
    public function findAllActive(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->orderBy('code', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Return a UUID → ClassificationSubject map for the given UUIDs.
     *
     * @param string[] $uuids
     * @return array<string, ClassificationSubject>  uuid => ClassificationSubject
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
