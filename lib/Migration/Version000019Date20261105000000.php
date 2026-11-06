<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCA\OpenCase\Db\AccessProfile;
use OCA\OpenCase\Db\RoleMapper;
use OCA\OpenCase\Service\AccessProfile\AccessProfileTypeResolver;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\RoleGroupSyncService;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * - opencase_access_profiles.group_deleted — 1 when the profile's group was
 *   deleted while cases used it. A new group with the same id must not get
 *   those cases (see GroupAccessSyncService::groupDeleted).
 *
 * A saved role_groups mapping used to fall back to the group named
 * "OpenCase Role - <role>" for roles it doesn't mention. That fallback is
 * gone, so with Grupper such groups that exist now are written to the mapping:
 * users keep their roles. Without a saved mapping nothing changes, and with
 * FK Adgang there is nothing to keep.
 */
class Version000019Date20261105000000 extends SimpleMigrationStep {

    public function __construct(
        private Configuration $configuration,
        private IGroupManager $groupManager,
        private RoleMapper $roleMapper,
    ) {}

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $profiles = $schema->getTable('opencase_access_profiles');
        if (!$profiles->hasColumn('group_deleted')) {
            $profiles->addColumn('group_deleted', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        if ($this->configuration->getConfigValue(AccessProfileTypeResolver::CONFIG_KEY, AccessProfile::TYPE_FK) !== AccessProfile::TYPE_GROUP) {
            return;
        }

        $value = $this->configuration->getConfigValue(RoleGroupSyncService::CONFIG_KEY);
        if ($value === null) {
            return;
        }
        $mapping = json_decode($value, true);
        $mapping = is_array($mapping) ? $mapping : [];
        $changed = false;
        foreach ($this->roleMapper->findAll() as $role) {
            $groupId = RoleGroupSyncService::DEFAULT_GROUP_PREFIX . $role->getName();
            if (!array_key_exists($role->getName(), $mapping) && $this->groupManager->groupExists($groupId)) {
                $mapping[$role->getName()] = [$groupId];
                $changed = true;
            }
        }
        if ($changed) {
            $this->configuration->setConfigValue(RoleGroupSyncService::CONFIG_KEY, json_encode($mapping, JSON_UNESCAPED_UNICODE));
        }
    }
}
