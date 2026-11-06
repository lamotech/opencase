<?php
declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Row-level access to opencase_class_plan (Emneplan). Same interface as
 * ClassificationSubjectRepository so ClassificationCsvImportService can sync
 * either table, plus what the manual editing on the Emneplan tab needs.
 */
class ClassificationPlanRepository {
	public function __construct(
		private IDBConnection $db,
	) {}

	public function find(string $uuid): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('opencase_class_plan')
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));
		$row = $qb->executeQuery()->fetch();
		return $row ?: null;
	}

	public function findByCode(string $code): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('opencase_class_plan')
			->where($qb->expr()->eq('code', $qb->createNamedParameter($code)));
		$row = $qb->executeQuery()->fetch();
		return $row ?: null;
	}

	public function insert(string $uuid, string $code, string $title, string $description, ?int $disposalcodeId, ?int $deletionDeadlineId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('opencase_class_plan')
			->values([
				'uuid'        => $qb->createNamedParameter($uuid),
				'code'        => $qb->createNamedParameter($code),
				'title'       => $qb->createNamedParameter($title),
				'description' => $qb->createNamedParameter($description),
				'disposalcode_id'      => $qb->createNamedParameter($disposalcodeId, $disposalcodeId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
				'deletion_deadline_id' => $qb->createNamedParameter($deletionDeadlineId, $deletionDeadlineId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
				'active'      => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			])->executeStatement();
	}

	public function update(string $uuid, string $code, string $title, string $description, ?int $disposalcodeId, ?int $deletionDeadlineId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('opencase_class_plan')
			->set('code',        $qb->createNamedParameter($code))
			->set('title',       $qb->createNamedParameter($title))
			->set('description', $qb->createNamedParameter($description))
			->set('disposalcode_id',      $qb->createNamedParameter($disposalcodeId, $disposalcodeId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT))
			->set('deletion_deadline_id', $qb->createNamedParameter($deletionDeadlineId, $deletionDeadlineId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)))
			->executeStatement();
	}

	public function setActive(string $uuid, bool $active): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('opencase_class_plan')
			->set('active', $qb->createNamedParameter($active ? 1 : 0, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)))
			->executeStatement();
	}

	public function findActiveNotIn(array $uuids): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('uuid')
			->from('opencase_class_plan')
			->where($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));

		if (count($uuids) > 0) {
			$qb->andWhere($qb->expr()->notIn('uuid', $qb->createNamedParameter($uuids, IQueryBuilder::PARAM_STR_ARRAY)));
		}

		return $qb->executeQuery()->fetchAll();
	}

	public function delete(string $uuid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('opencase_class_plan')
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)))
			->executeStatement();
	}

	/**
	 * Number of cases classified with the item (deleted cases included, since
	 * they still reference it).
	 */
	public function countCases(string $uuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('opencase_cases')
			->where($qb->expr()->eq('class_plan_uuid', $qb->createNamedParameter($uuid)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * Number of scopes pointing at the item, per kind of owner.
	 * (SagDokumentIndeks rules can't have Emneplan scopes.)
	 *
	 * @return array{phrases: int, templates: int, processes: int, custom_fields: int}
	 */
	public function countScopes(string $uuid): array {
		$counts = [];
		foreach ([
			'phrases' => 'opencase_phrases_scope',
			'templates' => 'opencase_template_scope',
			'processes' => 'opencase_process_scope',
			'custom_fields' => 'opencase_customfield_scope',
		] as $kind => $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*', 'cnt'))
				->from($table)
				->where($qb->expr()->eq('scope_type', $qb->createNamedParameter('class_plan')))
				->andWhere($qb->expr()->eq('scope_value', $qb->createNamedParameter($uuid)));
			$counts[$kind] = (int)$qb->executeQuery()->fetchOne();
		}
		return $counts;
	}

	public function countSeparationSheets(string $uuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('opencase_separation_sheets')
			->where($qb->expr()->eq('class_plan_uuid', $qb->createNamedParameter($uuid)));
		return (int)$qb->executeQuery()->fetchOne();
	}
}
