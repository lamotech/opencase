<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\AccessMaskRuleMapper;
use OCA\OpenCase\Service\AccessMaskingService;
use OCA\OpenCase\Service\RoleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Masking rules for access requests (Konfiguration → Maskeringsregler).
 * OpenCase Administrator role required.
 */
class AccessMaskRuleController extends OCSController {

    public function __construct(
        string $appName,
        IRequest $request,
        private ?string $userId,
        private AccessMaskRuleMapper $ruleMapper,
        private AccessMaskingService $maskingService,
        private RoleService $roleService,
    ) {
        parent::__construct($appName, $request);
    }

    private function checkAdministrator(): ?DataResponse {
        if ($this->userId === null || !$this->roleService->userHasRole($this->userId, 'Administrator')) {
            return new DataResponse(
                ['error' => 'OpenCase Administrator role required.'],
                Http::STATUS_FORBIDDEN,
            );
        }
        return null;
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/access-mask-rules')]
    public function index(): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }
        return new DataResponse([
            'rules'              => $this->maskingService->listRules(),
            'service_configured' => $this->maskingService->isConfigured(),
        ]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/access-mask-rules')]
    public function create(): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }
        try {
            $rule = $this->maskingService->saveRule(null, $this->ruleData(), $this->userId);
            return new DataResponse(['rule' => $this->maskingService->serializeRule($rule)]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/access-mask-rules/{id}')]
    public function update(int $id): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }
        try {
            $rule = $this->maskingService->saveRule($this->ruleMapper->findById($id), $this->ruleData(), $this->userId);
            return new DataResponse(['rule' => $this->maskingService->serializeRule($rule)]);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/access-mask-rules/{id}')]
    public function destroy(int $id): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }
        try {
            $this->maskingService->deleteRule($this->ruleMapper->findById($id));
            return new DataResponse([]);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Run an unsaved rule on sample text.
     * Body: { "text": "...", "kind": "...", "pattern": "...", "validator": ... }
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/access-mask-rules/test')]
    public function test(): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }
        try {
            $rule    = $this->maskingService->draftRule($this->ruleData());
            $matches = $this->maskingService->detect((string) $this->request->getParam('text', ''), [$rule]);
            return new DataResponse(['matches' => array_column($matches, 'text')]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
        }
    }

    private function ruleData(): array {
        $data = [];
        foreach (['label', 'kind', 'pattern', 'validator', 'legal_reference', 'enabled', 'sort_order'] as $key) {
            $value = $this->request->getParam($key);
            if ($value !== null || array_key_exists($key, $this->request->getParams())) {
                $data[$key] = $value;
            }
        }
        return $data;
    }
}
