<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\CaseParticipantMapper;
use OCA\OpenCase\Db\CaseStatusMapper;
use OCA\OpenCase\Db\ContactRoleMapper;
use OCA\OpenCase\Db\DocumentContactMapper;
use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Db\ParticipantRoleMapper;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\Datafordeler\CompanyClient;
use OCA\OpenCase\Service\Datafordeler\CompanyData;
use OCA\OpenCase\Service\TransactionLogService;
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

    private function serialize(CompanyData $c): array {
        return [
            'cpr_cvr'     => $c->cvr,
            'name'        => $c->name,
            'streetname'  => $c->streetname,
            'housenumber' => $c->housenumber,
            'floor'       => $c->floor,
            'door'        => $c->door,
            'zipcode'     => $c->zipcode,
            'zipdistrict' => $c->zipdistrict,
            'phone'       => $c->phone,
            'email'       => $c->email,
            'pnumber'     => $c->pnumber,
        ];
    }

    private function serializeLocalRow(array $row): array {
        return [
            'cpr_cvr'     => $row['cpr_cvr'],
            'name'        => $row['name'],
            'streetname'  => $row['streetname'],
            'housenumber' => $row['housenumber'],
            'floor'       => $row['floor'],
            'door'        => $row['door'],
            'zipcode'     => $row['zipcode'],
            'zipdistrict' => $row['zipdistrict'],
            'phone'       => $row['phone'] ?? '',
            'email'       => $row['email'] ?? '',
            'pnumber'     => $row['pnumber'] ?? null,
        ];
    }

    /**
     * Fallback company search used when enterprise_version=0 (no Datafordeler
     * access): searches existing case participants and document contacts
     * instead, deduplicated by CVR. Only rows whose cpr_cvr is CVR-length
     * (8 digits) are considered — this excludes citizen (CPR, 10 digit) rows
     * that live in the same tables.
     *
     * @return array<int, array>
     */
    private function searchLocal(string $cvr, string $name): array {
        $rows = array_merge(
            $this->participantMapper->searchByFields($cvr, $name, '', '', '', '', ''),
            $this->contactMapper->searchByFields($cvr, $name, '', '', '', '', ''),
        );

        $byCvr = [];
        foreach ($rows as $row) {
            $digits = preg_replace('/\D/', '', (string)$row['cpr_cvr']);
            if (strlen($digits) !== 8) {
                continue;
            }
            if (!isset($byCvr[$digits])) {
                $byCvr[$digits] = $this->serializeLocalRow($row);
            }
        }

        return array_values($byCvr);
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
        $cvr  = trim((string)$this->request->getParam('cvr', ''));
        $name = trim((string)$this->request->getParam('name', ''));
        $mode = trim((string)$this->request->getParam('mode', ''));

        // CompanyClient is an Enterprise-only component (not present in the
        // public App Store package) — guard against the class simply not
        // being present on disk, and fall back to a local search below if
        // it isn't (also covers a misconfigured instance where the flag is
        // on but the Enterprise package was never installed).
        $enterpriseEnabled = $this->configuration->getConfigValue('enterprise_version', '0') === '1';
        $companyClientAvailable = $enterpriseEnabled && class_exists(CompanyClient::class);

        if ($companyClientAvailable) {
            try {
                $companyClient = \OC::$server->get(CompanyClient::class);
                if ($cvr !== '' && $mode === 'pnumber') {
                    $results   = $companyClient->fetchByCVRWithPnumber($cvr);
                    $companies = array_map(fn($c) => $this->serialize($c), $results);
                } elseif ($cvr !== '') {
                    $company = $companyClient->fetchByCVR($cvr);
                    $companies = $company !== null ? [$this->serialize($company)] : [];
                } elseif ($name !== '') {
                    $results   = $companyClient->fetchByName($name);
                    $companies = array_map(fn($c) => $this->serialize($c), $results);
                } else {
                    $companies = [];
                }
            } catch (\Throwable $e) {
                return new DataResponse(['companies' => [], 'error' => $e->getMessage()]);
            }
        } else {
            // No Datafordeler access on this instance — fall back to searching
            // existing case participants / document contacts locally.
            $companies = $this->searchLocal($cvr, $name);
        }

        if ($this->userId !== null) {
            $criteria = array_filter(['cvr' => $cvr, 'name' => $name], fn($v) => $v !== '');
            $this->transactionLogService->log($this->userId, $companyClientAvailable ? 'search_company' : 'search_company_local', $criteria);
        }

        return new DataResponse(['companies' => $companies]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/company/cases')]
    public function cases(): DataResponse {
        $cvr     = trim((string)$this->request->getParam('cvr', ''));
        $pnumber = trim((string)$this->request->getParam('pnumber', '')) ?: null;
        if ($cvr === '') {
            return new DataResponse(['cases' => []]);
        }

        $rows  = $this->participantMapper->findRawCasesByCpr($cvr, $pnumber);
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

        $rows      = $this->contactMapper->findRawDocumentsByCpr($cvr, $pnumber);
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
