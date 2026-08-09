<?php

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\OrganisationRepository;
use OCA\OpenCase\Db\OrgSyncLogRepository;
use OCA\OpenCase\Service\Serviceplatformen\OrganisationData;
use OCA\OpenCase\Service\Serviceplatformen\OrganisationClient;
use OCP\IGroupManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\GroupFolders\Folder\FolderManager;
use OCP\Constants;

class OrganisationSyncService {

	private bool $teamfoldersEnabled;

	public function __construct(
		private OrganisationClient $orgClient,
		private OrganisationRepository $repo,
		private ITimeFactory $time,
		private OrgSyncLogRepository $orgSyncLogRepository,
		private IGroupManager $groups,
		private FolderManager $folderManager,
		private Configuration $configuration,
	) {
		$this->teamfoldersEnabled = $this->configuration->getConfigValue('teamfolders_enable', '0') === '1';
	}

	private const ROLE_EMAIL    = '2b670843-ce42-411a-8fb5-311dfdd5caf0';
	private const ROLE_PHONE    = '8dcfa714-5ed3-4000-b551-2ba520e7d8ad';
	private const ROLE_LOCATION = '80b610c6-314b-485a-a014-a9a1d7070bc4';

	public function sync(): array {
		$orgs = $this->orgClient->fetchOrganisations();
		$seen = [];
		$created = 0; $updated = 0; $deactivated = 0;

		if(count($orgs) > 0) {
			foreach ($orgs as $org) {
				$uuid = $org->uuid;
				$name = $org->name;
				$parentUuid = $org->parentUuid;
				$seen[$uuid] = true;

				$existing = $this->repo->find($uuid);
				$wasUpdated = false;

				if ($existing === null) {
					$folderId = $this->createOrganisation($uuid, $name, $parentUuid);
					$created++;
				} else {
					$folderId = (int)$existing['groupfolder_id'];
					if ($existing['org_name'] !== $name || $existing['org_parent_uuid'] !== $parentUuid) {
						$folderId = $this->updateOrganisation($uuid, $name, $folderId, $parentUuid);
						$wasUpdated = true;
					}
					if (!(bool)$existing['active']) {
						$this->repo->setActive($uuid, true);
					}
					if($this->teamfoldersEnabled) {
						// Make sure group display name is in sync (in case it was changed outside of this service)
						$groupId = $existing['nc_group_id'];
						if (!empty($groupId)) {
							$group = $this->groups->get($groupId);
							$group?->setDisplayName($name);
						}

						if($folderId == 0) {
							$groupId = 'org_' . strtolower($uuid);
							if ($this->groups->get($groupId) === null) {
								$group = $this->groups->createGroup($groupId);
								$group->setDisplayName($name);
							}		
							$permissions = Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE | Constants::PERMISSION_CREATE | Constants::PERMISSION_SHARE | Constants::PERMISSION_DELETE;

							$folderId = $this->folderManager->createFolder($name);

							$this->folderManager->addApplicableGroup($folderId, $groupId);
							$this->folderManager->setGroupPermissions($folderId, $groupId, $permissions);

							$this->repo->updateGroupFolder($uuid, $groupId, $folderId);
						} 
					}							
				}

				// Fetch full org details and update contact fields
				try {
					$detail = $this->orgClient->fetchOrganisation($uuid);
					[$email, $phone, $location] = $this->extractContactDetails($detail->addresses);

					$row = $existing ?? $this->repo->find($uuid);
					if (
						($row['email'] ?? null) !== $email ||
						($row['phone'] ?? null) !== $phone ||
						($row['location'] ?? null) !== $location
					) {
						$this->repo->updateContactDetails($uuid, $email, $phone, $location);
						$wasUpdated = true;
					}
				} catch (\Throwable $e) {
					// Contact detail lookup failure should not abort the whole sync
				}

				if ($wasUpdated) {
					$updated++;
				}
			}

			// Deactivate missing orgs
			$missing = $this->repo->findActiveNotIn(array_keys($seen));
			foreach ($missing as $row) {
				$this->repo->setActive($row['org_uuid'], false);
				$deactivated++;
			}
		}

		$this->orgSyncLogRepository->insert($this->time->getDateTime(), count($orgs), $created, $updated, $deactivated);

		return [
			'fetched' => count($orgs),
			'created' => $created,
			'updated' => $updated,
			'deactivated' => $deactivated,
		];
	}

	private function extractContactDetails(array $addresses): array {
		$email = null; $phone = null; $location = null;
		foreach ($addresses as $address) {
			if ($address->roleUuid === self::ROLE_EMAIL) {
				$email = $address->text;
			} elseif ($address->roleUuid === self::ROLE_PHONE) {
				$phone = $address->text;
			} elseif ($address->roleUuid === self::ROLE_LOCATION) {
				$location = $address->text;
			}
		}
		return [$email, $phone, $location];
	}

	public function createOrganisation(string $uuid, string $name, string $parentUuid): int {
		if($this->teamfoldersEnabled) {
			$groupId = 'org_' . strtolower($uuid);
			if ($this->groups->get($groupId) === null) {
				$group = $this->groups->createGroup($groupId);
				$group->setDisplayName($name);
			}		
			$permissions = Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE | Constants::PERMISSION_CREATE | Constants::PERMISSION_SHARE | Constants::PERMISSION_DELETE;

			$folderId = $this->folderManager->createFolder($name);

			$this->folderManager->addApplicableGroup($folderId, $groupId);
			$this->folderManager->setGroupPermissions($folderId, $groupId, $permissions);
		} else {
			$folderId = 0;
			$groupId = 0;
		}

		$this->repo->insert($uuid, $name, $parentUuid);

		if ($this->teamfoldersEnabled) {
			$this->repo->updateGroupFolder($uuid, (string)$groupId, $folderId);
		}

		return $folderId;
	}	

	public function updateOrganisation(string $uuid, string $name, int $folderId, string $parentUuid): int {
		$this->repo->updateOrganisation($uuid, $name, $parentUuid);

		if($this->teamfoldersEnabled) {
			if($folderId != 0) {
				$this->folderManager->renameFolder($folderId, $name);
				$org = $this->repo->find($uuid);
				if ($org !== null) {
					$group = $this->groups->get($org['nc_group_id']);
					$group?->setDisplayName($name);
				}
			} else {
				$groupId = 'org_' . strtolower($uuid);
				if ($this->groups->get($groupId) === null) {
					$group = $this->groups->createGroup($groupId);
					$group->setDisplayName($name);
				}		
				$permissions = Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE | Constants::PERMISSION_CREATE | Constants::PERMISSION_SHARE | Constants::PERMISSION_DELETE;

				$folderId = $this->folderManager->createFolder($name);

				$this->folderManager->addApplicableGroup($folderId, $groupId);
				$this->folderManager->setGroupPermissions($folderId, $groupId, $permissions);

				$this->repo->updateGroupFolder($uuid, $groupId, $folderId);
			}
		}
		return $folderId;
	}	
}
