<?php

declare(strict_types=1);

namespace OCA\OpenCase\Listener;

use OCA\OpenCase\Service\RoleService;
use OCP\Collaboration\Resources\LoadAdditionalScriptsEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\Util;

/**
 * Injects the OpenCase Talk integration script whenever Talk dispatches
 * LoadAdditionalScriptsEvent (i.e. when rendering its main page).
 *
 * Only injects for users who have the OpenCase 'User' role, so the
 * message action is invisible to users who cannot access OpenCase.
 */
class TalkIntegrationListener implements IEventListener {

    public function __construct(
        private IUserSession $userSession,
        private RoleService $roleService,
    ) {}

    public function handle(Event $event): void {
        if (!($event instanceof LoadAdditionalScriptsEvent)) {
            return;
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return;
        }

        if (!$this->roleService->userHasRole($user->getUID(), 'User')) {
            return;
        }

        Util::addScript('opencase', 'opencase-talk');
    }
}
