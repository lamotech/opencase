<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\CaseParticipantMapper;
use OCA\OpenCase\Db\CaseStatusMapper;
use OCA\OpenCase\Db\ContactRoleMapper;
use OCA\OpenCase\Db\DocumentContactMapper;
use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Db\ParticipantRoleMapper;
use OCA\OpenCase\Service\CompanySearchService;
use OCA\OpenCase\Service\PermissionService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\L10N\IFactory;

/**
 * REST API controller for company look-ups.
 *
 * When enterprise_version=1, search() queries Datafordeler (CVR service).
 * When enterprise_version=0 (no Datafordeler access), search() instead
 * searches existing case participants / document contacts locally.
 *
 * Routes:
 *   GET /api/v1/company/search?cvr={cvr}   — Search by CVR number
 *   GET /api/v1/company/search?name={name} — Search by company name
 */
class CompanyController extends ApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private CaseParticipantMapper $participantMapper,
        private DocumentContactMapper $contactMapper,
        private CaseStatusMapper $caseStatusMapper,
        private OrganisationMapper $organisationMapper,
        private ParticipantRoleMapper $participantRoleMapper,
        private ContactRoleMapper $contactRoleMapper,
        private CompanySearchService $searchService,
        private PermissionService $permissionService,
        private IUserManager $userManager,
        private IFactory $l10nFactory,
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

    /**
     * The rows on cases the user can read.
     *
     * @param array<int, array{id: int|string}> $rows
     * @return array<int, array{id: int|string}>
     */
    private function readableCaseRows(array $rows): array {
        $readable = array_flip($this->permissionService->filterReadableCases(
            (string)$this->userId,
            array_values(array_unique(array_map(fn(array $row) => (int)$row['id'], $rows))),
        ));
        return array_values(array_filter($rows, fn(array $row) => isset($readable[(int)$row['id']])));
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
     * Search for companies.
     *
     * Provide either ?cvr= or ?name= as query parameter.
     * Returns an array of matching companies (may be empty).
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/company/search')]
    public function search(): DataResponse {
        $result = $this->searchService->search([
            'cvr' => (string)$this->request->getParam('cvr', ''),
            'name' => (string)$this->request->getParam('name', ''),
            'mode' => (string)$this->request->getParam('mode', ''),
        ], $this->userId);

        return new DataResponse($result['error'] === null
            ? ['companies' => $result['results']]
            : ['companies' => [], 'error' => $result['error']]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/company/cases')]
    public function cases(): DataResponse {
        $cvr     = trim((string)$this->request->getParam('cvr', ''));
        $pnumber = trim((string)$this->request->getParam('pnumber', '')) ?: null;
        if ($cvr === '') {
            return new DataResponse(['cases' => []]);
        }

        $rows  = $this->readableCaseRows($this->participantMapper->findRawCasesByCpr($cvr, $pnumber));
        $lang  = $this->getUserLanguage();

        $statusIds   = array_unique(array_column($rows, 'status_id'));
        $statusNames = $this->caseStatusMapper->getNamesByIds(
            array_map('intval', $statusIds),
            $lang
        );

        $orgUuids  = array_unique(array_filter(array_column($rows, 'org_uuid')));
        $orgNames  = $this->organisationMapper->getNamesByUuids($orgUuids);
        $roleNames = $this->participantRoleMapper->getNameMap($lang);
        if (empty($roleNames)) {
            $roleNames = $this->participantRoleMapper->getNameMap('en');
        }

        $cases = array_map(function (array $row) use ($statusNames, $orgNames, $roleNames): array {
            $sid    = (int)$row['status_id'];
            $sEntry = $statusNames[$sid] ?? [];
            $rid    = (int)$row['participantrole_id'];
            return [
                'id'           => (int)$row['id'],
                'case_number'  => $row['case_number'],
                'title'        => $row['title'],
                'organisation' => $orgNames[$row['org_uuid']] ?? '',
                'status_class' => $this->statusCssClass($sid),
                'status_name'  => $sEntry['name'] ?? '',
                'updated_at'   => $row['updated_at'],
                'role_name'    => $roleNames[$rid] ?? '',
            ];
        }, $rows);

        return new DataResponse(['cases' => $cases]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/company/documents')]
    public function documents(): DataResponse {
        $cvr     = trim((string)$this->request->getParam('cvr', ''));
        $pnumber = trim((string)$this->request->getParam('pnumber', '')) ?: null;
        if ($cvr === '') {
            return new DataResponse(['documents' => []]);
        }

        $rows      = array_values(array_filter(
            $this->contactMapper->findRawDocumentsByCpr($cvr, $pnumber),
            fn(array $row) => $this->permissionService->userHasReadAccessToDocument((string)$this->userId, (int)$row['id']),
        ));
        $lang      = $this->getUserLanguage();
        $roleNames = $this->contactRoleMapper->getNameMap($lang);
        if (empty($roleNames)) {
            $roleNames = $this->contactRoleMapper->getNameMap('en');
        }

        $documents = array_map(function (array $row) use ($roleNames): array {
            $rid = (int)$row['contactrole_id'];
            return [
                'id'            => (int)$row['id'],
                'title'         => $row['title'],
                'document_type' => $row['document_type'],
                'document_date' => $row['document_date'],
                'updated_at'    => $row['updated_at'],
                'case_id'       => (int)$row['case_id'],
                'case_number'   => $row['case_number'],
                'organisation'  => $row['org_name'] ?? '',
                'role_name'     => $roleNames[$rid] ?? '',
            ];
        }, $rows);

        return new DataResponse(['documents' => $documents]);
    }
}
