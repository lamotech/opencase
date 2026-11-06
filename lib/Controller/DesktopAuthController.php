<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OC\Authentication\Token\IProvider;
use OC\Core\Exception\LoginFlowV2NotFoundException;
use OC\Core\Service\LoginFlowV2Service;
use OCA\OpenCase\Service\Configuration;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Authentication\Exceptions\InvalidTokenException;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;

/**
 * Login for Office desktop clients, wrapping Nextcloud's Login Flow v2.
 *
 * The core /login/v2 endpoints don't send CORS headers, so clients that run
 * web code (e.g. Euro-Office desktop plugins) can't call them directly.
 *
 * The user logs in in the browser through whatever the instance is set up
 * with — Nextcloud user + password, OpenID Connect (Entra ID via user_oidc)
 * or SAML (KOMBIT context handler, via OpenCase's own SAML login, which also
 * takes over the Login Flow v2 picker page). The client only ever sees the
 * resulting app password, so it doesn't need to know which one was used.
 *
 * Privileges and group membership only refresh at an interactive login, and
 * Nextcloud's app passwords never expire while in use. So every app password
 * handed out here gets a fixed expiry (opencase_config.desktop_token_lifetime_hours)
 * that forces the user back through that login.
 *
 * Routes:
 *   POST /desktop/v1/api/login      — Start a login flow
 *   POST /desktop/v1/api/login/poll — Poll for the app password
 *   POST /desktop/v1/api/logout     — Revoke the app password (Basic auth)
 */
class DesktopAuthController extends ApiController {

    private const DEFAULT_TOKEN_LIFETIME_HOURS = 720;

    public function __construct(
        string $appName,
        IRequest $request,
        private LoginFlowV2Service $loginFlowV2Service,
        private IProvider $tokenProvider,
        private ISession $session,
        private IURLGenerator $urlGenerator,
        private Configuration $configuration,
        private ITimeFactory $time,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Start a login flow. The client opens `login` in the browser and polls
     * `poll.endpoint` with `poll.token`.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[CORS]
    public function login(): JSONResponse {
        $tokens = $this->loginFlowV2Service->createTokens($this->request->getHeader('user-agent'));

        return new JSONResponse([
            'poll' => [
                'token'    => $tokens->getPollToken(),
                'endpoint' => $this->urlGenerator->linkToRouteAbsolute('opencase.desktop_auth.poll'),
            ],
            'login' => $this->urlGenerator->linkToRouteAbsolute(
                'core.ClientFlowLoginV2.landing',
                ['token' => $tokens->getLoginToken()],
            ),
        ]);
    }

    /**
     * Return the credentials once the user has granted access; 404 until
     * then (and for unknown/expired tokens). Credentials are handed out once.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[CORS]
    public function poll(string $token = ''): JSONResponse {
        if ($token === '') {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        try {
            $credentials = $this->loginFlowV2Service->poll($token);
        } catch (LoginFlowV2NotFoundException) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        // Not caught on purpose: credentials without an expiry must not go out
        $this->limitLifetime($credentials->getAppPassword());

        return new JSONResponse($credentials->jsonSerialize());
    }

    /**
     * Give the app password a fixed expiry. An expiry it already has is kept:
     * Login Flow v2 accepts an existing app password in place of a login, and
     * that must not renew it.
     */
    private function limitLifetime(string $appPassword): void {
        $token = $this->tokenProvider->getToken($appPassword);
        if ((int)$token->getExpires() !== 0) {
            return;
        }

        $hours = (int)$this->configuration->getConfigValue('desktop_token_lifetime_hours');
        if ($hours <= 0) {
            $hours = self::DEFAULT_TOKEN_LIFETIME_HOURS;
        }

        $token->setExpires($this->time->getTime() + $hours * 3600);
        $this->tokenProvider->updateToken($token);
    }

    /**
     * Revoke the app password the request is authenticated with.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function logout(): JSONResponse {
        // Set by Nextcloud when a request logs in with an app password
        $appPassword = $this->session->get('app_password');
        if (!is_string($appPassword) || $appPassword === '') {
            return new JSONResponse(['error' => 'Not authenticated with an app password'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $token = $this->tokenProvider->getToken($appPassword);
        } catch (InvalidTokenException) {
            return new JSONResponse(['error' => 'Invalid app password'], Http::STATUS_BAD_REQUEST);
        }

        $this->tokenProvider->invalidateTokenById($token->getUID(), $token->getId());
        return new JSONResponse([]);
    }
}
