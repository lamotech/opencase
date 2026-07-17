<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

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
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Render the Login Flow v2 helper page.
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function loginHelper(): TemplateResponse {
        return new TemplateResponse('opencase', 'login-helper', [
            'loginV2InitUrl' => $this->urlGenerator->linkToRouteAbsolute('core.clientflowloginv2.init'),
        ], 'guest');
    }
}
