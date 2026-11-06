<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\CaseParticipantMapper;
use OCA\OpenCase\Db\DocumentContactMapper;
use OCA\OpenCase\Service\Datafordeler\CitizenClient;
use OCA\OpenCase\Service\Datafordeler\CitizenData;

/**
 * Citizen look-up, shared by the web API (CitizenController) and the desktop API.
 *
 * When enterprise_version=1, search() queries Datafordeler. When
 * enterprise_version=0 (no Datafordeler access), it searches existing case
 * participants / document contacts locally instead. Every search is
 * recorded in the transaction log.
 */
class CitizenSearchService {

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
            && class_exists(CitizenClient::class);
    }

    /**
     * @param array<string, string> $criteria cpr, firstname, lastname, streetname, housenumber, zipcode, zipdistrict
     * @return array{results: list<array>, error: ?string}
     */
    public function search(array $criteria, ?string $userId): array {
        $cpr = trim((string)($criteria['cpr'] ?? ''));
        $firstname = trim((string)($criteria['firstname'] ?? ''));
        $lastname = trim((string)($criteria['lastname'] ?? ''));
        $streetname = trim((string)($criteria['streetname'] ?? ''));
        $housenumber = trim((string)($criteria['housenumber'] ?? ''));
        $zipcode = trim((string)($criteria['zipcode'] ?? ''));
        $zipdistrict = trim((string)($criteria['zipdistrict'] ?? ''));

        // CitizenClient is an Enterprise-only component (not present in the
        // public App Store package) — guard against the class simply not
        // being present on disk, and fall back to a local search below if
        // it isn't (also covers a misconfigured instance where the flag is
        // on but the Enterprise package was never installed).
        $citizenClientAvailable = $this->usesRegister();

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
                return ['results' => [], 'error' => $e->getMessage()];
            }
        } else {
            // No Datafordeler access on this instance — fall back to searching
            // existing case participants / document contacts locally.
            $citizens = $userId !== null ? $this->searchLocal($cpr, $firstname, $lastname, $streetname, $housenumber, $zipcode, $zipdistrict, $userId) : [];
        }

        if ($userId !== null) {
            $criteria = array_filter([
                'cpr'         => $cpr,
                'firstname'   => $firstname,
                'lastname'    => $lastname,
                'streetname'  => $streetname,
                'housenumber' => $housenumber,
                'zipcode'     => $zipcode,
                'zipdistrict' => $zipdistrict,
            ], fn($v) => $v !== '');
            $this->transactionLogService->log($userId, $citizenClientAvailable ? 'search_citizen' : 'search_citizen_local', $criteria);
        }

        return ['results' => $citizens, 'error' => null];
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
     * access): searches the case participants and document contacts of the
     * cases the user can read instead, deduplicated by CPR. Only rows whose cpr_cvr is CPR-length
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
        string $userId,
    ): array {
        $rows = array_merge(
            $this->participantMapper->searchByFields($cpr, $firstname, $lastname, $streetname, $housenumber, $zipcode, $zipdistrict, $userId),
            $this->contactMapper->searchByFields($cpr, $firstname, $lastname, $streetname, $housenumber, $zipcode, $zipdistrict, $userId),
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
}
