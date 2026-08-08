<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Service\AddinOriginService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Helper page for 3rd-party clients (e.g. the Outlook task pane add-in)
 * that need to obtain Nextcloud Login Flow v2 credentials.
 *
 * This page is served from the Nextcloud origin so it can call the
 * core /login/v2 and /login/v2/poll endpoints same-origin (these do
 * not support CORS). It opens the Nextcloud login/grant flow in a
 * popup, polls for the resulting app password, and reports the
 * credentials back to the opener/parent window.
 */
class AuthController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private IURLGenerator $urlGenerator,
        private AddinOriginService $addinOriginService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Render the Login Flow v2 helper page.
     *
     * SECURITY: returnRelayUrl decides where this page sends the app password
     * it obtains, so it is validated here — against the add-in origins an
     * admin has configured — and only ever reaches the template already
     * approved. The page must not read it back out of location.search itself:
     * that would let anyone who can get a user to click
     * ?returnRelayUrl=https://attacker.example/ collect working Nextcloud
     * credentials from a page on the genuine Nextcloud origin.
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function loginHelper(): TemplateResponse {
        $requestedRelayUrl = $this->request->getParam('returnRelayUrl');
        $requestedRelayUrl = is_string($requestedRelayUrl) ? $requestedRelayUrl : null;

        $relayUrl = $this->addinOriginService->resolveRelayUrl($requestedRelayUrl);

        $relayRejected = $requestedRelayUrl !== null && $requestedRelayUrl !== '' && $relayUrl === null;
        if ($relayRejected) {
            $this->logger->warning(
                'OpenCase login helper: rejected returnRelayUrl outside the allowed add-in origins',
                [
                    'app'              => 'opencase',
                    'requested'        => $requestedRelayUrl,
                    'allowed_origins'  => $this->addinOriginService->getAllowedRelayOrigins(),
                    'ip'               => $this->request->getRemoteAddress(),
                ],
            );
        }

        return new TemplateResponse('opencase', 'login-helper', [
            'loginV2InitUrl' => $this->urlGenerator->linkToRouteAbsolute('core.clientflowloginv2.init'),
            'relayUrl'       => $relayUrl,
            'relayRejected'  => $relayRejected,
            'ownOrigin'      => $this->ownOrigin(),
        ], 'guest');
    }

    /**
     * This server's own origin, used as the postMessage target for the
     * same-origin popup fallback. Never '*' — the message carries an app
     * password.
     */
    private function ownOrigin(): string {
        $parts = parse_url($this->urlGenerator->getAbsoluteURL('/'));
        if (!isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }
}
