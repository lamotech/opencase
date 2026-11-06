<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\AccessProfile;
use OCA\OpenCase\Db\AccessProfileMapper;
use OCA\OpenCase\Db\CaseEntity;
use OCA\OpenCase\Db\CaseStatusMapper;
use OCA\OpenCase\Db\CaseTypeMapper;
use OCA\OpenCase\Db\CaseWorkerMapper;
use OCA\OpenCase\Db\DisposalCodeMapper;
use OCA\OpenCase\Db\UserAccessMapper;
use OCA\OpenCase\Db\UserInfoMapper;
use OCA\OpenCase\Db\ClassificationFacetRepository;
use OCA\OpenCase\Db\ClassificationSubject;
use OCA\OpenCase\Db\InsightLevelMapper;
use OCA\OpenCase\Db\ClassificationPlan;
use OCA\OpenCase\Db\ClassificationPlanMapper;
use OCA\OpenCase\Db\ClassificationSubjectMapper;
use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Db\Sensitivity;
use OCA\OpenCase\Db\SensitivityMapper;
use OCA\OpenCase\Service\AccessProfile\AccessProfileTypeResolver;
use OCA\OpenCase\Service\AccessProfile\GroupAccessProfileType;
use OCA\OpenCase\Service\CaseClassificationService;
use OCA\OpenCase\Service\CaseService;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\TransactionLogService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;

/**
 * REST API controller for case management.
 *
 * All endpoints require authentication. Access control is enforced
 * at the service layer based on the user's access profiles.
 *
 * Routes:
 *   GET    /api/v1/cases           — List cases (paginated, filterable)
 *   POST   /api/v1/cases           — Create a new case
 *   GET    /api/v1/cases/{id}      — Get a single case
 *   PUT    /api/v1/cases/{id}      — Update case metadata
 *   PUT    /api/v1/cases/{id}/status — Change case status
 *   GET    /api/v1/cases/stats     — Get case count statistics
 */
class CaseController extends ApiController {

    /** @var array<int, string> disposal code id → name, cached per request */
    private array $disposalcodeNames = [];

