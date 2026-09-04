<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\CaseEstateMapper;
use OCA\OpenCase\Db\CaseStatusMapper;
use OCA\OpenCase\Db\EstateRoleMapper;
use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\Datafordeler\EstateClient;
use OCA\OpenCase\Service\EstateLinkService;
use OCA\OpenCase\Service\EstateReadService;
use OCA\OpenCase\Service\EstateSerializer;
use OCA\OpenCase\Service\PermissionService;
use OCA\OpenCase\Service\TransactionLogService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\L10N\IFactory;

/**
 * REST API controller for estate (fast ejendom) look-ups.
 *
 * Requires enterprise_version=1 (Datafordeler access) for a live lookup by
 * BFE-nummer or address — search() and searchByAddress() both fall back to
 * whatever's already stored locally when it isn't available.
 *
 * Routes:
 *   GET  /api/v1/estate/search?bfenumber={bfenumber}          — Search by BFE-nummer
 *   GET  /api/v1/estate/search-address?streetname=&housenumber=&limit=&offset= — Search by address, paginated
 *   GET  /api/v1/estate/cases?bfenumber={bfenumber}           — Cases linked to the estate
 *   POST /api/v1/estate                                        — Manually create a new estate (no case link)
 *   PUT  /api/v1/estate/{estateId}                             — Update an existing estate (type is immutable)
 *   POST /api/v1/cases/{caseId}/estate                        — Link (find-or-create) an estate to a case
 *   GET  /api/v1/cases/{caseId}/estates                       — Estates linked to a case
 *   GET  /api/v1/estate/{estateId}                             — A locally-stored estate's full detail
 */
class EstateController extends ApiController {

    use CaseAccessGuard;

