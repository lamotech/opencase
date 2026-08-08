<?php
declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class ClassificationFacetRepository {
	public function __construct(
		private IDBConnection $db,
	) {}

	public function find(string $uuid): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('opencase_class_facet')
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));
		$row = $qb->executeQuery()->fetch();
		return $row ?: null;
	}

	public function insert(string $uuid, string $code, string $title, string $description): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('opencase_class_facet')
			->values([
				'uuid'        => $qb->createNamedParameter($uuid),
				'code'        => $qb->createNamedParameter($code),
				'title'       => $qb->createNamedParameter($title),
				'description' => $qb->createNamedParameter($description),
				'active'      => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			])->executeStatement();
	}

	public function update(string $uuid, string $code, string $title, string $description): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('opencase_class_facet')
			->set('code',        $qb->createNamedParameter($code))
			->set('title',       $qb->createNamedParameter($title))
			->set('description', $qb->createNamedParameter($description))
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)))
			->executeStatement();
	}

	public function setActive(string $uuid, bool $active): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('opencase_class_facet')
			->set('active', $qb->createNamedParameter($active ? 1 : 0, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)))
			->executeStatement();
	}

	public function findByCode(string $code): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('opencase_class_facet')
			->where($qb->expr()->eq('code', $qb->createNamedParameter($code)));
		$row = $qb->executeQuery()->fetch();
		return $row ?: null;
	}

	public function findAllActive(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('opencase_class_facet')
			->where($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->orderBy('code', 'ASC');
		return $qb->executeQuery()->fetchAll();
	}

	public function findActiveNotIn(array $uuids): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('uuid')
			->from('opencase_class_facet')
			->where($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));

		if (count($uuids) > 0) {
			$qb->andWhere($qb->expr()->notIn('uuid', $qb->createNamedParameter($uuids, IQueryBuilder::PARAM_STR_ARRAY)));
		}

		return $qb->executeQuery()->fetchAll();
	}
}
