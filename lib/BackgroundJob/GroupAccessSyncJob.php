<?php

declare(strict_types=1);

namespace OCA\OpenCase\BackgroundJob;

use OCA\OpenCase\Service\AccessProfile\GroupAccessSyncService;
use OCA\OpenCase\Service\OrganisationMembershipSyncService;
use OCA\OpenCase\Service\RoleGroupSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Hourly registration of new access groups (named with the access group
 * prefix) and reconcile of 'group' access-profile grants and group-derived
 * roles with Nextcloud group membership. GroupAccessListener handles membership
 * events as they happen;
 * this job catches changes that fire no event, e.g. LDAP/AD group backends
 * where membership changes in the directory.
 */
class GroupAccessSyncJob extends TimedJob {

    public function __construct(
        ITimeFactory $time,
        private GroupAccessSyncService $groupAccessSyncService,
        private RoleGroupSyncService $roleGroupSyncService,
        private OrganisationMembershipSyncService $organisationMembershipSyncService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(60 * 60);
        $this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        try {
            $result = $this->groupAccessSyncService->syncAll();
            if ($result['registered'] > 0 || $result['added'] > 0 || $result['removed'] > 0) {
                $this->logger->info('GroupAccessSyncJob - {registered} access groups registered, {profiles} group profiles reconciled: {added} grants added, {removed} removed', [
                    'app' => 'opencase',
                    ...$result,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('GroupAccessSyncJob failed: {error}', [
                'app'       => 'opencase',
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);
        }

        try {
            $roles = $this->roleGroupSyncService->syncAll();
            if ($roles['added'] > 0 || $roles['removed'] > 0) {
                $this->logger->info('GroupAccessSyncJob - roles from groups reconciled: {added} added, {removed} removed', [
                    'app' => 'opencase',
                    ...$roles,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('GroupAccessSyncJob roles failed: {error}', [
                'app'       => 'opencase',
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);
        }

        try {
            $this->organisationMembershipSyncService->syncAll();
        } catch (\Throwable $e) {
            $this->logger->error('GroupAccessSyncJob organisations failed: {error}', [
                'app'       => 'opencase',
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
