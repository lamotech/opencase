<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\BackgroundJob\BulkReindexJob;
use OCA\OpenCase\Db\AccessProfileMapper;
use OCA\OpenCase\Db\ClassificationSubjectMapper;
use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Db\SensitivityMapper;
use OCA\OpenCase\Db\UserAccessMapper;
use OCA\OpenCase\Service\CaseNumberService;
use OCA\OpenCase\Service\ElasticsearchService;
use OCA\OpenCase\Service\FileService;
use OCA\OpenCase\Service\PermissionService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;

/**
 * Admin-only REST API for managing access profiles, user assignments,
 * and system operations.
 *
 * All endpoints require admin privileges (no #[NoAdminRequired]).
 *
 * Routes:
 *   GET    /api/v1/admin/profiles                       — List access profiles
 *   POST   /api/v1/admin/profiles                       — Create an access profile
 *   GET    /api/v1/admin/profiles/{id}/users             — List users assigned to a profile
 *   POST   /api/v1/admin/users/{userId}/access           — Grant user access to a profile
 *   DELETE /api/v1/admin/users/{userId}/access/{profileId} — Revoke user access
 *   POST   /api/v1/admin/users/{userId}/bulk-access      — Bulk grant profiles to user
 *   DELETE /api/v1/admin/users/{userId}/access            — Revoke all access for a user
 *   POST   /api/v1/admin/reindex                         — Trigger full ES reindex
 *   POST   /api/v1/admin/reindex/{caseId}                — Trigger case ES reindex
 *   POST   /api/v1/admin/es/setup                        — Create/update ES index and pipeline
 *   GET    /api/v1/admin/config                          — Get app configuration
 *   PUT    /api/v1/admin/config                          — Update app configuration
 */
