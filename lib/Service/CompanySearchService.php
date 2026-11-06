<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\CaseParticipantMapper;
use OCA\OpenCase\Db\DocumentContactMapper;
use OCA\OpenCase\Service\Datafordeler\CompanyClient;
use OCA\OpenCase\Service\Datafordeler\CompanyData;

/**
 * Company look-up, shared by the web API (CompanyController) and the desktop API.
 *
 * When enterprise_version=1, search() queries Datafordeler. When
 * enterprise_version=0 (no Datafordeler access), it searches existing case
 * participants / document contacts locally instead. Every search is
 * recorded in the transaction log.
 */
class CompanySearchService {

    public function __construct(
        private CaseParticipantMapper $participantMapper,
        private DocumentContactMapper $contactMapper,
        private TransactionLogService $transactionLogService,
        private Configuration $configuration,
    ) {
    }

    /**
     * Whether searches go to Datafordeler (true) or only to people already
     * on cases and documents (false).
     */
    public function usesRegister(): bool {
        return $this->configuration->getConfigValue('enterprise_version', '0') === '1'
            && class_exists(CompanyClient::class);
    }

    /**
     * @param array<string, string> $criteria cvr, name, mode
     * @return array{results: list<array>, error: ?string}
     */
    public function search(array $criteria, ?string $userId): array {
        $cvr = trim((string)($criteria['cvr'] ?? ''));
        $name = trim((string)($criteria['name'] ?? ''));
        $mode = trim((string)($criteria['mode'] ?? ''));

        // CompanyClient is an Enterprise-only component (not present in the
        // public App Store package) — guard against the class simply not
        // being present on disk, and fall back to a local search below if
        // it isn't (also covers a misconfigured instance where the flag is
        // on but the Enterprise package was never installed).
        $companyClientAvailable = $this->usesRegister();

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
                return ['results' => [], 'error' => $e->getMessage()];
            }
        } else {
            // No Datafordeler access on this instance — fall back to searching
            // existing case participants / document contacts locally.
            $companies = $userId !== null ? $this->searchLocal($cvr, $name, $userId) : [];
        }

        if ($userId !== null) {
            $criteria = array_filter(['cvr' => $cvr, 'name' => $name], fn($v) => $v !== '');
            $this->transactionLogService->log($userId, $companyClientAvailable ? 'search_company' : 'search_company_local', $criteria);
        }

        return ['results' => $companies, 'error' => null];
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
     * access): searches the case participants and document contacts of the
     * cases the user can read instead, deduplicated by CVR. Only rows whose cpr_cvr is CVR-length
     * (8 digits) are considered — this excludes citizen (CPR, 10 digit) rows
     * that live in the same tables.
     *
     * @return array<int, array>
     */
    private function searchLocal(string $cvr, string $name, string $userId): array {
        $rows = array_merge(
            $this->participantMapper->searchByFields($cvr, $name, '', '', '', '', '', $userId),
            $this->contactMapper->searchByFields($cvr, $name, '', '', '', '', '', $userId),
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
}
