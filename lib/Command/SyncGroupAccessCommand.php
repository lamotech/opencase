<?php

declare(strict_types=1);

namespace OCA\OpenCase\Command;

use OCA\OpenCase\Db\AccessProfileMapper;
use OCA\OpenCase\Service\AccessProfile\GroupAccessSyncService;
use OCA\OpenCase\Service\OrganisationMembershipSyncService;
use OCA\OpenCase\Service\RoleGroupSyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OCC command: opencase:access:sync-groups
 *
 * Registers groups named with the access group prefix as access groups
 * (group mode only) and reconciles the grant_source='group' rows in oc_opencase_user_access with
 * current Nextcloud group membership, for all 'group' access profiles or for
 * one group, and (without --group) the group-derived roles in
 * oc_opencase_user_roles. Same as the hourly GroupAccessSyncJob, run on demand.
 *
 * Manual and privilege grants/roles are never touched.
 *
 * Usage:
 *   occ opencase:access:sync-groups
 *   occ opencase:access:sync-groups --group=<gid>
 */
class SyncGroupAccessCommand extends Command {

    public function __construct(
        private GroupAccessSyncService $groupAccessSyncService,
        private AccessProfileMapper $accessProfileMapper,
        private RoleGroupSyncService $roleGroupSyncService,
        private OrganisationMembershipSyncService $organisationMembershipSyncService,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('opencase:access:sync-groups')
            ->setDescription('Sync group access-profile grants and roles from groups with Nextcloud group membership.')
            ->addOption('group', 'g', InputOption::VALUE_REQUIRED, 'Only sync the access profile for this group id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $groupId = $input->getOption('group');

        if ($groupId !== null) {
            $profile = $this->accessProfileMapper->findByGroupId((string)$groupId);
            if ($profile === null) {
                $output->writeln("<error>No access profile for group '{$groupId}'</error>");
                return self::FAILURE;
            }
            $result = $this->groupAccessSyncService->syncProfile($profile);
            $output->writeln("Group '{$groupId}' (profile {$profile->getId()}): {$result['added']} added, {$result['removed']} removed.");
            return self::SUCCESS;
        }

        $result = $this->groupAccessSyncService->syncAll();
        $output->writeln("{$result['registered']} access groups registered.");
        $output->writeln("{$result['profiles']} group profiles: {$result['added']} added, {$result['removed']} removed.");
        $roles = $this->roleGroupSyncService->syncAll();
        $output->writeln("Roles from groups: {$roles['added']} added, {$roles['removed']} removed.");
        $orgs = $this->organisationMembershipSyncService->syncAll();
        $output->writeln("Organisation membership from groups: {$orgs['users']} users reconciled.");
        return self::SUCCESS;
    }
}
