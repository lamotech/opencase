<?php

declare(strict_types=1);

namespace OCA\OpenCase\Listener;

use OCA\OpenCase\Service\AccessProfile\GroupAccessSyncService;
use OCA\OpenCase\Service\OrganisationMembershipSyncService;
use OCA\OpenCase\Service\RoleGroupSyncService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\GroupCreatedEvent;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\User\Events\PostLoginEvent;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Keeps OpenCase in step with Nextcloud group membership:
 *   - 'group' access-profile grants (GroupAccessSyncService), for groups
 *     that have an access profile, and automatic registration of new groups
 *     named with the access group prefix;
 *   - roles from groups mapped to a role (RoleGroupSyncService);
 *   - organisation membership from organisations' member groups
 *     (OrganisationMembershipSyncService).
 *
 * At login all three are reconciled for the user, so groups provisioned by the
 * identity provider (e.g. Microsoft Entra ID via oidc_login, which updates
 * the user's groups before completing the login) take effect right away —
 * also when a membership event was missed.
 *
 * Failures are logged, never rethrown: group and user management must keep
 * working even if OpenCase cannot update its grants. GroupAccessSyncJob
 * reconciles anything missed here.
 *
 * The listener is registered in Application.php.
 *
 * @implements IEventListener<PostLoginEvent|GroupCreatedEvent|UserAddedEvent|UserRemovedEvent|GroupDeletedEvent|UserDeletedEvent>
 */
class GroupAccessListener implements IEventListener {

    public function __construct(
        private GroupAccessSyncService $groupAccessSyncService,
        private RoleGroupSyncService $roleGroupSyncService,
        private OrganisationMembershipSyncService $organisationMembershipSyncService,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        // PostLoginEvent::isTokenLogin() can't be used to skip API logins:
        // oidc_login completes an interactive login with a session token too.
        $this->updateAccessGrants($event);
        $this->updateRoles($event);
        $this->updateOrganisations($event);
    }

    private function updateAccessGrants(Event $event): void {
        try {
            if ($event instanceof PostLoginEvent) {
                $this->groupAccessSyncService->syncUser($event->getUser());
            } elseif ($event instanceof GroupCreatedEvent) {
                $this->groupAccessSyncService->groupCreated($event->getGroup()->getGID());
            } elseif ($event instanceof UserAddedEvent) {
                $this->groupAccessSyncService->userAddedToGroup($event->getGroup()->getGID(), $event->getUser()->getUID());
            } elseif ($event instanceof UserRemovedEvent) {
                $this->groupAccessSyncService->userRemovedFromGroup($event->getGroup()->getGID(), $event->getUser()->getUID());
            } elseif ($event instanceof GroupDeletedEvent) {
                $this->groupAccessSyncService->groupDeleted($event->getGroup()->getGID());
            } elseif ($event instanceof UserDeletedEvent) {
                $this->groupAccessSyncService->userDeleted($event->getUser()->getUID());
            }
        } catch (\Throwable $e) {
            $this->logger->error('OpenCase: failed to update group access grants: {error}', [
                'app'       => 'opencase',
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    private function updateRoles(Event $event): void {
        try {
            if ($event instanceof PostLoginEvent) {
                $this->roleGroupSyncService->syncUser($event->getUser()->getUID());
            } elseif ($event instanceof UserAddedEvent || $event instanceof UserRemovedEvent) {
                $this->roleGroupSyncService->membershipChanged($event->getGroup()->getGID(), $event->getUser()->getUID());
            } elseif ($event instanceof GroupDeletedEvent) {
                $this->roleGroupSyncService->groupDeleted($event->getGroup()->getGID());
            } elseif ($event instanceof UserDeletedEvent) {
                $this->roleGroupSyncService->userDeleted($event->getUser()->getUID());
            }
        } catch (\Throwable $e) {
            $this->logger->error('OpenCase: failed to update roles from groups: {error}', [
                'app'       => 'opencase',
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    private function updateOrganisations(Event $event): void {
        try {
            if ($event instanceof PostLoginEvent) {
                $this->organisationMembershipSyncService->syncUser($event->getUser()->getUID());
            } elseif ($event instanceof UserAddedEvent || $event instanceof UserRemovedEvent) {
                $this->organisationMembershipSyncService->membershipChanged($event->getGroup()->getGID(), $event->getUser());
            } elseif ($event instanceof GroupDeletedEvent) {
                // The mapping is kept (a group provisioned at login can come
                // back); its former members lose the membership.
                if ($this->organisationMembershipSyncService->isMemberGroup($event->getGroup()->getGID())) {
                    $this->organisationMembershipSyncService->syncAll();
                }
            } elseif ($event instanceof UserDeletedEvent) {
                $this->organisationMembershipSyncService->userDeleted($event->getUser()->getUID());
            }
        } catch (\Throwable $e) {
            $this->logger->error('OpenCase: failed to update organisation membership from groups: {error}', [
                'app'       => 'opencase',
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
