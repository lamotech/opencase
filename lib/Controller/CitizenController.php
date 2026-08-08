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
use OCA\OpenCase\Service\Datafordeler\CitizenClient;
use OCA\OpenCase\Service\Datafordeler\CitizenData;
use OCA\OpenCase\Service\TransactionLogService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\L10N\IFactory;

/**
 * REST API controller for citizen look-ups.
 *
 * When enterprise_version=1, search() queries Datafordeler (CPR service).
 * When enterprise_version=0 (no Datafordeler access), search() instead
 * searches existing case participants / document contacts locally.
 *
 * Routes:
 *   GET /api/v1/citizen/search?cpr={cpr}                        — Search by CPR number
 *   GET /api/v1/citizen/search?firstname={f}&lastname={l}&…     — Search by name/address fields
 */
class CitizenController extends ApiController {

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

    private function serialize(CitizenData $c): array {
        return [
            'cpr_cvr'                => $c->cpr,
            'name'                   => $c->name,
            'streetname'             => $c->streetname,
            'housenumber'            => $c->housenumber,
            'floor'                  => $c->floor,
            'door'                   => $c->door,
            'zipcode'                => $c->zipcode,
            'zipdistrict'            => $c->zipdistrict,
            'phone'                  => '',
            'email'                  => '',
            'has_address_protection' => $c->hasAddressProtection,
        ];
    }

    private function serializeLocalRow(array $row): array {
        return [
            'cpr_cvr'                => $row['cpr_cvr'],
            'name'                   => $row['name'],
            'streetname'             => $row['streetname'],
            'housenumber'            => $row['housenumber'],
            'floor'                  => $row['floor'],
            'door'                   => $row['door'],
            'zipcode'                => $row['zipcode'],
            'zipdistrict'            => $row['zipdistrict'],
            'phone'                  => $row['phone'] ?? '',
            'email'                  => $row['email'] ?? '',
            'has_address_protection' => (bool)$row['has_address_protection'],
        ];
    }

    /**
     * Fallback citizen search used when enterprise_version=0 (no Datafordeler
     * access): searches existing case participants and document contacts
     * instead, deduplicated by CPR. Only rows whose cpr_cvr is CPR-length
     * (10 digits) are considered — this excludes company (CVR, 8 digit) rows
     * that live in the same tables.
     *
     * @return array<int, array>
     */
    private function searchLocal(
        string $cpr,
        string $firstname,
        string $lastname,
        string $streetname,
        string $housenumber,
        string $zipcode,
        string $zipdistrict,
    ): array {
        $rows = array_merge(
            $this->participantMapper->searchByFields($cpr, $firstname, $lastname, $streetname, $housenumber, $zipcode, $zipdistrict),
            $this->contactMapper->searchByFields($cpr, $firstname, $lastname, $streetname, $housenumber, $zipcode, $zipdistrict),
        );

        $byCpr = [];
        foreach ($rows as $row) {
            $digits = preg_replace('/\D/', '', (string)$row['cpr_cvr']);
            if (strlen($digits) !== 10) {
                continue;
            }
            if (!isset($byCpr[$digits])) {
                $byCpr[$digits] = $this->serializeLocalRow($row);
            }
        }

        return array_values($byCpr);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/citizen/search')]
    public function search(): DataResponse {
        $cpr         = trim((string)$this->request->getParam('cpr', ''));
        $firstname   = trim((string)$this->request->getParam('firstname', ''));
        $lastname    = trim((string)$this->request->getParam('lastname', ''));
        $streetname  = trim((string)$this->request->getParam('streetname', ''));
        $housenumber = trim((string)$this->request->getParam('housenumber', ''));
        $zipcode     = trim((string)$this->request->getParam('zipcode', ''));
        $zipdistrict = trim((string)$this->request->getParam('zipdistrict', ''));

        // CitizenClient is an Enterprise-only component (not present in the
        // public App Store package) — guard against the class simply not
        // being present on disk, and fall back to a local search below if
        // it isn't (also covers a misconfigured instance where the flag is
        // on but the Enterprise package was never installed).
        $enterpriseEnabled = $this->configuration->getConfigValue('enterprise_version', '0') === '1';
        $citizenClientAvailable = $enterpriseEnabled && class_exists(CitizenClient::class);

        if ($citizenClientAvailable) {
            try {
                $citizenClient = \OC::$server->get(CitizenClient::class);
                if ($cpr !== '') {
                    $citizen  = $citizenClient->fetchByCPR($cpr);
                    $citizens = $citizen !== null ? [$this->serialize($citizen)] : [];
                } else {
                    $results  = $citizenClient->search($firstname, $lastname, $streetname, $housenumber, $zipcode, $zipdistrict);
                    $citizens = array_map(fn($c) => $this->serialize($c), $results);
                }
            } catch (\Throwable $e) {
                return new DataResponse(['citizens' => [], 'error' => $e->getMessage()]);
            }
        } else {
            // No Datafordeler access on this instance — fall back to searching
            // existing case participants / document contacts locally.
            $citizens = $this->searchLocal($cpr, $firstname, $lastname, $streetname, $housenumber, $zipcode, $zipdistrict);
        }

        if ($this->userId !== null) {
            $criteria = array_filter([
                'cpr'         => $cpr,
                'firstname'   => $firstname,
                'lastname'    => $lastname,
                'streetname'  => $streetname,
                'housenumber' => $housenumber,
                'zipcode'     => $zipcode,
                'zipdistrict' => $zipdistrict,
            ], fn($v) => $v !== '');
            $this->transactionLogService->log($this->userId, $citizenClientAvailable ? 'search_citizen' : 'search_citizen_local', $criteria);
        }

        return new DataResponse(['citizens' => $citizens]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/citizen/cases')]
    public function cases(): DataResponse {
        $cpr = trim((string)$this->request->getParam('cpr', ''));
        if ($cpr === '') {
            return new DataResponse(['cases' => []]);
        }

        $rows = $this->participantMapper->findRawCasesByCpr($cpr);

        $lang        = $this->getUserLanguage();
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
                'id'            => (int)$row['id'],
                'case_number'   => $row['case_number'],
                'title'         => $row['title'],
                'organisation'  => $orgNames[$row['org_uuid']] ?? '',
                'status_class'  => $this->statusCssClass($sid),
                'status_name'   => $sEntry['name'] ?? '',
                'updated_at'    => $row['updated_at'],
                'role_name'     => $roleNames[$rid] ?? '',
            ];
        }, $rows);

        return new DataResponse(['cases' => $cases]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/citizen/documents')]
    public function documents(): DataResponse {
        $cpr = trim((string)$this->request->getParam('cpr', ''));
        if ($cpr === '') {
            return new DataResponse(['documents' => []]);
        }

        $rows = $this->contactMapper->findRawDocumentsByCpr($cpr);

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
