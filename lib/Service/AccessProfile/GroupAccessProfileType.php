<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\AccessProfile;

use OCA\OpenCase\Db\AccessProfile;
use OCA\OpenCase\Db\AccessProfileMapper;
use OCP\IGroupManager;

/**
 * "Grupper" access profiles: one profile per Nextcloud group. Members of the
 * group get write access; see GroupAccessSyncService. Groups are shown
 * without the access group prefix; see AccessGroupNaming.
 */
class GroupAccessProfileType implements AccessProfileType {

    public function __construct(
        private AccessProfileMapper $accessProfileMapper,
        private IGroupManager $groupManager,
        private GroupAccessSyncService $groupAccessSyncService,
        private AccessGroupNaming $accessGroupNaming,
    ) {}

    public function key(): string {
        return AccessProfile::TYPE_GROUP;
    }

    public function requiredInputFields(): array {
        return ['group_id'];
    }

    /**
     * Input: group_id — the Nextcloud group id (oc_groups.gid) of a registered
     * access group, or of a group that qualifies as one (see
     * AccessGroupNaming), which is registered now. Any other group must be
     * registered by an administrator first (register()): this is reached from
     * the case forms, so it must not let users make e.g. 'admin' an access group.
     *
     * Members are granted when the profile is created; after that the
     * profile is kept in sync by GroupAccessListener and GroupAccessSyncJob,
     * so an existing profile is returned as-is.
     */
    public function resolveProfile(array $input): AccessProfile {
        $groupId = (string)($input['group_id'] ?? '');
        if ($groupId === '') {
            throw new \InvalidArgumentException('Group is required');
        }

        $existing = $this->accessProfileMapper->findByGroupId($groupId);
        if ($existing !== null) {
            if ($existing->isGroupDeleted()) {
                throw new \InvalidArgumentException("Not an access group: {$groupId}");
            }
            return $existing;
        }

        $group = $this->groupManager->get($groupId);
        if ($group === null) {
            throw new \InvalidArgumentException("Group not found: {$groupId}");
        }
        if (!$this->accessGroupNaming->isAccessGroup($group)) {
            throw new \InvalidArgumentException("Not an access group: {$groupId}");
        }

        return $this->register($groupId);
    }

    /**
     * Register any existing group as access group, whatever its name, and
     * grant the profile to its members. Idempotent. For administrators only:
     * the "Adgangsgrupper" settings and the inbox group.
     *
     * With $reconnect, a group with the id of a deleted group gets the deleted
     * group's profile and cases. That takes an administrator's decision, so
     * only the "Adgangsgrupper" settings pass it.
     *
     * @throws \InvalidArgumentException when the group does not exist
     */
    public function register(string $groupId, bool $reconnect = false): AccessProfile {
        $existing = $this->accessProfileMapper->findByGroupId($groupId);
        if ($existing !== null && !($reconnect && $existing->isGroupDeleted())) {
            return $existing;
        }

        if (!$this->groupManager->groupExists($groupId)) {
            throw new \InvalidArgumentException("Group not found: {$groupId}");
        }

        if ($existing !== null) {
            $existing->setGroupDeleted(0);
            $this->accessProfileMapper->update($existing);
            $this->grantUsers($existing);
            return $existing;
        }

        $profile = $this->accessProfileMapper->findOrCreateByGroup($groupId);
        $this->grantUsers($profile);

        return $profile;
    }

    /**
     * Group id and display name (without the access group prefix) for the
     * 'group' profiles among the given profile IDs ('fk' profiles are left
     * out). A deleted group keeps its id as display name.
     *
     * @param int[] $profileIds
     * @return array<int, array{group_id: string, group_name: string}>  profileId => group
     */
    public function describeGroups(array $profileIds): array {
        $groups = [];
        foreach ($this->accessProfileMapper->getGroupIdsByProfileIds(array_values(array_unique($profileIds))) as $profileId => $groupId) {
            $groups[$profileId] = [
                'group_id'   => $groupId,
                'group_name' => $this->accessGroupNaming->displayName($groupId),
            ];
        }
        return $groups;
    }

    /**
     * All 'group' access profiles with their group, sorted by group name —
     * the choices for a case's access group. can_write tells whether
     * $userId has write access to the profile.
     *
     * @param int[] $writeProfileIds the user's write profile IDs
     * @return list<array{access_profile_id: int, group_id: string, group_name: string, can_write: bool}>
     */
    public function listGroupProfiles(array $writeProfileIds): array {
        $writable = array_flip($writeProfileIds);
        $list     = [];
        foreach ($this->accessProfileMapper->findGroupProfiles() as $profile) {
            $groupId = (string)$profile->getGroupId();
            if ($profile->isGroupDeleted() || !$this->groupManager->groupExists($groupId)) {
                continue;
            }
            $list[] = [
                'access_profile_id' => $profile->getId(),
                'group_id'          => $groupId,
                'group_name'        => $this->accessGroupNaming->displayName($groupId),
                'can_write'         => isset($writable[$profile->getId()]),
            ];
        }
        usort($list, fn(array $a, array $b) => strcasecmp((string)$a['group_name'], (string)$b['group_name']));
        return $list;
    }

    /**
     * Grant the profile to the group's current members (and revoke it from
     * former members).
     */
    public function grantUsers(AccessProfile $profile): void {
        $this->groupAccessSyncService->syncProfile($profile);
    }
}
