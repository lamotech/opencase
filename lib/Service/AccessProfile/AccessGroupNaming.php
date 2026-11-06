<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\AccessProfile;

use OCA\OpenCase\Db\OrganisationRepository;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\RoleGroupSyncService;
use OCP\IGroup;
use OCP\IGroupManager;

/**
 * Which Nextcloud groups are access groups, and how they are named in OpenCase.
 *
 * A group is an access group when its id or display name starts with the
 * 'access_group_prefix' config (default "OpenCase - ", case-insensitive),
 * e.g. a group "OpenCase - Byggesager" provisioned from Microsoft Entra ID.
 * Such groups get a 'group' access profile automatically (see
 * GroupAccessSyncService::registerGroup) and are shown without the prefix
 * ("Byggesager"). An empty prefix makes every group an access group.
 *
 * Never access groups: 'admin', organisation groups (org_<uuid>), groups
 * mapped to an OpenCase role (RoleGroupSyncService) and organisations'
 * member groups (OrganisationMembershipSyncService).
 */
class AccessGroupNaming {

    public const PREFIX_CONFIG_KEY = 'access_group_prefix';
    public const DEFAULT_PREFIX    = 'OpenCase - ';

    private const EXCLUDED_GROUPS  = ['admin'];
    private const ORG_GROUP_PREFIX = 'org_';

    public function __construct(
        private Configuration $configuration,
        private IGroupManager $groupManager,
        private RoleGroupSyncService $roleGroupSyncService,
        private OrganisationRepository $organisationRepository,
    ) {}

    public function prefix(): string {
        return $this->configuration->getConfigValue(self::PREFIX_CONFIG_KEY, self::DEFAULT_PREFIX) ?? self::DEFAULT_PREFIX;
    }

    /**
     * Whether the group should be registered as access group automatically.
     */
    public function isAccessGroup(IGroup $group): bool {
        $groupId = $group->getGID();
        if (in_array($groupId, self::EXCLUDED_GROUPS, true)
            || str_starts_with($groupId, self::ORG_GROUP_PREFIX)
            || $this->roleGroupSyncService->isMappedGroup($groupId)
            || $this->organisationRepository->isMemberGroup($groupId)) {
            return false;
        }

        $prefix = $this->prefix();
        return $prefix === ''
            || $this->startsWithPrefix($groupId, $prefix)
            || $this->startsWithPrefix((string)$group->getDisplayName(), $prefix);
    }

    /**
     * The group's display name without the access group prefix. A deleted
     * group keeps its id as name.
     */
    public function displayName(string $groupId): string {
        $name   = $this->groupManager->get($groupId)?->getDisplayName() ?? $groupId;
        $prefix = $this->prefix();
        if ($prefix !== '' && $this->startsWithPrefix($name, $prefix)) {
            $stripped = trim(mb_substr($name, mb_strlen($prefix)));
            if ($stripped !== '') {
                return $stripped;
            }
        }
        return $name;
    }

    private function startsWithPrefix(string $name, string $prefix): bool {
        return mb_stripos($name, $prefix) === 0;
    }
}
