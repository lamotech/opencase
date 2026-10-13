<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\CustomFieldDef;
use OCA\OpenCase\Db\CustomFieldScope;
use OCA\OpenCase\Service\CaseService;
use OCA\OpenCase\Service\CustomFieldService;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\RoleService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * REST API for admin-defined custom fields on cases.
 *
 * Definition management (index/create/update/destroy) requires the
 * OpenCase "Administrator" app role (same gate as ConfigController) —
 * this is a separate concept from Nextcloud instance-admin privileges,
 * since the "Tillægsfelter" management UI lives in the in-app
 * Configuration view rather than Nextcloud's server admin settings.
 * Reading/writing a case's own values only requires the case-level
 * access already enforced by CaseService.
 *
 * Routes:
 *   GET    /api/v1/admin/custom-fields          — List all field definitions
 *   POST   /api/v1/admin/custom-fields          — Create a field definition
 *   PUT    /api/v1/admin/custom-fields/{id}     — Update a field definition
 *   DELETE /api/v1/admin/custom-fields/{id}     — Delete a field definition
 *   GET    /api/v1/custom-fields                — List active field definitions (any user)
 *   GET    /api/v1/custom-fields/applicable     — Fields applicable given casetype/org/KLE (no case yet)
 *   GET    /api/v1/cases/{caseId}/custom-fields — Fields + values applicable to a case
 *   PUT    /api/v1/cases/{caseId}/custom-fields — Update a case's custom field values
 */
