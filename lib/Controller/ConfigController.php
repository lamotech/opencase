<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\ClassificationFacetRepository;
use OCA\OpenCase\Db\ConfigMapper;
use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Db\SensitivityMapper;
use OCA\OpenCase\Service\AccessProfile\AccessProfileTypeResolver;
use OCA\OpenCase\Service\CaseClassificationService;
use OCA\OpenCase\Service\CaseNumberService;
use OCA\OpenCase\Service\ClassificationPlanService;
use OCA\OpenCase\Service\DisposalService;
use OCA\OpenCase\Service\RoleService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Config API accessible to OpenCase Administrators (not necessarily NC admins).
 *
 * Routes:
 *   GET  /api/v1/config          — list all config entries with name + description
 *   PUT  /api/v1/config/{key}    — update a single config value
 */
class ConfigController extends OCSController {

    private const INBOX_KEYS = [
        'incoming_sensitivity',
        'incoming_kle',
        'incoming_facet',
        'incoming_insight_level',
        AccessProfileTypeResolver::INBOX_GROUP_CONFIG_KEY,
        ClassificationPlanService::INBOX_CONFIG_KEY,
    ];

    public function __construct(
        string $appName,
        IRequest $request,
        private ?string $userId,
        private ConfigMapper $configMapper,
        private CaseNumberService $caseNumberService,
        private RoleService $roleService,
        private OrganisationMapper $organisationMapper,
        private CaseMapper $caseMapper,
        private AccessProfileTypeResolver $accessProfileTypeResolver,
        private CaseClassificationService $caseClassificationService,
        private SensitivityMapper $sensitivityMapper,
        private ClassificationFacetRepository $classificationFacetRepository,
        private DisposalService $disposalService,
        private ClassificationPlanService $classificationPlanService,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
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

    /**
     * Return all config entries with their human-readable name and description.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/config')]
    public function index(): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }

        return new DataResponse(['entries' => $this->configMapper->getAllWithMeta()]);
    }

    /**
     * Update a single config value by key.
     *
     * Request body: { "value": "..." }
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/config/{key}')]
    public function update(string $key): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }

        $value = (string)($this->request->getParam('value', ''));

        if ($key === AccessProfileTypeResolver::INBOX_GROUP_CONFIG_KEY
            && $value !== '' && !$this->groupManager->groupExists($value)) {
            return new DataResponse(['error' => "Group not found: {$value}"], Http::STATUS_BAD_REQUEST);
        }
        if ($key === ClassificationPlanService::INBOX_CONFIG_KEY) {
            try {
                $this->classificationPlanService->validateUnlessCurrent($value, $this->configMapper->get($key, ''));
            } catch (\InvalidArgumentException $e) {
                return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
            }
        }

        // Delegate to domain-specific service for keys that have validation.
        if ($key === 'case_number_mask') {
            try {
                $this->caseNumberService->setMask($value);
            } catch (\InvalidArgumentException $e) {
                return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
            }
        } else {
            $this->configMapper->set($key, $value);
        }

        if (in_array($key, self::INBOX_KEYS, true)) {
            $this->syncInboxCase();
        }

        return new DataResponse(['entries' => $this->configMapper->getAllWithMeta()]);
    }

    /**
     * If an inbox case already exists for the configured organisation, update it
     * to reflect the current incoming_* settings.
     */
    private function syncInboxCase(): void {
        try {
            $orgUuid = $this->configMapper->get('incoming_organisation', '');
            if ($orgUuid !== '') {
                $org = $this->organisationMapper->findByUuid($orgUuid);
            } else {
                $org = $this->organisationMapper->findTopLevel();
            }
            if ($org === null) {
                return;
            }

            $case = $this->caseMapper->findInboxByOrgUuid($org->getOrgUuid());
            if ($case === null) {
                return;
            }

            $changed = false;

            // ── Access profile (FK: sensitivity + KLE; Grupper: group) ──
            $sensitivityUuid = $this->configMapper->get('incoming_sensitivity', '31c09910-e011-46a5-86fb-254374421fe8');
            $kleCode         = $this->configMapper->get('incoming_kle', '00.01.00');

            $inboxAccessConfigured = $this->accessProfileTypeResolver->isGroupMode()
                ? $this->configMapper->get(AccessProfileTypeResolver::INBOX_GROUP_CONFIG_KEY, '') !== ''
                : $this->sensitivityMapper->findByUuid($sensitivityUuid) !== null;
            if ($inboxAccessConfigured) {
                // Resolving also grants the profile to the users entitled to it,
                // regardless of whether the profile is new or pre-existing.
                $profile = $this->accessProfileTypeResolver->resolveInboxProfile($org->getOrgUuid(), fn() => [
                    'classification_code' => $kleCode,
                    'sensitivity_uuid'    => $sensitivityUuid,
                ]);
                if ($case->getAccessProfileId() !== $profile->getId()) {
                    $case->setAccessProfileId($profile->getId());
                    $changed = true;
                }
            }

            // Grupper + KLE: KLE number and sensitivity are kept on the case
            if ($this->caseClassificationService->onCase()) {
                [$oldClass, $oldSens] = [$case->getClassSubjectUuid(), $case->getSensitivityUuid()];
                $this->caseClassificationService->applyToInboxCase($case);
                if ($case->getClassSubjectUuid() !== $oldClass || $case->getSensitivityUuid() !== $oldSens) {
                    $changed = true;
                }
            }

            // ── Facet ───────────────────────────────────────────────────
            $facetUuid = $this->configMapper->get('incoming_facet', '');
            if ($facetUuid === '') {
                $facetUuid = $this->classificationFacetRepository->findByCode('A00')['uuid'] ?? 'A00';
            }
            if ($case->getClassificationFacetUuid() !== $facetUuid) {
                $case->setClassificationFacetUuid($facetUuid);
                $changed = true;
            }

            // ── Emneplan item ───────────────────────────────────────────
            if ($this->classificationPlanService->isEmneplan()) {
                // Null also when the chosen item is deactivated; the case then keeps its item
                $classPlanUuid = $this->classificationPlanService->inboxClassPlanUuid();
                $cleared = $this->configMapper->get(ClassificationPlanService::INBOX_CONFIG_KEY, '') === '';
                if (($classPlanUuid !== null || $cleared) && $case->getClassPlanUuid() !== $classPlanUuid) {
                    $case->setClassPlanUuid($classPlanUuid);
                    $changed = true;
                }
            }

            // ── Insight level ───────────────────────────────────────────
            $insightLevel = (int)$this->configMapper->get('incoming_insight_level', '3') ?: null;
            if ($case->getInsightLevelId() !== $insightLevel) {
                $case->setInsightLevelId($insightLevel);
                $changed = true;
            }

            if ($changed) {
                $this->disposalService->applyDisposalCode($case);
                $case->setUpdatedAt(new \DateTime());
                $this->caseMapper->update($case);
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ConfigController: failed to sync inbox case after config change — ' . $e->getMessage(),
                ['app' => 'opencase', 'exception' => $e]
            );
        }
    }
}