class AdminController extends ApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private AccessProfileMapper $accessProfileMapper,
        private OrganisationMapper $organisationMapper,
        private ClassificationSubjectMapper $classificationSubjectMapper,
        private SensitivityMapper $sensitivityMapper,
        private UserAccessMapper $userAccessMapper,
        private PermissionService $permissionService,
        private CaseNumberService $caseNumberService,
        private FileService $fileService,
        private ElasticsearchService $esService,
        private IJobList $jobList,
    ) {
        parent::__construct($appName, $request);
    }

    // ---------------------------------------------------------------
    // Access profile management
    // ---------------------------------------------------------------

    /**
     * List access profiles, optionally filtered by organisation.
     */
    #[ApiRoute(verb: 'GET', url: '/api/v1/admin/profiles')]
    public function listProfiles(): DataResponse {
        $org = $this->request->getParam('organisation');
        $limit = min((int)($this->request->getParam('limit', '100')), 500);
        $offset = max((int)($this->request->getParam('offset', '0')), 0);

        if ($org !== null && $org !== '') {
            $profiles = $this->accessProfileMapper->findByOrganisation($org, $limit, $offset);
        } else {
            // Without org filter, this could be large.
            // In production, require an organisation filter or implement global pagination.
            $profiles = $this->accessProfileMapper->findByOrganisation('', $limit, $offset);
        }

        // Resolve org UUIDs → names in one batch lookup
        $orgUuids = array_unique(array_map(fn($p) => $p->getOrgUuid(), $profiles));
        $orgNames = $this->organisationMapper->getNamesByUuids($orgUuids);

        // Resolve class subject UUIDs → code in one batch lookup
        $csUuids   = array_unique(array_map(fn($p) => $p->getClassSubjectUuid(), $profiles));
        $csObjects = $this->classificationSubjectMapper->getByUuids($csUuids);

        // Resolve sensitivity UUIDs → title in one batch lookup
        $sensUuids   = array_unique(array_map(fn($p) => $p->getSensitivityUuid(), $profiles));
        $sensObjects = $this->sensitivityMapper->getByUuids($sensUuids);

        return new DataResponse([
            'profiles' => array_map(fn($p) => [
                'id'                  => $p->getId(),
                'organisation'        => $orgNames[$p->getOrgUuid()] ?? $p->getOrgUuid(),
                'classification_code' => $csObjects[$p->getClassSubjectUuid()]?->getCode() ?? '',
                'sensitivity_key'     => $sensObjects[$p->getSensitivityUuid()]?->getKey() ?? '',
                'sensitivity_title'   => $sensObjects[$p->getSensitivityUuid()]?->getTitle() ?? '',
                'created_at'          => $p->getCreatedAt()?->format('c'),
            ], $profiles),
        ]);
    }

    /**
     * Create an access profile.
     *
     * Request body (JSON):
     *   organisation        — Required
     *   classification_code — Required
     *   sensitivity         — Required
     */
    #[ApiRoute(verb: 'POST', url: '/api/v1/admin/profiles')]
    public function createProfile(): DataResponse {
        $params = $this->request->getParams();

        $required = ['organisation', 'classification_code', 'sensitivity'];
        foreach ($required as $field) {
            if (empty($params[$field])) {
                return new DataResponse(
                    ['error' => "Field '{$field}' is required"],
                    Http::STATUS_BAD_REQUEST
                );
            }
        }

        $org          = $this->organisationMapper->findOrCreateByName($params['organisation']);
        $classSubject = $this->classificationSubjectMapper->findOrCreateByCode($params['classification_code']);
        $sens         = $this->sensitivityMapper->findOrCreateByKey($params['sensitivity']);
        $profile      = $this->accessProfileMapper->findOrCreate(
            $org->getOrgUuid(),
            $classSubject->getUuid(),
            $sens->getUuid()
        );

        return new DataResponse([
            'profile' => [
                'id'                  => $profile->getId(),
                'organisation'        => $org->getOrgName(),
                'classification_code' => $classSubject->getCode(),
                'sensitivity_key'     => $sens->getKey(),
                'sensitivity_title'   => $sens->getTitle(),
            ],
        ], Http::STATUS_CREATED);
    }

    /**
     * List users assigned to an access profile.
     */
    #[ApiRoute(verb: 'GET', url: '/api/v1/admin/profiles/{id}/users')]
    public function profileUsers(int $id): DataResponse {
        $assignments = $this->userAccessMapper->getUsersForProfile($id);

        return new DataResponse([
            'users' => array_map(fn($a) => [
                'user_id' => $a->getUserId(),
                'access_level' => $a->getAccessLevel(),
                'granted_at' => $a->getGrantedAt()?->format('c'),
                'granted_by' => $a->getGrantedBy(),
            ], $assignments),
        ]);
    }

    // ---------------------------------------------------------------
    // User access management
    // ---------------------------------------------------------------

    /**
     * Grant a user access to an access profile.
     *
     * Request body (JSON):
     *   access_profile_id — Required
     *   access_level      — Required. 'read' or 'write'
     */
    #[ApiRoute(verb: 'POST', url: '/api/v1/admin/users/{userId}/access')]
    public function grantAccess(string $userId): DataResponse {
        $params = $this->request->getParams();

        if (empty($params['access_profile_id']) || empty($params['access_level'])) {
            return new DataResponse(
                ['error' => "Fields 'access_profile_id' and 'access_level' are required"],
                Http::STATUS_BAD_REQUEST
            );
        }

        $validLevels = ['read', 'write'];
        if (!in_array($params['access_level'], $validLevels)) {
            return new DataResponse(
                ['error' => "access_level must be 'read' or 'write'"],
                Http::STATUS_BAD_REQUEST
            );
        }

        $profileId = (int)$params['access_profile_id'];

        $entity = $this->userAccessMapper->grantAccess(
            $userId,
            $profileId,
            $params['access_level'],
            $params['granted_by'] ?? null,
        );

        // Invalidate permission cache and update filecache ceiling permissions
        $this->permissionService->invalidateUserCache($userId);
        $this->fileService->updateCeilingPermissionsForProfile($profileId);

        return new DataResponse([
            'access' => [
                'user_id' => $entity->getUserId(),
                'access_profile_id' => $entity->getAccessProfileId(),
                'access_level' => $entity->getAccessLevel(),
            ],
        ], Http::STATUS_CREATED);
    }

    /**
     * Revoke a user's access to a specific profile.
     */
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/admin/users/{userId}/access/{profileId}')]
    public function revokeAccess(string $userId, int $profileId): DataResponse {
        $this->userAccessMapper->revokeAccess($userId, $profileId);
        $this->permissionService->invalidateUserCache($userId);
        $this->fileService->updateCeilingPermissionsForProfile($profileId);

        return new DataResponse(['revoked' => true]);
    }

    /**
     * Bulk grant: assign a user to multiple profiles at once.
     *
     * Request body (JSON):
     *   profile_ids   — Required. Array of access profile IDs
     *   access_level  — Required. 'read' or 'write'
     */
    #[ApiRoute(verb: 'POST', url: '/api/v1/admin/users/{userId}/bulk-access')]
    public function bulkGrant(string $userId): DataResponse {
        $params = $this->request->getParams();

        if (empty($params['profile_ids']) || !is_array($params['profile_ids'])) {
            return new DataResponse(
                ['error' => "Field 'profile_ids' must be a non-empty array"],
                Http::STATUS_BAD_REQUEST
            );
        }
        if (empty($params['access_level'])) {
            return new DataResponse(
                ['error' => "Field 'access_level' is required"],
                Http::STATUS_BAD_REQUEST
            );
        }

        $profileIds = array_map('intval', $params['profile_ids']);

        $this->userAccessMapper->bulkGrant(
            $userId,
            $profileIds,
            $params['access_level'],
            $params['granted_by'] ?? null,
        );

        $this->permissionService->invalidateUserCache($userId);
        foreach ($profileIds as $profileId) {
            $this->fileService->updateCeilingPermissionsForProfile($profileId);
        }

        return new DataResponse([
            'granted' => count($profileIds),
        ]);
    }

    /**
     * Revoke all access for a user (e.g. offboarding).
     */
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/admin/users/{userId}/access')]
    public function revokeAllAccess(string $userId): DataResponse {
        // Capture affected profile IDs before revoking so we can update ceilings
        $affected = $this->userAccessMapper->getProfileIdsForUser($userId);

        $this->userAccessMapper->revokeAllForUser($userId);
        $this->permissionService->invalidateUserCache($userId);

        foreach ($affected as $profileId) {
            $this->fileService->updateCeilingPermissionsForProfile($profileId);
        }

        return new DataResponse(['revoked_all' => true]);
    }

    // ---------------------------------------------------------------
    // Elasticsearch management
    // ---------------------------------------------------------------

    /**
     * Create or update the Elasticsearch index and ingest pipeline.
     */
    #[ApiRoute(verb: 'POST', url: '/api/v1/admin/es/setup')]
    public function setupElasticsearch(): DataResponse {
        try {
            $this->esService->setupIndex();
            return new DataResponse(['success' => true, 'message' => 'Index and pipeline created/updated']);
        } catch (\Throwable $e) {
            return new DataResponse(
                ['error' => 'ES setup failed: ' . $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Trigger a full re-index of all files.
     */
    #[ApiRoute(verb: 'POST', url: '/api/v1/admin/reindex')]
    public function reindexAll(): DataResponse {
        $batchSize = (int)($this->request->getParam('batch_size', '500'));

        $this->jobList->add(BulkReindexJob::class, [
            'full' => true,
            'offset' => 0,
            'batch_size' => $batchSize,
        ]);

        return new DataResponse([
            'success' => true,
            'message' => 'Full re-index queued with batch size ' . $batchSize,
        ]);
    }

    /**
     * Trigger re-index of all files in a specific case.
     */
    #[ApiRoute(verb: 'POST', url: '/api/v1/admin/reindex/{caseId}')]
    public function reindexCase(int $caseId): DataResponse {
        $this->jobList->add(BulkReindexJob::class, [
            'case_id' => $caseId,
        ]);

        return new DataResponse([
            'success' => true,
            'message' => "Re-index queued for case {$caseId}",
        ]);
    }

    // ---------------------------------------------------------------
    // App configuration
    // ---------------------------------------------------------------

    /**
     * Return the current app configuration.
     *
     * Response body:
     *   case_number_mask — The mask used to generate case numbers.
     *                      Syntax: yyyy=4-digit year, yy=2-digit year,
     *                      #...#=sequence (width = number of # chars).
     *                      Example: "yyyy-#####" → "2026-00001"
     */
    #[ApiRoute(verb: 'GET', url: '/api/v1/admin/config')]
    public function getConfig(): DataResponse {
        return new DataResponse([
            'config' => [
                'case_number_mask' => $this->caseNumberService->getMask(),
            ],
        ]);
    }

    /**
     * Update app configuration.
     *
     * Request body (JSON):
     *   case_number_mask — Optional. New case number mask.
     *
     * Only recognised keys are applied; unknown keys are ignored.
     */
    #[ApiRoute(verb: 'PUT', url: '/api/v1/admin/config')]
    public function updateConfig(): DataResponse {
        $params = $this->request->getParams();

        if (isset($params['case_number_mask'])) {
            try {
                $this->caseNumberService->setMask((string)$params['case_number_mask']);
            } catch (\InvalidArgumentException $e) {
                return new DataResponse(
                    ['error' => $e->getMessage()],
                    Http::STATUS_BAD_REQUEST
                );
            }
        }

        return new DataResponse([
            'config' => [
                'case_number_mask' => $this->caseNumberService->getMask(),
            ],
        ]);
    }
}
