<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\AccessProfileMapper;
use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\ClassificationFacetRepository;
use OCA\OpenCase\Db\ClassificationSubjectMapper;
use OCA\OpenCase\Db\ConfigMapper;
use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Db\SensitivityMapper;
use OCA\OpenCase\Service\CaseNumberService;
use OCA\OpenCase\Service\PrivilegeService;
use OCA\OpenCase\Service\RoleService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
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
        private AccessProfileMapper $accessProfileMapper,
        private ClassificationSubjectMapper $classificationSubjectMapper,
        private SensitivityMapper $sensitivityMapper,
        private ClassificationFacetRepository $classificationFacetRepository,
        private PrivilegeService $privilegeService,
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

            // ── Access profile (sensitivity + KLE) ─────────────────────
            $sensitivityUuid = $this->configMapper->get('incoming_sensitivity', '31c09910-e011-46a5-86fb-254374421fe8');
            $kleCode         = $this->configMapper->get('incoming_kle', '00.01.00');

            $sensitivity = $this->sensitivityMapper->findByUuid($sensitivityUuid);
            if ($sensitivity !== null) {
                $classSubject = $this->classificationSubjectMapper->findOrCreateByCode($kleCode);
                $profile      = $this->accessProfileMapper->findOrCreate(
                    $org->getOrgUuid(),
                    $classSubject->getUuid(),
                    $sensitivity->getUuid()
                );
                if ($case->getAccessProfileId() !== $profile->getId()) {
                    $case->setAccessProfileId($profile->getId());
                    $changed = true;
                }
                // Grant access to all users whose privileges cover this profile,
                // regardless of whether the profile is new or pre-existing.
                $this->privilegeService->grantPrivilegedUsersToProfile(
                    $profile->getId(),
                    $org->getOrgUuid(),
                    $classSubject->getUuid(),
                    $sensitivity->getUuid()
                );
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

            // ── Insight level ───────────────────────────────────────────
            $insightLevel = (int)$this->configMapper->get('incoming_insight_level', '3') ?: null;
            if ($case->getInsightLevelId() !== $insightLevel) {
                $case->setInsightLevelId($insightLevel);
                $changed = true;
            }

            if ($changed) {
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