    public function __construct(
        string $appName,
        IRequest $request,
        private CaseService $caseService,
        private OrganisationMapper $organisationMapper,
        private AccessProfileMapper $accessProfileMapper,
        private ClassificationSubjectMapper $classificationSubjectMapper,
        private ClassificationFacetRepository $classificationFacetRepository,
        private SensitivityMapper $sensitivityMapper,
        private CaseStatusMapper $caseStatusMapper,
        private CaseTypeMapper $caseTypeMapper,
        private InsightLevelMapper $insightLevelMapper,
        private DisposalCodeMapper $disposalCodeMapper,
        private IConfig $config,
        private IUserManager $userManager,
        private CaseWorkerMapper $caseWorkerMapper,
        private UserAccessMapper $userAccessMapper,
        private UserInfoMapper $userInfoMapper,
        private TransactionLogService $transactionLogService,
        private AccessProfileTypeResolver $accessProfileTypeResolver,
        private CaseClassificationService $caseClassificationService,
        private GroupAccessProfileType $groupAccessProfileType,
        private ClassificationPlanMapper $classificationPlanMapper,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request, corsAllowedHeaders: 'Authorization, Content-Type, Accept, OCS-APIREQUEST');
    }

    /**
     * List cases with optional filtering.
     *
     * Query parameters:
     *   organisation  — Filter by organisation name
     *   year          — Filter by year
     *   status        — Filter by status (open, closed, archived)
     *   search        — Search in title and case number
     *   limit         — Max results (default 50, max 200)
     *   offset        — Pagination offset
     */
    #[NoAdminRequired]
    #[CORS]
    #[ApiRoute(verb: 'GET', url: '/api/v1/cases')]
    public function index(): DataResponse {
        $organisation             = $this->request->getParam('organisation');
        $year                     = $this->request->getParam('year');
        $statusIdRaw              = $this->request->getParam('status_id');
        $statusId                 = $statusIdRaw !== null ? (int)$statusIdRaw : null;
        $search                   = $this->request->getParam('search');
        $classificationCode       = $this->request->getParam('classification_code') ?: null;
        $sensitivityKey           = $this->request->getParam('sensitivity_key') ?: null;
        $classificationFacetUuid  = $this->request->getParam('classification_facet_uuid') ?: null;
        $classPlanCode            = $this->request->getParam('class_plan_code') ?: null;
        $insightLevelIdRaw        = $this->request->getParam('insight_level_id');
        $insightLevelId           = ($insightLevelIdRaw !== null && $insightLevelIdRaw !== '') ? (int)$insightLevelIdRaw : null;
        $responsibleUserId        = $this->request->getParam('responsible_user_id') ?: null;
        $casetypeIdRaw            = $this->request->getParam('casetype_id');
        $casetypeId               = ($casetypeIdRaw !== null && $casetypeIdRaw !== '') ? (int)$casetypeIdRaw : null;
        $customFields             = $this->parseCustomFieldFilters($this->request->getParam('custom_fields', []));
        $limit                    = min((int)($this->request->getParam('limit', '50')), 1000);
        $offset                   = max((int)($this->request->getParam('offset', '0')), 0);

        $result = $this->caseService->list(
            $this->userId,
            $organisation,
            $year,
            $statusId,
            $search,
            $limit,
            $offset,
            $classificationCode,
            $sensitivityKey,
            $classificationFacetUuid,
            $insightLevelId,
            $responsibleUserId,
            $casetypeId,
            $customFields,
            $classPlanCode,
        );

        if ($this->userId !== null) {
            $criteria = array_filter([
                'search'                   => $search,
                'organisation'             => $organisation,
                'year'                     => $year,
                'status_id'                => $statusId,
                'classification_code'      => $classificationCode,
                'sensitivity_key'          => $sensitivityKey,
                'classification_facet_uuid'=> $classificationFacetUuid,
                'class_plan_code'          => $classPlanCode,
                'insight_level_id'         => $insightLevelId,
                'responsible_user_id'      => $responsibleUserId,
                'casetype_id'              => $casetypeId,
            ], fn($v) => $v !== null && $v !== '');
            if (!empty($customFields)) {
                $criteria['custom_fields'] = $customFields;
            }
            $this->transactionLogService->log($this->userId, 'search_case', $criteria);
        }

        // Batch-resolve org UUIDs → names for all cases in one query
        $orgUuids = array_unique(array_map(fn($c) => $c->getOrgUuid(), $result['cases']));
        $orgNames = $this->organisationMapper->getNamesByUuids($orgUuids);

        // Batch-resolve class subject and sensitivity UUIDs → objects: the
        // case's own (Grupper + KLE), else its access profile's (FK Adgang)
        $profileIds        = array_unique(array_map(fn($c) => $c->getAccessProfileId(), $result['cases']));
        $profileClassUuids = $this->accessProfileMapper->getClassSubjectUuidsByProfileIds($profileIds);
        $profileSensUuids  = $this->accessProfileMapper->getSensitivityUuidsByProfileIds($profileIds);
        $caseClassUuid     = fn($c) => $c->getClassSubjectUuid() ?? ($profileClassUuids[$c->getAccessProfileId()] ?? null);
        $caseSensUuid      = fn($c) => $c->getSensitivityUuid() ?? ($profileSensUuids[$c->getAccessProfileId()] ?? null);
        $classSubjects     = $this->classificationSubjectMapper->getByUuids(
            array_values(array_unique(array_filter(array_map($caseClassUuid, $result['cases']))))
        );
        $sensitivities     = $this->sensitivityMapper->getByUuids(
            array_values(array_unique(array_filter(array_map($caseSensUuid, $result['cases']))))
        );
        $profileGroups     = $this->groupAccessProfileType->describeGroups($profileIds);
        $classPlanItems    = $this->classificationPlanMapper->getByUuids(
            array_unique(array_map(fn($c) => (string)$c->getClassPlanUuid(), $result['cases']))
        );

        // Batch-resolve status IDs → localized names
        $statusIds   = array_unique(array_map(fn($c) => $c->getStatusId(), $result['cases']));
        $statusNames = $this->caseStatusMapper->getNamesByIds($statusIds, $this->userLanguage());

        // Batch-resolve insight level IDs → localized names
        $insightIds   = array_unique(array_filter(array_map(fn($c) => $c->getInsightLevelId(), $result['cases'])));
        $insightNames = $this->insightLevelMapper->getNamesByIds($insightIds, $this->userLanguage());

        // Batch-resolve case type IDs → localized names
        $casetypeIds   = array_unique(array_filter(array_map(fn($c) => $c->getCasetypeId(), $result['cases'])));
        $casetypeNames = $this->caseTypeMapper->getNamesByIds($casetypeIds, $this->userLanguage());

        return new DataResponse([
            'cases'  => array_map(function ($c) use ($orgNames, $caseClassUuid, $classSubjects, $caseSensUuid, $sensitivities, $profileGroups, $classPlanItems, $statusNames, $insightNames, $casetypeNames) {
                $csUuid   = $caseClassUuid($c);
                $cs       = $csUuid ? ($classSubjects[$csUuid] ?? null) : null;
                $sensUuid = $caseSensUuid($c);
                $sens     = $sensUuid ? ($sensitivities[$sensUuid] ?? null) : null;
                $sid        = $c->getStatusId();
                $sEntry     = $statusNames[$sid] ?? [];
                $statusInfo = empty($sEntry) ? null : [
                    'name'      => $sEntry['name'],
                    'css_class' => $this->statusCssClass($sid),
                    'is_closed' => $sEntry['is_closed'],
                ];
                $ilId   = $c->getInsightLevelId();
                $ilName = $ilId ? ($insightNames[$ilId] ?? '') : '';
                $ctId   = $c->getCasetypeId();
                $ctName = $ctId ? ($casetypeNames[$ctId] ?? '') : '';
                $facet  = $this->resolveFacet($c->getClassificationFacetUuid());
                $plan   = $classPlanItems[(string)$c->getClassPlanUuid()] ?? null;
                return $this->serializeCase($c, $orgNames[$c->getOrgUuid()] ?? '', $cs, $sens, $statusInfo, false, $ilName, $facet, $ctName, $profileGroups[$c->getAccessProfileId()] ?? null, $plan);
            }, $result['cases']),
            'total'  => $result['total'],
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Create a new case.
     *
     * The case number is generated automatically from the mask configured by
     * an administrator. Callers must not supply a case_number.
     *
     * Request body (JSON):
     *   title                — Required. Case title
     *   organisation         — Required. Organisation name
     *   classification_code  — Required with FK Adgang and Grupper + KLE. KLE classification code
     *   sensitivity          — Required with FK Adgang and Grupper + KLE. Sensitivity level
     *   classification_facet_uuid — Required with FK Adgang and Grupper + KLE. KLE facet
     *   group_id             — Required with Grupper. Nextcloud group id
     *   class_plan_uuid      — Optional. Emneplan item (classification plan Emneplan)
     *   responsible_user_id  — Optional. Defaults to the current user
     *   summary              — Optional. Case summary as rich-text HTML
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/cases')]
    public function create(): DataResponse {
        $params = $this->getJsonBody();

        // The access fields depend on the access profile type; KLE number,
        // sensitivity and facet apply with FK Adgang and with Grupper + KLE.
        $required = ['title', 'organisation', ...$this->caseClassificationService->requiredCreateFields()];
        foreach ($required as $field) {
            if (empty($params[$field])) {
                return new DataResponse(
                    ['error' => "Field '{$field}' is required"],
                    Http::STATUS_BAD_REQUEST
                );
            }
        }

        $responsibleUserId = !empty($params['responsible_user_id'])
            ? $params['responsible_user_id']
            : $this->userId;

        $insightLevelId = isset($params['insight_level_id']) ? (int)$params['insight_level_id'] : null;
        $parentCaseId   = isset($params['parent_case_id']) ? (int)$params['parent_case_id'] : null;
        $casetypeId     = !empty($params['casetype_id']) ? (int)$params['casetype_id'] : null;
        $summary        = !empty($params['summary']) ? (string)$params['summary'] : null;

        try {
            $entity = $this->caseService->create(
                $params['title'],
                $params['organisation'],
                array_map('strval', array_filter(
                    array_intersect_key($params, array_flip(['classification_code', 'sensitivity', 'group_id'])),
                    fn($v) => $v !== null && $v !== ''
                )),
                $this->userId,
                $responsibleUserId,
                $insightLevelId,
                !empty($params['classification_facet_uuid']) ? $params['classification_facet_uuid'] : null,
                $parentCaseId,
                $casetypeId,
                $summary,
                !empty($params['class_plan_uuid']) ? (string)$params['class_plan_uuid'] : null,
            );

            $orgName     = $this->organisationMapper->findByUuid($entity->getOrgUuid())?->getOrgName() ?? '';
            $profileInfo = $this->resolveProfileInfo($entity);
            $statusInfo  = $this->resolveStatusInfo($entity->getStatusId());
            $ilName      = $this->resolveInsightLevelName($entity->getInsightLevelId());
            $ctName      = $this->resolveCasetypeName($entity->getCasetypeId());
            $facet       = $this->resolveFacet($entity->getClassificationFacetUuid());

            return new DataResponse(
                ['case' => $this->serializeCase($entity, $orgName, $profileInfo['class_subject'], $profileInfo['sensitivity'], $statusInfo, false, $ilName, $facet, $ctName, $profileInfo['group'], $this->resolveClassPlan($entity->getClassPlanUuid()))],
                Http::STATUS_CREATED
            );
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        } catch (NotFoundException $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_FORBIDDEN
            );
        }
    }

    /**
     * Get a single case by ID.
     */
    #[NoAdminRequired]
    #[CORS]
    #[ApiRoute(verb: 'GET', url: '/api/v1/cases/{id}')]
    public function show(int $id): DataResponse {
        try {
            $audit       = $this->request->getParam('audit', 'true') !== 'false';
            $entity      = $this->caseService->get($id, $this->userId, $audit);
            $orgName     = $this->organisationMapper->findByUuid($entity->getOrgUuid())?->getOrgName() ?? '';
            $profileInfo = $this->resolveProfileInfo($entity);
            $statusInfo  = $this->resolveStatusInfo($entity->getStatusId());
            $canWrite    = $this->caseService->canWriteCase($id, $this->userId);
            $ilName      = $this->resolveInsightLevelName($entity->getInsightLevelId());
            $ctName      = $this->resolveCasetypeName($entity->getCasetypeId());
            $facet       = $this->resolveFacet($entity->getClassificationFacetUuid());
            return new DataResponse(['case' => $this->serializeCase($entity, $orgName, $profileInfo['class_subject'], $profileInfo['sensitivity'], $statusInfo, $canWrite, $ilName, $facet, $ctName, $profileInfo['group'], $this->resolveClassPlan($entity->getClassPlanUuid()))]);
        } catch (NotFoundException $e) {
            return new DataResponse(
                ['error' => 'Case not found'],
                Http::STATUS_NOT_FOUND
            );
        }
    }

    /**
     * Update case metadata.
     *
     * Request body (JSON): any of title, organisation,
     * classification_code, sensitivity (FK Adgang), group_id (Grupper), summary.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/cases/{id}')]
    public function update(int $id): DataResponse {
        $params = $this->getJsonBody();

        $insightLevelProvided = array_key_exists('insight_level_id', $params);
        $insightLevelId       = $insightLevelProvided ? (isset($params['insight_level_id']) ? (int)$params['insight_level_id'] : null) : null;

        $facetUuidProvided = array_key_exists('classification_facet_uuid', $params);
        $facetUuid         = $facetUuidProvided ? ($params['classification_facet_uuid'] ?: null) : null;

        $summaryProvided = array_key_exists('summary', $params);
        $summary         = $summaryProvided ? ($params['summary'] ?: null) : null;

        $classPlanProvided = array_key_exists('class_plan_uuid', $params);
        $classPlanUuid     = $classPlanProvided ? ((string)$params['class_plan_uuid'] ?: null) : null;

        try {
            $entity  = $this->caseService->update(
                $id,
                $params['title'] ?? null,
                $params['organisation'] ?? null,
                array_map('strval', array_filter(
                    array_intersect_key($params, array_flip(['classification_code', 'sensitivity', 'group_id'])),
                    fn($v) => $v !== null && $v !== ''
                )),
                $this->userId,
                !empty($params['responsible_user_id']) ? $params['responsible_user_id'] : null,
                $insightLevelId,
                $insightLevelProvided,
                $facetUuid,
                $facetUuidProvided,
                $summary,
                $summaryProvided,
                $classPlanUuid,
                $classPlanProvided,
            );

            $orgName     = $this->organisationMapper->findByUuid($entity->getOrgUuid())?->getOrgName() ?? '';
            $profileInfo = $this->resolveProfileInfo($entity);
            $statusInfo  = $this->resolveStatusInfo($entity->getStatusId());
            $canWrite    = $this->caseService->canWriteCase($id, $this->userId);
            $ilName      = $this->resolveInsightLevelName($entity->getInsightLevelId());
            $ctName      = $this->resolveCasetypeName($entity->getCasetypeId());
            $facet       = $this->resolveFacet($entity->getClassificationFacetUuid());
            return new DataResponse(['case' => $this->serializeCase($entity, $orgName, $profileInfo['class_subject'], $profileInfo['sensitivity'], $statusInfo, $canWrite, $ilName, $facet, $ctName, $profileInfo['group'], $this->resolveClassPlan($entity->getClassPlanUuid()))]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        } catch (NotFoundException $e) {
            return new DataResponse(
                ['error' => 'Case not found'],
                Http::STATUS_NOT_FOUND
            );
        }
    }

    /**
     * Change case status.
     *
     * Request body (JSON):
     *   status_id — Required. Integer status ID (1=Open, 2=Closed, 3=Archived)
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/cases/{id}/status')]
    public function changeStatus(int $id): DataResponse {
        $params = $this->getJsonBody();

        if (empty($params['status_id'])) {
            return new DataResponse(
                ['error' => "Field 'status_id' is required"],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $entity      = $this->caseService->changeStatus($id, (int)$params['status_id'], $this->userId);
            $orgName     = $this->organisationMapper->findByUuid($entity->getOrgUuid())?->getOrgName() ?? '';
            $profileInfo = $this->resolveProfileInfo($entity);
            $statusInfo  = $this->resolveStatusInfo($entity->getStatusId());
            $canWrite    = $this->caseService->canWriteCase($id, $this->userId);
            $ilName      = $this->resolveInsightLevelName($entity->getInsightLevelId());
            $ctName      = $this->resolveCasetypeName($entity->getCasetypeId());
            $facet       = $this->resolveFacet($entity->getClassificationFacetUuid());
            return new DataResponse(['case' => $this->serializeCase($entity, $orgName, $profileInfo['class_subject'], $profileInfo['sensitivity'], $statusInfo, $canWrite, $ilName, $facet, $ctName, $profileInfo['group'], $this->resolveClassPlan($entity->getClassPlanUuid()))]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        } catch (NotFoundException $e) {
            return new DataResponse(
                ['error' => 'Case not found'],
                Http::STATUS_NOT_FOUND
            );
        }
    }

    /**
     * Get the full hierarchy tree for a case.
     *
     * Returns null tree when the case is not part of any hierarchy.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/cases/{id}/hierarchy')]
    public function hierarchy(int $id): DataResponse {
        try {
            $tree = $this->caseService->getHierarchy($id, $this->userId);
            return new DataResponse(['tree' => $tree]);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Get case count statistics grouped by organisation and year.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/cases/stats')]
    public function stats(): DataResponse {
        $stats = $this->caseService->getStats($this->userId);
        return new DataResponse(['stats' => $stats]);
    }

    /**
     * List all direct-access grants for a case.
     *
     * Includes the responsible user as the first entry (marked is_responsible=true)
     * and all rows from opencase_case_users after that.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/cases/{id}/grants')]
    public function listGrants(int $id): DataResponse {
        try {
            $grants = $this->caseService->listGrants($id, $this->userId);
            $case   = $this->caseService->get($id, $this->userId);

            $responsibleUid  = $case->getResponsibleUserId();
            $responsibleUser = $responsibleUid ? $this->userManager->get($responsibleUid) : null;

            $caseworkerUids = array_map(
                fn($cw) => $cw->getUserId(),
                $this->caseWorkerMapper->findByCase($id)
            );
            $caseworkerSet = array_flip($caseworkerUids);

            $responsible = $responsibleUid ? [[
                'user_id'        => $responsibleUid,
                'display_name'   => $responsibleUser?->getDisplayName() ?? $responsibleUid,
                'is_responsible' => true,
                'is_caseworker'  => false,
                'can_write'      => true,
                'granted_by'     => null,
                'granted_at'     => null,
                'expires_at'     => null,
                'is_expired'     => false,
            ]] : [];

            return new DataResponse([
                'grants' => array_merge(
                    $responsible,
                    array_map(fn($g) => $this->serializeGrant($g, $caseworkerSet), $grants)
                ),
            ]);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Grant (or update) direct access for a user.
     *
     * Request body (JSON):
     *   user_id    — Required. NC user ID to grant access to
     *   can_write  — Optional bool (default false = read-only)
     *   expires_at — Optional ISO-8601 date string (null = permanent)
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/cases/{id}/grants')]
    public function grantAccess(int $id): DataResponse {
        $params = $this->getJsonBody();

        if (empty($params['user_id'])) {
            return new DataResponse(
                ['error' => "Field 'user_id' is required"],
                Http::STATUS_BAD_REQUEST
            );
        }

        $expiresAt = null;
        if (!empty($params['expires_at'])) {
            try {
                $expiresAt = new \DateTime($params['expires_at']);
            } catch (\Exception $e) {
                return new DataResponse(
                    ['error' => 'Invalid expires_at date'],
                    Http::STATUS_BAD_REQUEST
                );
            }
        }

        try {
            $grant = $this->caseService->grantAccess(
                $id,
                $params['user_id'],
                (bool)($params['can_write'] ?? false),
                $expiresAt,
                $this->userId,
            );
            return new DataResponse(['grant' => $this->serializeGrant($grant)], Http::STATUS_CREATED);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }
    }

    /**
     * Revoke a user's direct access grant.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/cases/{id}/grants/{grantUserId}')]
    public function revokeAccess(int $id, string $grantUserId): DataResponse {
        try {
            $this->caseService->revokeAccess($id, $grantUserId, $this->userId);
            return new DataResponse([]);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }
    }

    /**
     * List users who have access to the access profile assigned to this case.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/cases/{id}/profile-users')]
    public function listProfileUsers(int $id): DataResponse {
        try {
            $case = $this->caseService->get($id, $this->userId);
            $profileId = $case->getAccessProfileId();

            if (!$profileId) {
                return new DataResponse(['users' => [], 'count' => 0]);
            }

            $assignments = $this->userAccessMapper->getUsersForProfile($profileId);

            $users = array_map(function ($a) {
                $ncUser   = $this->userManager->get($a->getUserId());
                $userInfo = $this->userInfoMapper->findByUserId($a->getUserId());
                return [
                    'user_id'      => $a->getUserId(),
                    'username'     => $userInfo ? $userInfo->getUsername() : $a->getUserId(),
                    'display_name' => $ncUser?->getDisplayName() ?? ($userInfo ? $userInfo->getPersonname() : $a->getUserId()),
                    'access_level' => $a->getAccessLevel(),
                ];
            }, $assignments);

            return new DataResponse(['users' => $users, 'count' => count($users)]);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Get distinct organisation names accessible to the current user,
     * derived from their access profiles.
     */
    #[NoAdminRequired]
    public function organisations(): DataResponse {
        $orgs = $this->caseService->getAccessibleOrganisations($this->userId);
        return new DataResponse(['organisations' => $orgs]);
    }

    /**
     * The access profile type and, with Grupper, the groups a case can be
     * given (all registered access groups; can_write marks the ones the
     * current user can write to).
     */
    #[NoAdminRequired]
    public function accessGroups(): DataResponse {
        $type = $this->accessProfileTypeResolver->activeKey();
        if ($type !== AccessProfile::TYPE_GROUP) {
            return new DataResponse(['accessprofile_type' => $type, 'groups' => []]);
        }
        $writeProfileIds = $this->userAccessMapper->getWriteProfileIdsForUser((string)$this->userId);
        return new DataResponse([
            'accessprofile_type' => $type,
            'groups'             => $this->groupAccessProfileType->listGroupProfiles($writeProfileIds),
        ]);
    }

    /**
     * Search all active organisations by name.
     *
     * Used by the organisation search-dropdown on the case create/edit form.
     * Returns up to 20 results matching the query string.
     *
     * @param string $q Search term (minimum 1 character)
     */
    #[NoAdminRequired]
    public function searchOrganisations(string $q = ''): DataResponse {
        $q = trim($q);
        if ($q === '') {
            // Return first 20 alphabetically when no query is given
            $entities = array_slice($this->organisationMapper->findAllActive(), 0, 20);
        } else {
            $entities = $this->organisationMapper->searchByName($q);
        }

        $results = array_map(fn($o) => [
            'id'    => $o->getOrgName(),
            'label' => $o->getOrgName(),
            'uuid'  => $o->getOrgUuid(),
        ], $entities);

        return new DataResponse(['organisations' => $results]);
    }

    /**
     * List all active classification subjects (KLE emneord).
     */
    #[NoAdminRequired]
    public function classificationSubjects(): DataResponse {
        $subjects = $this->classificationSubjectMapper->findAllActive();
        return new DataResponse([
            'classification_subjects' => array_map(fn($s) => [
                'uuid'  => $s->getUuid(),
                'code'  => $s->getCode(),
                'title' => $s->getTitle(),
            ], $subjects),
        ]);
    }

    /**
     * List all active classification facets (KLE handlingsfacetter).
     */
    #[NoAdminRequired]
    public function classificationFacets(): DataResponse {
        $facets = $this->classificationFacetRepository->findAllActive();
        return new DataResponse([
            'classification_facets' => array_map(fn($f) => [
                'uuid'  => $f['uuid'],
                'code'  => $f['code'],
                'title' => $f['title'],
            ], $facets),
        ]);
    }

    /**
     * List all active sensitivity levels.
     */
    #[NoAdminRequired]
    public function sensitivities(): DataResponse {
        $levels = $this->sensitivityMapper->findAllActive();
        return new DataResponse([
            'sensitivities' => array_map(fn($s) => [
                'uuid'  => $s->getUuid(),
                'key'   => $s->getKey(),
                'title' => $s->getTitle(),
            ], $levels),
        ]);
    }

    /**
     * List all insight levels in the user's language.
     */
    #[NoAdminRequired]
    public function insightLevels(): DataResponse {
        $levels = $this->insightLevelMapper->findAllForLanguage($this->userLanguage(), 'en', true);
        return new DataResponse([
            'insight_levels' => array_map(fn($l) => [
                'id'          => $l->getId(),
                'name'        => $l->getName(),
                'description' => $l->getDescription(),
            ], $levels),
        ]);
    }

    /**
     * List all case statuses in the user's language.
     */
    #[NoAdminRequired]
    public function caseStatuses(): DataResponse {
        $statuses = $this->caseStatusMapper->findAllForLanguage($this->userLanguage(), 'en', true);
        return new DataResponse([
            'case_statuses' => array_map(fn($s) => [
                'id'        => $s->getId(),
                'name'      => $s->getName(),
                'is_closed' => (bool)$s->getIsClosed(),
                'expired'   => (bool)$s->getExpired(),
            ], $statuses),
        ]);
    }

    /**
     * List all case types in the user's language.
     */
    #[NoAdminRequired]
    public function caseTypes(): DataResponse {
        $types = $this->caseTypeMapper->findAllForLanguage($this->userLanguage(), 'en', true);
        return new DataResponse([
            'case_types' => array_map(fn($t) => [
                'id'                  => $t->getId(),
                'name'                => $t->getName(),
                'primary_participant' => $t->getPrimaryParticipant(),
                'expired'             => (bool)$t->getExpired(),
            ], $types),
        ]);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Normalizes the `custom_fields[N][key|value|value_from|value_to|type]`
     * query params (a PHP-native nested array from the query string) into
     * a clean list of filters for CaseService::list(). date-type fields
     * use value_from/value_to (a range) instead of value.
     *
     * @return array<int, array{key: ?string, value: ?string, value_from: ?string, value_to: ?string, type: ?string}>
     */
    private function parseCustomFieldFilters(mixed $raw): array {
        if (!is_array($raw)) {
            return [];
        }
        $filters = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $filters[] = [
                'key'        => isset($entry['key']) ? (string)$entry['key'] : null,
                'value'      => isset($entry['value']) ? (string)$entry['value'] : null,
                'value_from' => isset($entry['value_from']) ? (string)$entry['value_from'] : null,
                'value_to'   => isset($entry['value_to']) ? (string)$entry['value_to'] : null,
                'type'       => isset($entry['type']) ? (string)$entry['type'] : null,
            ];
        }
        return $filters;
    }

    /**
     * Resolve a case's classification subject and sensitivity (its own with
     * Grupper + KLE, else its access profile's) and group (Grupper) in one shot.
     *
     * @return array{class_subject: ?ClassificationSubject, sensitivity: ?Sensitivity, group: ?array{group_id: string, group_name: string}}
     */
    private function resolveProfileInfo(CaseEntity $case): array {
        $profileId = $case->getAccessProfileId();
        $profile   = $this->accessProfileMapper->findById($profileId);
        $classSubjectUuid = $case->effectiveClassSubjectUuid($profile);
        $sensitivityUuid  = $case->effectiveSensitivityUuid($profile);
        return [
            'class_subject' => $classSubjectUuid !== null ? $this->classificationSubjectMapper->findByUuid($classSubjectUuid) : null,
            'sensitivity'   => $sensitivityUuid !== null ? $this->sensitivityMapper->findByUuid($sensitivityUuid) : null,
            'group'         => $profile !== null ? ($this->groupAccessProfileType->describeGroups([$profileId])[$profileId] ?? null) : null,
        ];
    }

    /**
     * Resolve an Emneplan item UUID to the item (or null).
     */
    private function resolveClassPlan(?string $uuid): ?ClassificationPlan {
        return $uuid ? $this->classificationPlanMapper->findByUuid($uuid) : null;
    }

    /**
     * Resolve a classification facet UUID to its row (or null).
     */
    private function resolveFacet(?string $uuid): ?array {
        if ($uuid === null) {
            return null;
        }
        return $this->classificationFacetRepository->find($uuid);
    }

    /**
     * Resolve an insight level ID to its localized name.
     */
    private function resolveInsightLevelName(?int $insightLevelId): string {
        if ($insightLevelId === null) {
            return '';
        }
        $map = $this->insightLevelMapper->getNamesByIds([$insightLevelId], $this->userLanguage());
        return $map[$insightLevelId] ?? '';
    }

    /**
     * Resolve a case type ID to its localized name.
     */
    private function resolveCasetypeName(?int $casetypeId): string {
        if ($casetypeId === null) {
            return '';
        }
        $map = $this->caseTypeMapper->getNamesByIds([$casetypeId], $this->userLanguage());
        return $map[$casetypeId] ?? '';
    }

    /**
     * Resolve a disposal code ID to its name.
     */
    private function resolveDisposalcodeName(?int $disposalcodeId): string {
        if ($disposalcodeId === null) {
            return '';
        }
        return $this->disposalcodeNames[$disposalcodeId]
            ??= $this->disposalCodeMapper->findById($disposalcodeId)?->getName() ?? '';
    }

    /**
     * Resolve a status ID to its localized name, CSS class, and is_closed flag.
     *
     * @return array{name: string, css_class: string, is_closed: bool}
     */
    private function resolveStatusInfo(int $statusId): array {
        $map = $this->caseStatusMapper->getNamesByIds([$statusId], $this->userLanguage());
        return [
            'name'      => $map[$statusId]['name'] ?? '',
            'css_class' => $this->statusCssClass($statusId),
            'is_closed' => $map[$statusId]['is_closed'] ?? false,
        ];
    }

    /**
     * Return the user's preferred Nextcloud language code (e.g. 'da', 'en').
     *
     * Uses the core 'lang' user config directly so the result matches the
     * user's UI language regardless of whether the opencase app has its own
     * translation files for that language.
     * Normalised to a plain 2-char code to match the casestatus table.
     */
    private function userLanguage(): string {
        $lang = $this->config->getUserValue($this->userId ?? '', 'core', 'lang', 'en');
        // Strip country suffix: 'da_DK' → 'da', 'en_GB' → 'en'
        return substr($lang, 0, 2) ?: 'en';
    }

    /** Map numeric status ID to a stable CSS class string for the frontend badge. */
    private function statusCssClass(int $statusId): string {
        return match ($statusId) {
            1 => 'open',
            2 => 'closed',
            3 => 'archived',
            default => 'unknown',
        };
    }

    private function serializeGrant(\OCA\OpenCase\Db\CaseUserEntity $grant, array $caseworkerSet = []): array {
        $user = $this->userManager->get($grant->getUserId());
        return [
            'user_id'        => $grant->getUserId(),
            'display_name'   => $user?->getDisplayName() ?? $grant->getUserId(),
            'is_responsible' => false,
            'is_caseworker'  => isset($caseworkerSet[$grant->getUserId()]),
            'can_write'      => $grant->getCanWrite(),
            'granted_by'     => $grant->getGrantedBy(),
            'granted_at'     => $grant->getGrantedAt()?->format('c'),
            'expires_at'     => $grant->getExpiresAt()?->format('c'),
            'is_expired'     => $grant->isExpired(),
        ];
    }

    private function serializeCase(
        $entity,
        string $orgName = '',
        ?ClassificationSubject $classSubject = null,
        ?Sensitivity $sensitivity = null,
        ?array $statusInfo = null,
        bool $canWrite = false,
        string $insightLevelName = '',
        ?array $facet = null,
        string $casetypeName = '',
        ?array $group = null,
        ?ClassificationPlan $classPlan = null,
    ): array {
        $responsibleUid  = $entity->getResponsibleUserId();
        $responsibleUser = $responsibleUid ? $this->userManager->get($responsibleUid) : null;
        $createdByUid    = $entity->getCreatedBy();
        $createdByUser   = $createdByUid ? $this->userManager->get($createdByUid) : null;
        $updatedByUid    = $entity->getUpdatedBy() ?: $createdByUid;
        $updatedByUser   = $updatedByUid ? $this->userManager->get($updatedByUid) : null;

        return [
            'id'                                => $entity->getId(),
            'uuid'                              => $entity->getUuid(),
            'case_number'                       => $entity->getCaseNumber(),
            'title'                             => $entity->getTitle(),
            'access_profile_id'                 => $entity->getAccessProfileId(),
            'access_profile_type'               => $group !== null ? AccessProfile::TYPE_GROUP : AccessProfile::TYPE_FK,
            'group_id'                          => $group['group_id'] ?? null,
            'group_name'                        => $group['group_name'] ?? '',
            'organisation'                      => $orgName,
            'classification_code'               => $classSubject?->getCode() ?? '',
            'classification_title'              => $classSubject?->getTitle() ?? '',
            'sensitivity_key'                   => $sensitivity?->getKey() ?? '',
            'sensitivity_title'                 => $sensitivity?->getTitle() ?? '',
            'year'                              => $entity->getYear(),
            'status_id'                         => $entity->getStatusId(),
            'status_name'                       => $statusInfo['name'] ?? '',
            'status_class'                      => $statusInfo['css_class'] ?? $this->statusCssClass($entity->getStatusId()),
            'status_is_closed'                  => $statusInfo['is_closed'] ?? false,
            'created_at'                        => $entity->getCreatedAt()?->format('c'),
            'updated_at'                        => $entity->getUpdatedAt()?->format('c'),
            'updated_in_sapa'                   => $entity->getUpdatedInSapa()?->format('c'),
            'created_by'                        => $entity->getCreatedBy(),
            'created_by_display_name'           => $createdByUser?->getDisplayName() ?? $createdByUid ?? '',
            'updated_by'                        => $updatedByUid,
            'updated_by_display_name'           => $updatedByUser?->getDisplayName() ?? $updatedByUid ?? '',
            'virtual_path'                      => $entity->getCreatedYear() . '/' . $entity->getCreatedMonth() . '/' . $entity->getCreatedDay() . '/' . $entity->getCaseNumber(),
            'responsible_user_id'               => $responsibleUid,
            'responsible_user_display_name'     => $responsibleUser?->getDisplayName() ?? $responsibleUid ?? '',
            'can_write'                         => $canWrite,
            'insight_level_id'                  => $entity->getInsightLevelId(),
            'insight_level_name'                => $insightLevelName,
            'classification_facet_uuid'         => $entity->getClassificationFacetUuid(),
            'classification_facet_code'         => $facet['code'] ?? '',
            'classification_facet_title'        => $facet['title'] ?? '',
            'class_plan_uuid'                   => $entity->getClassPlanUuid(),
            'class_plan_code'                   => $classPlan?->getCode() ?? '',
            'class_plan_title'                  => $classPlan?->getTitle() ?? '',
            'parent_case_id'                    => $entity->getParentCaseId(),
            'is_inbox'                          => $entity->getIsInbox(),
            'casetype_id'                       => $entity->getCasetypeId(),
            'casetype_name'                     => $casetypeName,
            'summary'                           => $entity->getSummary(),
            'disposalcode_id'                   => $entity->getDisposalcodeId(),
            'disposalcode_name'                 => $this->resolveDisposalcodeName($entity->getDisposalcodeId()),
            'closed_date'                       => $entity->getClosedDate()?->format('c'),
            'disposal_date'                     => $entity->getDisposalDate()?->format('c'),
            'deletion_deadline_id'              => $entity->getDeletionDeadlineId(),
            'deletion_date'                     => $entity->getDeletionDate()?->format('c'),
        ];
    }

    private function getJsonBody(): array {
        $body = $this->request->getParams();
        // Nextcloud parses JSON body into params automatically
        return $body;
    }
}
