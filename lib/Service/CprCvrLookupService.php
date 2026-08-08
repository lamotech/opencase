<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Service\Datafordeler\CitizenClient;
use OCA\OpenCase\Service\Datafordeler\CompanyClient;

/**
 * Resolves a CPR (10-digit, citizen) or CVR (8-digit, company) number to
 * name/address/etc. via Datafordeler. Shared by the public API's
 * participant- and document-contact-adding endpoints.
 *
 * CitizenClient/CompanyClient are Enterprise-only components (not present
 * in the public App Store package), so — matching CitizenController and
 * CompanyController — availability is gated on both the enterprise_version
 * config flag and the class actually being present on disk, and resolved
 * lazily via the service container rather than constructor-injected.
 */
class CprCvrLookupService {

    public function __construct(
        private Configuration $configuration,
    ) {
    }

    /**
     * @return array{name: string, streetname: string, housenumber: string, floor: string,
     *   door: string, zipcode: string, zipdistrict: string, phone: ?string, email: ?string,
     *   has_address_protection: bool, pnumber: ?string}|null
     * @throws \RuntimeException if Datafordeler citizen lookup isn't enabled on this instance
     */
    public function lookupCitizen(string $cpr): ?array {
        $enterpriseEnabled = $this->configuration->getConfigValue('enterprise_version', '0') === '1';
        if (!$enterpriseEnabled || !class_exists(CitizenClient::class)) {
            throw new \RuntimeException('Citizen lookup (Datafordeler) is not enabled on this instance');
        }

        $citizenClient = \OC::$server->get(CitizenClient::class);
        $citizen       = $citizenClient->fetchByCPR($cpr);
        if ($citizen === null) {
            return null;
        }

        return [
            'name'                   => $citizen->name,
            'streetname'             => $citizen->streetname,
            'housenumber'            => $citizen->housenumber,
            'floor'                  => $citizen->floor,
            'door'                   => $citizen->door,
            'zipcode'                => $citizen->zipcode,
            'zipdistrict'            => $citizen->zipdistrict,
            'phone'                  => null,
            'email'                  => null,
            'has_address_protection' => $citizen->hasAddressProtection,
            'pnumber'                => null,
        ];
    }

    /**
     * @return array{name: string, streetname: string, housenumber: string, floor: string,
     *   door: string, zipcode: string, zipdistrict: string, phone: ?string, email: ?string,
     *   has_address_protection: bool, pnumber: ?string}|null
     * @throws \RuntimeException if Datafordeler company lookup isn't enabled on this instance
     */
    public function lookupCompany(string $cvr): ?array {
        $enterpriseEnabled = $this->configuration->getConfigValue('enterprise_version', '0') === '1';
        if (!$enterpriseEnabled || !class_exists(CompanyClient::class)) {
            throw new \RuntimeException('Company lookup (Datafordeler) is not enabled on this instance');
        }

        $companyClient = \OC::$server->get(CompanyClient::class);
        $company       = $companyClient->fetchByCVR($cvr);
        if ($company === null) {
            return null;
        }

        return [
            'name'                   => $company->name,
            'streetname'             => $company->streetname,
            'housenumber'            => $company->housenumber,
            'floor'                  => $company->floor,
            'door'                   => $company->door,
            'zipcode'                => $company->zipcode,
            'zipdistrict'            => $company->zipdistrict,
            'phone'                  => $company->phone,
            'email'                  => $company->email,
            'has_address_protection' => false,
            'pnumber'                => $company->pnumber,
        ];
    }
}