class CustomFieldController extends ApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private CustomFieldService $customFieldService,
        private CaseService $caseService,
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

    // ---------------------------------------------------------------
    // Definitions (admin)
    // ---------------------------------------------------------------

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/admin/custom-fields')]
    public function index(): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }
        $rows = $this->customFieldService->listDefsWithScopes();
        return new DataResponse(['fields' => array_map([$this, 'serializeDefWithScopes'], $rows)]);
    }

    /**
     * Request body (JSON):
     *   field_key   — Required. Machine name, lowercase letters/digits/underscore
     *   label       — Required. Display label
     *   field_type  — Required. text | dropdown | checkbox | date
     *   options     — Optional array of strings, used when field_type = dropdown
     *   required    — Optional bool (default false)
     *   sort_order  — Optional int (default 0)
     *   scopes      — Optional array of {scope_type, scope_value}
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/admin/custom-fields')]
    public function create(): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }
        $params = $this->getJsonBody();

        $required = ['field_key', 'label', 'field_type'];
        foreach ($required as $field) {
            if (empty($params[$field])) {
                return new DataResponse(['error' => "Field '{$field}' is required"], Http::STATUS_BAD_REQUEST);
            }
        }

        try {
            $def = $this->customFieldService->createDef(
                $params['field_key'],
                $params['label'],
                $params['field_type'],
                $params['options'] ?? [],
                (bool)($params['required'] ?? false),
                (int)($params['sort_order'] ?? 0),
                $params['scopes'] ?? [],
                $this->userId,
            );
            return new DataResponse(['field' => $this->serializeDef($def, [])], Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Request body (JSON): any of label, required, sort_order, active,
     * options, scopes.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/admin/custom-fields/{id}')]
    public function update(int $id): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }
        $params = $this->getJsonBody();

        try {
            $def = $this->customFieldService->updateDef(
                $id,
                $params['label'] ?? null,
                array_key_exists('required', $params) ? (bool)$params['required'] : null,
                array_key_exists('sort_order', $params) ? (int)$params['sort_order'] : null,
                array_key_exists('active', $params) ? (bool)$params['active'] : null,
                $params['options'] ?? null,
                $params['scopes'] ?? null,
                $this->userId,
            );
            $rows = $this->customFieldService->listDefsWithScopes();
            $scopes = [];
            foreach ($rows as $row) {
                if ($row['def']->getId() === $def->getId()) {
                    $scopes = $row['scopes'];
                    break;
                }
            }
            return new DataResponse(['field' => $this->serializeDef($def, $scopes)]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/admin/custom-fields/{id}')]
    public function destroy(int $id): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }
        try {
            $this->customFieldService->deleteDef($id);
            return new DataResponse([]);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * List active field definitions — open to any authenticated user
     * (unlike the /admin/custom-fields management endpoints above), since
     * this only backs read-only UI like the case search filter, which
     * needs to know what fields exist and their type regardless of the
     * OpenCase Administrator role.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/custom-fields')]
    public function listActive(): DataResponse {
        $defs = $this->customFieldService->listDefsWithScopes(true);
        return new DataResponse(['fields' => array_map(fn($row) => $this->serializeDef($row['def'], []), $defs)]);
    }

    // ---------------------------------------------------------------
    // Values (any user with case access)
    // ---------------------------------------------------------------

    /**
     * Fields applicable to a case that doesn't exist yet — used by the
     * "new case" dialog, which needs to show the right custom fields
     * before a case id exists to key values off of.
     *
     * Query parameters:
     *   casetype_id         — Optional. Case type id
     *   org_uuid            — Optional. Organisation uuid
     *   classification_code — Optional. KLE code
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/custom-fields/applicable')]
    public function applicable(): DataResponse {
        $casetypeIdRaw = $this->request->getParam('casetype_id');
        $casetypeId = ($casetypeIdRaw !== null && $casetypeIdRaw !== '') ? (int)$casetypeIdRaw : null;
        $orgUuid = $this->request->getParam('org_uuid') ?: null;
        $classificationCode = $this->request->getParam('classification_code') ?: null;

        $defs = $this->customFieldService->getApplicableFields($casetypeId, $orgUuid, $classificationCode);
        $fields = array_map(fn(CustomFieldDef $def) => [
            'id' => $def->getId(),
            'field_key' => $def->getFieldKey(),
            'label' => $def->getLabel(),
            'field_type' => $def->getFieldType(),
            'options' => $def->getOptionsArray(),
            'required' => $def->getRequired(),
        ], $defs);

        return new DataResponse(['fields' => $fields]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/cases/{caseId}/custom-fields')]
    public function forCase(int $caseId): DataResponse {
        try {
            $case = $this->caseService->get($caseId, $this->userId, false);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
        }

        $kleCode = $this->caseService->getClassificationCodeForCase($case);
        $defs = $this->customFieldService->getApplicableFields(
            $case->getCasetypeId(),
            $case->getOrgUuid(),
            $kleCode,
        );
        $values = $this->customFieldService->getValuesForCase($caseId);

        $fields = array_map(function (CustomFieldDef $def) use ($values) {
            return [
                'id' => $def->getId(),
                'field_key' => $def->getFieldKey(),
                'label' => $def->getLabel(),
                'field_type' => $def->getFieldType(),
                'options' => $def->getOptionsArray(),
                'required' => $def->getRequired(),
                'value' => $values[$def->getFieldKey()] ?? null,
            ];
        }, $defs);

        return new DataResponse(['fields' => $fields]);
    }

    /**
     * Request body (JSON): { "values": { "<field_key>": <value>, ... } }
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/cases/{caseId}/custom-fields')]
    public function updateForCase(int $caseId): DataResponse {
        $params = $this->getJsonBody();

        if (!$this->caseService->canWriteCase($caseId, $this->userId)) {
            return new DataResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $values = $this->customFieldService->setValuesForCase($caseId, $params['values'] ?? [], $this->userId);
            return new DataResponse(['values' => $values]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    // ---------------------------------------------------------------

    /**
     * @param CustomFieldScope[] $scopes
     */
    private function serializeDef(CustomFieldDef $def, array $scopes): array {
        return [
            'id' => $def->getId(),
            'field_key' => $def->getFieldKey(),
            'label' => $def->getLabel(),
            'field_type' => $def->getFieldType(),
            'options' => $def->getOptionsArray(),
            'required' => $def->getRequired(),
            'sort_order' => $def->getSortOrder(),
            'active' => $def->getActive(),
            'scopes' => array_map(fn(CustomFieldScope $s) => [
                'scope_type' => $s->getScopeType(),
                'scope_value' => $s->getScopeValue(),
            ], $scopes),
        ];
    }

    /**
     * @param array{def: CustomFieldDef, scopes: CustomFieldScope[]} $row
     */
    private function serializeDefWithScopes(array $row): array {
        return $this->serializeDef($row['def'], $row['scopes']);
    }

    private function getJsonBody(): array {
        return $this->request->getParams();
    }
}
