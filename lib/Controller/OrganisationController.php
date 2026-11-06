<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Service\LogoService;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\RoleService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;

class OrganisationController extends ApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private IDBConnection $db,
        private OrganisationMapper $organisationMapper,
        private LogoService $logoService,
        private RoleService $roleService,
        private IL10N $l10n,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Search active organisations by name.
     * Returns full data including parent org name and member count.
     *
     * Query params:
     *   q — substring match on org_name (required, min 1 char)
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/org/search')]
    public function search(): DataResponse {
        $q = trim($this->request->getParam('q', ''));
        if ($q === '') {
            return new DataResponse(['organisations' => []]);
        }

        $entities = $this->organisationMapper->searchByName($q, 50);

        if (empty($entities)) {
            return new DataResponse(['organisations' => []]);
        }

        // Build a map of all org uuids → names to resolve parent names
        $parentUuids = array_filter(array_unique(
            array_map(fn($e) => $e->getOrgParentUuid(), $entities)
        ));
        $parentNames = empty($parentUuids)
            ? []
            : $this->organisationMapper->getNamesByUuids(array_values($parentUuids));

        // Count members per org
        $orgUuids = array_map(fn($e) => $e->getOrgUuid(), $entities);
        $memberCounts = $this->getMemberCounts($orgUuids);

        $results = array_map(function ($org) use ($parentNames, $memberCounts) {
            $parentUuid = $org->getOrgParentUuid();
            return [
                'org_uuid'        => $org->getOrgUuid(),
                'org_name'        => $org->getOrgName(),
                'org_parent_uuid' => $parentUuid,
                'org_parent_name' => $parentUuid ? ($parentNames[$parentUuid] ?? '') : '',
                'active'          => (bool)$org->getActive(),
                'member_count'    => $memberCounts[$org->getOrgUuid()] ?? 0,
                'email'           => $org->getEmail(),
                'phone'           => $org->getPhone(),
                'location'        => $org->getLocation(),
                'logo_file'       => $org->getLogoFile(),
            ];
        }, $entities);

        return new DataResponse(['organisations' => $results]);
    }

    /**
     * The logo used for an organisation in templates: its own, or else the
     * nearest ancestor's.
     *
     * Response: { has_own_logo, logo_org_uuid, logo_org_name } — the logo
     * fields are null when neither the organisation nor an ancestor has one.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/org/{orgUuid}/logo-info')]
    public function logoInfo(string $orgUuid): DataResponse {
        $org = $this->organisationMapper->findByUuid($orgUuid);
        if ($org === null) {
            return new DataResponse(['error' => 'Organisation not found'], Http::STATUS_NOT_FOUND);
        }
        $logo = $this->logoService->resolve($orgUuid);
        return new DataResponse([
            'has_own_logo'  => $logo !== null && $logo['org_uuid'] === $orgUuid,
            'logo_org_uuid' => $logo['org_uuid'] ?? null,
            'logo_org_name' => $logo['org_name'] ?? null,
        ]);
    }

    /**
     * Download an organisation's own logo (404 when it has none).
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/org/{orgUuid}/logo')]
    public function logo(string $orgUuid): DataDownloadResponse|DataResponse {
        $logo = $this->logoService->getOwnLogo($orgUuid);
        if ($logo === null) {
            return new DataResponse(['error' => 'No logo'], Http::STATUS_NOT_FOUND);
        }
        return new DataDownloadResponse((string)file_get_contents($logo['path']), $logo['filename'], $logo['mime_type']);
    }

    /**
     * Upload (or replace) an organisation's logo. Expects multipart/form-data
     * with "file" — a PNG, JPEG, GIF or SVG image.
     *
     * Requires the Administrator or Template Designer role.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/org/{orgUuid}/logo')]
    public function uploadLogo(string $orgUuid): DataResponse {
        if ($denied = $this->checkCanManageLogos()) {
            return $denied;
        }
        $file = $this->request->getUploadedFile('file');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            $errorMsg = match ($file['error'] ?? UPLOAD_ERR_NO_FILE) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $this->l10n->t('Filen er større end den tilladte uploadstørrelse'),
                UPLOAD_ERR_NO_FILE => $this->l10n->t('Der blev ikke uploadet nogen fil'),
                default => $this->l10n->t('Upload af filen mislykkedes'),
            };
            return new DataResponse(['error' => $errorMsg], Http::STATUS_BAD_REQUEST);
        }
        try {
            $org = $this->logoService->upload($orgUuid, $file['name'], $file['tmp_name']);
            return new DataResponse(['logo_file' => $org->getLogoFile()]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Remove an organisation's own logo (it then uses its parent's again).
     *
     * Requires the Administrator or Template Designer role.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/org/{orgUuid}/logo')]
    public function deleteLogo(string $orgUuid): DataResponse {
        if ($denied = $this->checkCanManageLogos()) {
            return $denied;
        }
        try {
            $this->logoService->delete($orgUuid);
            return new DataResponse(['deleted' => true]);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    private function checkCanManageLogos(): ?DataResponse {
        if ($this->userId === null
            || !($this->roleService->userHasRole($this->userId, 'Administrator')
                || $this->roleService->userHasRole($this->userId, 'Template Designer'))) {
            return new DataResponse(['error' => 'Not permitted'], Http::STATUS_FORBIDDEN);
        }
        return null;
    }

    /**
     * Return employees who are members of a specific organisation.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/org/{orgUuid}/members')]
    public function members(string $orgUuid): DataResponse {
        $qb = $this->db->getQueryBuilder();
        $qb->select(['ui.uuid', 'ui.personname', 'ui.username', 'ui.email', 'ui.phone', 'ui.location', 'uo.role'])
            ->from('opencase_userorgs', 'uo')
            ->innerJoin('uo', 'opencase_userinfo', 'ui',
                $qb->expr()->eq('ui.uuid', 'uo.user_uuid'))
            ->where($qb->expr()->eq('uo.org_uuid', $qb->createNamedParameter($orgUuid)))
            ->orderBy('ui.personname', 'ASC');

        $result = $qb->executeQuery();
        $members = [];
        while ($row = $result->fetch()) {
            $members[] = [
                'uuid'       => $row['uuid'],
                'personname' => $row['personname'],
                'username'   => $row['username'],
                'email'      => $row['email'],
                'phone'      => $row['phone'],
                'location'   => $row['location'],
                'role'       => $row['role'],
            ];
        }
        $result->closeCursor();

        return new DataResponse(['members' => $members]);
    }

    /**
     * @param string[] $orgUuids
     * @return array<string, int>
     */
    private function getMemberCounts(array $orgUuids): array {
        if (empty($orgUuids)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('org_uuid')
            ->addSelect($qb->createFunction('COUNT(*) AS cnt'))
            ->from('opencase_userorgs')
            ->where($qb->expr()->in('org_uuid',
                $qb->createNamedParameter($orgUuids, IQueryBuilder::PARAM_STR_ARRAY)))
            ->groupBy('org_uuid');

        $result = $qb->executeQuery();
        $counts = [];
        while ($row = $result->fetch()) {
            $counts[$row['org_uuid']] = (int)$row['cnt'];
        }
        $result->closeCursor();
        return $counts;
    }
}
