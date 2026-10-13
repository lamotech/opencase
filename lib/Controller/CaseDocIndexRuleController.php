<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\CaseDocIndexDef;
use OCA\OpenCase\Db\CaseDocIndexScope;
use OCA\OpenCase\Service\CaseDocIndexRuleService;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\RoleService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * REST API for the rules deciding which cases are transferred to
 * SagDokumentIndeks (SAPA). Requires the OpenCase "Administrator" app role,
 * like the custom field definitions (CustomFieldController).
 *
 * Routes:
 *   GET    /api/v1/admin/sapa-rules       — List all rules with scopes
 *   POST   /api/v1/admin/sapa-rules       — Create a rule
 *   PUT    /api/v1/admin/sapa-rules/{id}  — Update a rule
 *   DELETE /api/v1/admin/sapa-rules/{id}  — Delete a rule
 *   GET    /api/v1/admin/sapa-rules/{id}/counts — Matching / sent case counts
 */
class CaseDocIndexRuleController extends ApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private CaseDocIndexRuleService $ruleService,
        private RoleService $roleService,
        private ?string $userId,
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
    #[ApiRoute(verb: 'GET', url: '/api/v1/admin/sapa-rules')]
    public function index(): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }
        $rows = $this->ruleService->listRulesWithScopes();
        return new DataResponse(['rules' => array_map(fn(array $row) => $this->serialize($row['def'], $row['scopes']), $rows)]);
    }

    /**
     * Request body (JSON):
     *   name        — Required
     *   sort_order  — Optional int (default 0)
     *   scopes      — Optional array of {scope_type, scope_value}
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/admin/sapa-rules')]
    public function create(): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }
        $params = $this->request->getParams();

        try {
            $def = $this->ruleService->createRule(
                (string)($params['name'] ?? ''),
                (int)($params['sort_order'] ?? 0),
                $params['scopes'] ?? [],
                $this->userId,
            );
            $row = $this->ruleService->getRuleWithScopes($def->getId());
            return new DataResponse(['rule' => $this->serialize($row['def'], $row['scopes'])], Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Request body (JSON): any of name, sort_order, active, scopes.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/admin/sapa-rules/{id}')]
    public function update(int $id): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }
        $params = $this->request->getParams();

        try {
            $this->ruleService->updateRule(
                $id,
                array_key_exists('name', $params) ? (string)$params['name'] : null,
                array_key_exists('sort_order', $params) ? (int)$params['sort_order'] : null,
                array_key_exists('active', $params) ? (bool)$params['active'] : null,
                $params['scopes'] ?? null,
                $this->userId,
            );
            $row = $this->ruleService->getRuleWithScopes($id);
            return new DataResponse(['rule' => $this->serialize($row['def'], $row['scopes'])]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/admin/sapa-rules/{id}')]
    public function destroy(int $id): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }
        try {
            $this->ruleService->deleteRule($id);
            return new DataResponse([]);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Number of cases matching the rule, and how many of those have been
     * sent to SagDokumentIndeks. Separate from index() since it can be slow
     * on large installations, so the UI loads it per rule afterwards.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/admin/sapa-rules/{id}/counts')]
    public function counts(int $id): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }
        try {
            return new DataResponse($this->ruleService->countCasesForRule($id));
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * @param CaseDocIndexScope[] $scopes
     */
    private function serialize(CaseDocIndexDef $def, array $scopes): array {
        return [
            'id' => $def->getId(),
            'name' => $def->getName(),
            'sort_order' => $def->getSortOrder(),
            'active' => $def->getActive(),
            'scopes' => array_map(fn(CaseDocIndexScope $s) => [
                'scope_type' => $s->getScopeType(),
                'scope_value' => $s->getScopeValue(),
            ], $scopes),
        ];
    }
}
