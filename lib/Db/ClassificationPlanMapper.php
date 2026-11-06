<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Read mapper for the opencase_class_plan table (Emneplan). Writes go
 * through ClassificationPlanRepository.
 *
 * @extends QBMapper<ClassificationPlan>
 */
class ClassificationPlanMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_class_plan', ClassificationPlan::class);
    }

    public function findByUuid(string $uuid): ?ClassificationPlan {
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
     * @return ClassificationPlan[]
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
     * All items, active or not, ordered by code — for the Emneplan tab.
     *
     * @return ClassificationPlan[]
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('code', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * @param string[] $uuids
     * @return array<string, ClassificationPlan>  uuid => ClassificationPlan
     */
    public function getByUuids(array $uuids): array {
        $uuids = array_values(array_filter($uuids, fn($u) => $u !== null && $u !== ''));
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

        $map = [];
        foreach ($this->findEntities($qb) as $entity) {
            $map[$entity->getUuid()] = $entity;
        }
        return $map;
    }
}
