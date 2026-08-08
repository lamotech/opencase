<?php
declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\AppFramework\Utility\ITimeFactory;

class OrganisationRepository {
	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $time,
	) {}

	public function find(string $uuid): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('opencase_org')
			->where($qb->expr()->eq('org_uuid', $qb->createNamedParameter($uuid)));
		$row = $qb->executeQuery()->fetch();
		return $row ?: null;
	}

	public function insert(string $uuid, string $name, ?string $parentUuid = null): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('opencase_org')
			->values([
				'org_uuid' => $qb->createNamedParameter($uuid),
				'org_name' => $qb->createNamedParameter($name),
				'org_parent_uuid' => $qb->createNamedParameter($parentUuid),
				'active' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
			])->executeStatement();
	}

	public function updateIfChanged(string $uuid, string $name, ?string $parentUuid = null): bool {
		$current = $this->find($uuid);
		if ($current === null) {
			return false;
		}
		$currentParentUuid = $current['org_parent_uuid'] ?? null;
		$changed = ($current['org_name'] !== $name) ||
			($currentParentUuid !== $parentUuid);

		if (!$changed) {
			return false;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update('opencase_org')
			->set('org_name', $qb->createNamedParameter($name))
			->set('org_parent_uuid', $qb->createNamedParameter($parentUuid))
			->where($qb->expr()->eq('org_uuid', $qb->createNamedParameter($uuid)))
			->executeStatement();

		return true;
	}

	public function touch(string $uuid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('opencase_org')
			->where($qb->expr()->eq('org_uuid', $qb->createNamedParameter($uuid)))
			->executeStatement();
	}

	public function updateOrganisation(string $uuid, string $name, string $parentUuid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('opencase_org')
			->set('org_name', $qb->createNamedParameter($name))
			->set('org_parent_uuid', $qb->createNamedParameter($parentUuid))
			->where($qb->expr()->eq('org_uuid', $qb->createNamedParameter($uuid)))
			->executeStatement();
	}

	public function updateContactDetails(string $uuid, ?string $email, ?string $phone, ?string $location): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('opencase_org')
			->set('email', $qb->createNamedParameter($email))
			->set('phone', $qb->createNamedParameter($phone))
			->set('location', $qb->createNamedParameter($location))
			->where($qb->expr()->eq('org_uuid', $qb->createNamedParameter($uuid)))
			->executeStatement();
	}

	public function updateGroupFolder(string $uuid, string $groupId, int $folderId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('opencase_org')
			->set('nc_group_id', $qb->createNamedParameter($groupId))
			->set('groupfolder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('org_uuid', $qb->createNamedParameter($uuid)))
			->executeStatement();
	}

	public function setActive(string $uuid, bool $active): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('opencase_org')
			->set('active', $qb->createNamedParameter($active, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('org_uuid', $qb->createNamedParameter($uuid)))
			->executeStatement();
	}

	public function setParentUuid(string $uuid, ?string $parentUuid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('opencase_org')
			->set('org_parent_uuid', $qb->createNamedParameter($parentUuid))
			->where($qb->expr()->eq('org_uuid', $qb->createNamedParameter($uuid)))
			->executeStatement();
	}

	public function findActiveNotIn(array $uuids): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('opencase_org')
			->where($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

		if (\count($uuids) > 0) {
			$qb->andWhere($qb->expr()->notIn('org_uuid', $qb->createNamedParameter($uuids, IQueryBuilder::PARAM_STR_ARRAY)));
		}

		return $qb->executeQuery()->fetchAll();
	}

	public function findAllActive(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('opencase_org')
			->where($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

		return $qb->executeQuery()->fetchAll();
	}
}
