<?php

declare(strict_types=1);

namespace OCA\OpenCase\Listener;

use OCA\OpenCase\Db\UserInfoMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\PostLoginEvent;
use Psr\Log\LoggerInterface;

/**
 * Ensures every user who logs in has an opencase_userinfo row (used for the
 * user's organisations, employee search, template merge fields, ...).
 *
 * SAML logins create the row from Serviceplatformen before the login
 * completes (SamlController → UserSyncService), so this only matters for
 * other logins, e.g. Microsoft Entra ID via oidc_login. An existing row is
 * never changed.
 *
 * Failures are logged, never rethrown, so the login itself always succeeds.
 *
 * The listener is registered in Application.php.
 *
 * @implements IEventListener<PostLoginEvent>
 */
class UserInfoLoginListener implements IEventListener {

    public function __construct(
        private UserInfoMapper $userInfoMapper,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof PostLoginEvent) {
            return;
        }
        $user   = $event->getUser();
        $userId = $user->getUID();
        try {
            $this->userInfoMapper->ensureForUser($user);
        } catch (\Throwable $e) {
            $this->logger->error('OpenCase: failed to create user info for {user}: {error}', [
                'app'       => 'opencase',
                'user'      => $userId,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
