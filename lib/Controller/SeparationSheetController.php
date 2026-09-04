<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\ClassificationFacetRepository;
use OCA\OpenCase\Db\ClassificationSubjectMapper;
use OCA\OpenCase\Db\InsightLevelMapper;
use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Db\SensitivityMapper;
use OCA\OpenCase\Db\SeparationSheet;
use OCA\OpenCase\Service\RoleService;
use OCA\OpenCase\Service\SeparationSheetService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\L10N\IFactory;

/**
 * Separation sheet API accessible to OpenCase Administrators.
 *
 * Routes:
 *   GET    /api/v1/separation-sheets      — list all separation sheets
 *   POST   /api/v1/separation-sheets      — create a separation sheet
 *   PUT    /api/v1/separation-sheets/{id} — update an editable separation sheet
 *   DELETE /api/v1/separation-sheets/{id} — delete a separation sheet
 */
class SeparationSheetController extends OCSController {

    public function __construct(
        string $appName,
        IRequest $request,
        private ?string $userId,
        private SeparationSheetService $separationSheetService,
        private RoleService $roleService,
        private OrganisationMapper $organisationMapper,
        private ClassificationSubjectMapper $classSubjectMapper,
        private SensitivityMapper $sensitivityMapper,
        private ClassificationFacetRepository $classFacetRepository,
        private InsightLevelMapper $insightLevelMapper,
        private IFactory $l10nFactory,
        private IUserManager $userManager,
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
    #[ApiRoute(verb: 'GET', url: '/api/v1/separation-sheets')]
    public function index(): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }

        $entities = $this->separationSheetService->list();
        $labels   = $this->resolveLabels($entities);
        $sheets   = array_map(fn($s) => $this->serialize($s, $labels), $entities);

        return new DataResponse(['separation_sheets' => $sheets]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/separation-sheets')]
    public function create(): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }

        $params = $this->request->getParams();
        $type   = (string)($params['type'] ?? '');

        try {
            $sheet = $this->separationSheetService->create($type, $params);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        return new DataResponse(['separation_sheet' => $this->serialize($sheet)], Http::STATUS_CREATED);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/separation-sheets/{id}')]
    public function update(int $id): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }

        $sheet = $this->separationSheetService->findById($id);
        if ($sheet === null) {
            return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $sheet = $this->separationSheetService->update($sheet, $this->request->getParams());
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        return new DataResponse(['separation_sheet' => $this->serialize($sheet)]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/separation-sheets/{id}')]
    public function destroy(int $id): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }

        $sheet = $this->separationSheetService->findById($id);
        if ($sheet === null) {
            return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        $this->separationSheetService->delete($sheet);

        return new DataResponse(null, Http::STATUS_NO_CONTENT);
    }

    /**
     * Look up the display labels behind the uuids/ids a sheet stores, so the
     * printed sheet can show the values a human filed it under.
     *
     * @param SeparationSheet[] $sheets
     * @return array<string, array<array-key, mixed>>
     */
    private function resolveLabels(array $sheets): array {
        $idsOf = fn(callable $get) => array_values(array_unique(array_filter(array_map($get, $sheets))));

        $facets = [];
        foreach ($idsOf(fn(SeparationSheet $s) => $s->getClassificationFacetUuid()) as $uuid) {
            $facets[$uuid] = $this->classFacetRepository->find($uuid);
        }

        return [
            'orgs'          => $this->organisationMapper->getNamesByUuids($idsOf(fn(SeparationSheet $s) => $s->getOrgUuid())),
            'subjects'      => $this->classSubjectMapper->getByUuids($idsOf(fn(SeparationSheet $s) => $s->getClassSubjectUuid())),
            'sensitivities' => $this->sensitivityMapper->getByUuids($idsOf(fn(SeparationSheet $s) => $s->getSensitivityUuid())),
            'facets'        => $facets,
            'insightLevels' => $this->insightLevelMapper->getNamesByIds(
                $idsOf(fn(SeparationSheet $s) => $s->getInsightLevelId()),
                $this->getUserLanguage(),
            ),
        ];
    }

    private function getUserLanguage(): string {
        if ($this->userId === null) {
            return 'en';
        }
        $user = $this->userManager->get($this->userId);
        return $user ? $this->l10nFactory->getUserLanguage($user) : 'en';
    }

    /**
     * @param array<string, array<array-key, mixed>>|null $labels Pre-resolved labels
     *   from resolveLabels(); resolved for this sheet alone when omitted.
     */
    private function serialize(SeparationSheet $sheet, ?array $labels = null): array {
        $labels = $labels ?? $this->resolveLabels([$sheet]);

        $orgUuid         = $sheet->getOrgUuid();
        $subject         = $labels['subjects'][$sheet->getClassSubjectUuid()] ?? null;
        $sensitivity     = $labels['sensitivities'][$sheet->getSensitivityUuid()] ?? null;
        $facet           = $labels['facets'][$sheet->getClassificationFacetUuid()] ?? null;
        $insightLevelId  = $sheet->getInsightLevelId();
        $responsibleUid  = $sheet->getResponsibleUserId();
        $responsibleUser = $responsibleUid ? $this->userManager->get($responsibleUid) : null;

        return [
            'id'                            => $sheet->getId(),
            'type'                          => $sheet->getType(),
            'name'                          => $sheet->getName(),
            'case_number'                   => $sheet->getCaseNumber(),
            'title'                         => $sheet->getTitle(),
            'org_uuid'                      => $orgUuid,
            'organisation_name'             => $orgUuid ? ($labels['orgs'][$orgUuid] ?? '') : '',
            'class_subject_uuid'            => $sheet->getClassSubjectUuid(),
            'class_subject_code'            => $subject?->getCode() ?? '',
            'class_subject_title'           => $subject?->getTitle() ?? '',
            'sensitivity_uuid'              => $sheet->getSensitivityUuid(),
            'sensitivity_title'             => $sensitivity?->getTitle() ?? '',
            'classification_facet_uuid'     => $sheet->getClassificationFacetUuid(),
            'classification_facet_code'     => $facet['code'] ?? '',
            'classification_facet_title'    => $facet['title'] ?? '',
            'insight_level_id'              => $insightLevelId,
            'insight_level_name'            => $insightLevelId ? ($labels['insightLevels'][$insightLevelId] ?? '') : '',
            'responsible_user_id'           => $responsibleUid,
            'responsible_user_display_name' => $responsibleUser?->getDisplayName() ?? $responsibleUid ?? '',
        ];
    }
}
