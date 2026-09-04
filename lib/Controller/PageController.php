<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Service\Configuration;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IInitialStateService;
use OCP\IRequest;

/**
 * Serves the main single-page application.
 *
 * All frontend routes are handled by Vue Router — this controller
 * just renders the PHP template that loads the JS bundle.
 *
 * Routes are registered via appinfo/routes.php (not via #[FrontpageRoute] attributes),
 * because attribute routes are loaded before file routes and would match the catch-all
 * /{path} pattern before specific routes like /saml/login can be reached.
 */
class PageController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private IInitialStateService $initialState,
        private IAppManager $appManager,
        private Configuration $configuration,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Render the main page.
     *
     * Matches all frontend routes (/, /case/*, /document/*, /search, /recent)
     * so that Vue Router can handle client-side navigation.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        $this->pushInitialState();
        return new TemplateResponse('opencase', 'main');
    }

    /**
     * Catch-all for client-side routes.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function catchAll(): TemplateResponse {
        $this->pushInitialState();
        return new TemplateResponse('opencase', 'main');
    }

    private function pushInitialState(): void {
        $enterpriseVersionEnabled = $this->configuration->getConfigValue('enterprise_version', '0') === '1';

        $this->initialState->provideInitialState(
            'opencase',
            'enterprise_version_enabled',
            $enterpriseVersionEnabled,
        );
        $this->initialState->provideInitialState(
            'opencase',
            'ai_enabled',
            $enterpriseVersionEnabled && $this->appManager->isEnabledForUser('integration_openai'),
        );
        $this->initialState->provideInitialState(
            'opencase',
            'search_max_result_count',
            (int)$this->configuration->getConfigValue('search_max_result_count', '500'),
        );
        $this->initialState->provideInitialState(
            'opencase',
            'search_page_size',
            (int)$this->configuration->getConfigValue('search_page_size', '50'),
        );
        $this->initialState->provideInitialState(
            'opencase',
            'digital_post_enabled',
            $enterpriseVersionEnabled,
        );
        $this->initialState->provideInitialState(
            'opencase',
            'citizen_search_enabled',
            $enterpriseVersionEnabled,
        );
        $this->initialState->provideInitialState(
            'opencase',
            'company_search_enabled',
            $enterpriseVersionEnabled,
        );
        $this->initialState->provideInitialState(
            'opencase',
            'mail_app_enabled',
            $this->appManager->isEnabledForUser('mail'),
        );
    }
}
