<?php

declare(strict_types=1);

namespace OCA\OpenCase\Middleware;

use OCA\OpenCase\Controller\SamlController;
use OCA\OpenCase\Exception\NotPermittedByRoleException;
use OCA\OpenCase\Service\RoleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\OCSController;
use OCP\IUserSession;

class RoleMiddleware extends Middleware {

    public function __construct(
        private IUserSession $userSession,
        private RoleService $roleService,
    ) {}

    /**
     * Block the request before it reaches the controller if the logged-in user
     * does not have the OpenCase 'User' role.
     *
     * Unauthenticated requests are left alone — Nextcloud's own auth middleware
     * handles those and will redirect to the login page.
     */
    public function beforeController(Controller $controller, string $methodName): void {
        // SAML endpoints are infrastructure-level (IdP fetches metadata, posts
        // assertions) and must not be gated by application roles.
        if ($controller instanceof SamlController) {
            return;
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return;
        }

        if (!$this->roleService->userHasRole($user->getUID(), 'User')) {
            throw new NotPermittedByRoleException();
        }
    }

    /**
     * Convert a NotPermittedByRoleException into an appropriate HTTP response.
     *
     * OCS / API controllers → 403 JSON
     * Page controllers      → 403 rendered template
     */
    public function afterException(Controller $controller, string $methodName, \Exception $exception): Response {
        if (!($exception instanceof NotPermittedByRoleException)) {
            throw $exception;
        }

        if ($controller instanceof OCSController) {
            return new JSONResponse(
                ['error' => $exception->getMessage()],
                Http::STATUS_FORBIDDEN,
            );
        }

        return new TemplateResponse('core', '403', [], 'guest');
    }
}