    public function __construct(
        string $appName,
        IRequest $request,
        private CaseEstateMapper $caseEstateMapper,
        private CaseStatusMapper $caseStatusMapper,
        private EstateRoleMapper $estateRoleMapper,
        private OrganisationMapper $organisationMapper,
        private EstateLinkService $estateLinkService,
        private EstateReadService $estateReadService,
        private EstateSerializer $estateSerializer,
        private PermissionService $permissionService,
        private TransactionLogService $transactionLogService,
        private IUserManager $userManager,
        private IFactory $l10nFactory,
        private Configuration $configuration,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    private function getUserLanguage(): string {
        if ($this->userId === null) {
            return 'en';
        }
        $user = $this->userManager->get($this->userId);
        return $user ? $this->l10nFactory->getUserLanguage($user) : 'en';
    }

    private function statusCssClass(int $statusId): string {
        return match ($statusId) {
            1 => 'open',
            2 => 'closed',
            3 => 'archived',
            default => 'unknown',
        };
    }

    /**
     * Search for an estate by BFE-nummer.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/estate/search')]
    public function search(): DataResponse {
        $bfenumber = trim((string)$this->request->getParam('bfenumber', ''));
        if ($bfenumber === '' || !ctype_digit($bfenumber)) {
            return new DataResponse(['estate' => null]);
        }

        // EstateClient is an Enterprise-only component (not present in the
        // public App Store package) — guard against the class simply not
        // being present on disk (also covers a misconfigured instance where
        // the flag is on but the Enterprise package was never installed).
        $enterpriseEnabled = $this->configuration->getConfigValue('enterprise_version', '0') === '1';
        $estateClientAvailable = $enterpriseEnabled && class_exists(EstateClient::class);

        if (!$estateClientAvailable) {
            // No Datafordeler access on this instance — fall back to
            // whatever's already stored locally for this BFE-nummer (e.g.
            // an estate linked to a case previously).
            $estate = $this->estateReadService->findByBfenummer((int)$bfenumber);

            if ($this->userId !== null) {
                $this->transactionLogService->log($this->userId, 'search_estate_local', ['bfenumber' => $bfenumber]);
            }

            return new DataResponse(['estate' => $estate]);
        }

        try {
            $estateClient = \OC::$server->get(EstateClient::class);
            $estateData = $estateClient->fetchByBFENumber($bfenumber);
        } catch (\Throwable $e) {
            return new DataResponse(['estate' => null, 'error' => $e->getMessage()]);
        }

        if ($this->userId !== null) {
            $this->transactionLogService->log($this->userId, 'search_estate', ['bfenumber' => $bfenumber]);
        }

        return new DataResponse(['estate' => $estateData !== null ? $this->estateSerializer->toArray($estateData) : null]);
    }

    /**
     * Search for estates by street name (and optionally house number) —
     * returns a list, since an address can resolve to several distinct
     * estates (e.g. an apartment building's individual units).
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/estate/search-address')]
    public function searchByAddress(): DataResponse {
        $streetname = trim((string)$this->request->getParam('streetname', ''));
        $housenumber = trim((string)$this->request->getParam('housenumber', ''));
        $limit = min((int)$this->request->getParam('limit', '50'), 1000);
        $offset = max((int)$this->request->getParam('offset', '0'), 0);

        if ($streetname === '') {
            return new DataResponse(['estates' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset]);
        }

        $enterpriseEnabled = $this->configuration->getConfigValue('enterprise_version', '0') === '1';
        $estateClientAvailable = $enterpriseEnabled && class_exists(EstateClient::class);

        if (!$estateClientAvailable) {
            // No Datafordeler access on this instance — fall back to
            // whatever's already stored locally (estates linked to a case
            // previously), matched the same way by street name/house number.
            $estates = $this->estateReadService->searchByAddress($streetname, $housenumber);

            if ($this->userId !== null) {
                $this->transactionLogService->log($this->userId, 'search_estate_address_local', [
                    'streetname'  => $streetname,
                    'housenumber' => $housenumber,
                ]);
            }

            $total = count($estates);
            $page = array_slice($estates, $offset, $limit);

            return new DataResponse([
                'estates' => $page,
                'total'   => $total,
                'limit'   => $limit,
                'offset'  => $offset,
            ]);
        }

        try {
            $estateClient = \OC::$server->get(EstateClient::class);
            $estates = $estateClient->searchByAddress($streetname, $housenumber);
        } catch (\Throwable $e) {
            return new DataResponse(['estates' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset, 'error' => $e->getMessage()]);
        }

        if ($this->userId !== null) {
            $this->transactionLogService->log($this->userId, 'search_estate_address', [
                'streetname'  => $streetname,
                'housenumber' => $housenumber,
            ]);
        }

        $total = count($estates);
        $page = array_slice($estates, $offset, $limit);

        return new DataResponse([
            'estates' => array_map(fn ($e) => $this->estateSerializer->toArray($e), $page),
            'total'   => $total,
            'limit'   => $limit,
            'offset'  => $offset,
        ]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/estate/cases')]
    public function cases(): DataResponse {
        $bfenumber = trim((string)$this->request->getParam('bfenumber', ''));
        if ($bfenumber === '' || !ctype_digit($bfenumber)) {
            return new DataResponse(['cases' => []]);
        }

        $rows = $this->caseEstateMapper->findRawCasesByBfenummer((int)$bfenumber);
        $lang = $this->getUserLanguage();

        $statusIds   = array_unique(array_column($rows, 'status_id'));
        $statusNames = $this->caseStatusMapper->getNamesByIds(
            array_map('intval', $statusIds),
            $lang
        );

        $orgUuids = array_unique(array_filter(array_column($rows, 'org_uuid')));
        $orgNames = $this->organisationMapper->getNamesByUuids($orgUuids);

        $cases = array_map(function (array $row) use ($statusNames, $orgNames): array {
            $sid    = (int)$row['status_id'];
            $sEntry = $statusNames[$sid] ?? [];
            return [
                'id'           => (int)$row['id'],
                'case_number'  => $row['case_number'],
                'title'        => $row['title'],
                'organisation' => $orgNames[$row['org_uuid']] ?? '',
                'status_class' => $this->statusCssClass($sid),
                'status_name'  => $sEntry['name'] ?? '',
                'updated_at'   => $row['updated_at'],
            ];
        }, $rows);

        return new DataResponse(['cases' => $cases]);
    }

    /**
     * Manually create a new local estate, not linked to any case — the
     * community/public-release path for registering an estate without
     * Datafordeler access (enterprise_version=0), driven by the "Ejendom"
     * item under the "Opret" menu's "Ny Ejendom" group.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/estate')]
    public function create(): DataResponse {
        $params = $this->request->getParams();
        $estate = $params['estate'] ?? null;
        if (!is_array($estate)) {
            return new DataResponse(['error' => "Field 'estate' is required"], Http::STATUS_BAD_REQUEST);
        }

        try {
            $estateId = $this->estateLinkService->createOrFindEstate($estate);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if ($this->userId !== null) {
            $this->transactionLogService->log($this->userId, 'create_estate', [
                'bfenummer' => $estate['bfenummer'] ?? null,
                'type'      => $estate['type'] ?? null,
            ]);
        }

        return new DataResponse(['estate_id' => $estateId], Http::STATUS_CREATED);
    }

    /**
     * Update an existing local estate's editable fields (never its type) —
     * only available when enterprise_version=0, matching the "Rediger"
     * button on the estate detail page, which is itself only shown then.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/estate/{estateId}')]
    public function update(int $estateId): DataResponse {
        $enterpriseEnabled = $this->configuration->getConfigValue('enterprise_version', '0') === '1';
        if ($enterpriseEnabled) {
            return new DataResponse(['error' => 'Manuel redigering er ikke tilgængelig i Enterprise-udgaven'], Http::STATUS_FORBIDDEN);
        }

        $params = $this->request->getParams();
        $estate = $params['estate'] ?? null;
        if (!is_array($estate)) {
            return new DataResponse(['error' => "Field 'estate' is required"], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->estateLinkService->updateEstate($estateId, $estate);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if ($this->userId !== null) {
            $this->transactionLogService->log($this->userId, 'update_estate', ['estate_id' => $estateId]);
        }

        return new DataResponse(['estate_id' => $estateId]);
    }

    /**
     * Link an estate to a case, creating the local opencase_estate row (and
     * its aggregated_estates/estate_addresses/land_plots/apartments/
     * buildings_land_by_other rows) if a matching BFE-nummer + type doesn't
     * already exist locally.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/cases/{caseId}/estate')]
    public function linkToCase(int $caseId): DataResponse {
        if ($denied = $this->denyUnlessCaseWrite($caseId)) {
            return $denied;
        }

        $params = $this->request->getParams();
        $estate = $params['estate'] ?? null;
        if (!is_array($estate)) {
            return new DataResponse(['error' => "Field 'estate' is required"], Http::STATUS_BAD_REQUEST);
        }
        $roleId = isset($params['role_id']) && $params['role_id'] !== '' ? (int)$params['role_id'] : null;

        try {
            $estateId = $this->estateLinkService->linkEstateToCase($caseId, $estate, $roleId);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if ($this->userId !== null) {
            $this->transactionLogService->log($this->userId, 'link_estate_case', [
                'case_id'   => $caseId,
                'bfenummer' => $estate['bfenummer'] ?? null,
            ]);
        }

        return new DataResponse(['estate_id' => $estateId], Http::STATUS_CREATED);
    }

    /**
     * List the estates linked to a case, with location address pre-resolved
     * (apartment/building own address, falling back to the aggregated
     * estate's) for the Ejendomme tab's row list.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/cases/{caseId}/estates')]
    public function caseEstates(int $caseId): DataResponse {
        if ($denied = $this->denyUnlessCaseRead($caseId)) {
            return $denied;
        }

        $rows = $this->caseEstateMapper->findRawEstatesByCase($caseId);

        $lang = $this->getUserLanguage();
        $roleNames = $this->estateRoleMapper->getNameMap($lang);
        if (empty($roleNames)) {
            $roleNames = $this->estateRoleMapper->getNameMap('en');
        }

        $estates = array_map(function (array $row) use ($roleNames): array {
            $roleId = $row['role_id'] !== null ? (int)$row['role_id'] : null;
            return [
                'estate_id'        => (int)$row['estate_id'],
                'type'             => $row['type'],
                'bfenummer'        => $row['bfenummer'] !== null ? (int)$row['bfenummer'] : null,
                'role_name'        => $roleId !== null ? ($roleNames[$roleId] ?? '') : '',
                'location_address' => $row['apt_location_address'] ?: $row['bld_location_address'] ?: $row['agg_location_address'],
            ];
        }, $rows);

        return new DataResponse(['estates' => $estates]);
    }

    /**
     * Full detail for a locally-stored estate (already linked to at least
     * one case) — same shape as search(), but reconstructed from the local
     * tables instead of a live Datafordeler call.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/estate/{estateId}')]
    public function show(int $estateId): DataResponse {
        $estate = $this->estateReadService->getEstateById($estateId);
        if ($estate === null) {
            return new DataResponse(['error' => 'Estate not found'], Http::STATUS_NOT_FOUND);
        }

        return new DataResponse(['estate' => $estate]);
    }
}
