<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Service\Datafordeler\EstateClient;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Persists estate data fetched from Datafordeler into the local
 * opencase_estate / opencase_aggregated_estates / opencase_estate_addresses /
 * opencase_land_plots / opencase_apartments / opencase_buildings_land_by_other
 * tables (find-or-create by BFE-nummer + type), and links the resulting
 * estate to a case via opencase_caseestates.
 *
 * The estate array is the same shape EstateSerializer::toArray() emits from
 * a Datafordeler search — see that method for the exact key layout.
 */
class EstateLinkService {

    public function __construct(
        private IDBConnection $db,
        private Configuration $configuration,
        private EstateSerializer $estateSerializer,
    ) {}

    /**
     * Look up an estate by BFE-nummer via a live Datafordeler call and link
     * it to a case (find-or-create locally). Used by the public API's
     * estate-linking endpoint, which only receives a BFE-nummer — unlike
     * linkEstateToCase() above, whose caller (the internal UI) already has
     * the full estate data in hand from an earlier search.
     *
     * @return array{estate_id: int, type: string, bfenummer: ?int, location_address: ?string}|null
     *   null if no estate was found for the given BFE-nummer.
     * @throws \RuntimeException if Datafordeler estate lookup isn't enabled on this instance
     */
    public function linkByBfenummer(int $caseId, string $bfenummer, ?int $roleId): ?array {
        // EstateClient is an Enterprise-only component (not present in the
        // public App Store package) — matching EstateController::search().
        $enterpriseEnabled = $this->configuration->getConfigValue('enterprise_version', '0') === '1';
        if (!$enterpriseEnabled || !class_exists(EstateClient::class)) {
            throw new \RuntimeException('Estate lookup (Datafordeler) is not enabled on this instance');
        }

        $estateClient = \OC::$server->get(EstateClient::class);
        $estateData   = $estateClient->fetchByBFENumber($bfenummer);
        if ($estateData === null) {
            return null;
        }

        $estateArray = $this->estateSerializer->toArray($estateData);
        $estateId    = $this->linkEstateToCase($caseId, $estateArray, $roleId);

        return [
            'estate_id'        => $estateId,
            'type'             => $estateData->type,
            'bfenummer'        => $estateData->bfenummer,
            'location_address' => $estateArray['apartment']['location_address']
                ?? $estateArray['building_land_by_other']['location_address']
                ?? $estateArray['aggregated_estate']['location_address']
                ?? null,
        ];
    }

    public function linkEstateToCase(int $caseId, array $estate, ?int $roleId): int {
        $estateId = $this->createOrFindEstate($estate);
        $this->linkCaseEstate($caseId, $estateId, $roleId);

        return $estateId;
    }

    /**
     * Find-or-create a local estate row (and its aggregated_estates/
     * apartment/buildings_land_by_other rows) by BFE-nummer + type, without
     * linking it to a case. Used both by linkEstateToCase() above and by
     * the "create a new estate manually" flow (community edition, no
     * Datafordeler access), which never links the estate to a case at
     * creation time.
     */
    public function createOrFindEstate(array $estate): int {
        $type = (string)($estate['type'] ?? '');
        $bfenummer = isset($estate['bfenummer']) ? (int)$estate['bfenummer'] : null;

        if ($type === '' || $bfenummer === null) {
            throw new \InvalidArgumentException("Fields 'type' and 'bfenummer' are required");
        }
        if (!is_array($estate['aggregated_estate'] ?? null)) {
            throw new \InvalidArgumentException("Field 'aggregated_estate' is required");
        }

        $estateId = $this->findEstateId($bfenummer, $type);
        if ($estateId === null) {
            $estateId = $this->createEstate($type, $bfenummer, $estate);
        }

        return $estateId;
    }

    /**
     * Update an existing local estate's editable fields — everything except
     * its type, which is immutable once created (matching the "not allowed
     * to change the type" edit-dialog rule). Only touches the single
     * aggregated_estate/apartment/building_land_by_other row already linked
     * to this estate (or creates one, if — unexpectedly — it didn't have
     * one yet); never the Datafordeler-only child lists (addresses/land
     * plots/sibling apartments), which the manual create/edit dialogs don't
     * expose.
     */
    public function updateEstate(int $estateId, array $estate): void {
        $existing = $this->fetchEstateRow($estateId);
        if ($existing === null) {
            throw new \InvalidArgumentException('Estate not found');
        }

        $type = $existing['type'];
        $bfenummer = isset($estate['bfenummer']) ? (int)$estate['bfenummer'] : null;
        if ($bfenummer === null) {
            throw new \InvalidArgumentException("Field 'bfenummer' is required");
        }
        if (!is_array($estate['aggregated_estate'] ?? null)) {
            throw new \InvalidArgumentException("Field 'aggregated_estate' is required");
        }

        $this->db->beginTransaction();
        try {
            $aggregatedEstatesId = $this->nullableInt($existing['aggregated_estates_id'] ?? null);
            if ($aggregatedEstatesId !== null) {
                $this->updateAggregatedEstate($aggregatedEstatesId, $estate['aggregated_estate']);
            } else {
                $aggregatedEstatesId = $this->insertAggregatedEstate($estate['aggregated_estate']);
            }

            $apartmentId = $this->nullableInt($existing['apartment_id'] ?? null);
            if ($type === 'Ejerlejlighed' && is_array($estate['apartment'] ?? null)) {
                if ($apartmentId !== null) {
                    $this->updateApartment($apartmentId, $aggregatedEstatesId, $estate['apartment']);
                } else {
                    $apartmentId = $this->insertApartment($aggregatedEstatesId, $estate['apartment']);
                }
            }

            $buildingsLandByOtherId = $this->nullableInt($existing['buildings_land_by_other_id'] ?? null);
            if ($type === 'Bygning på fremmed grund' && is_array($estate['building_land_by_other'] ?? null)) {
                if ($buildingsLandByOtherId !== null) {
                    $this->updateBuildingLandByOther($buildingsLandByOtherId, $aggregatedEstatesId, $estate['building_land_by_other']);
                } else {
                    $buildingsLandByOtherId = $this->insertBuildingLandByOther($aggregatedEstatesId, $estate['building_land_by_other']);
                }
            }

            $qb = $this->db->getQueryBuilder();
            $qb->update('opencase_estate')
                ->set('bfenummer', $qb->createNamedParameter($bfenummer, IQueryBuilder::PARAM_INT))
                ->set('aggregated_estates_id', $this->intOrNull($qb, $aggregatedEstatesId))
                ->set('apartment_id', $this->intOrNull($qb, $apartmentId))
                ->set('buildings_land_by_other_id', $this->intOrNull($qb, $buildingsLandByOtherId))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($estateId, IQueryBuilder::PARAM_INT)))
                ->executeStatement();

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function fetchEstateRow(int $estateId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('opencase_estate')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($estateId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row !== false ? $row : null;
    }

    private function findEstateId(int $bfenummer, string $type): ?int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('opencase_estate')
            ->where($qb->expr()->eq('bfenummer', $qb->createNamedParameter($bfenummer, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type)));
        $result = $qb->executeQuery();
        $id = $result->fetchOne();
        $result->closeCursor();
        return $id !== false ? (int)$id : null;
    }

    private function createEstate(string $type, int $bfenummer, array $estate): int {
        $this->db->beginTransaction();
        try {
            $aggregatedEstatesId = null;
            $apartmentIdsByBfe = [];
            $buildingIdsByBfe = [];

            $aggregated = $estate['aggregated_estate'] ?? null;
            if (is_array($aggregated)) {
                $aggregatedEstatesId = $this->insertAggregatedEstate($aggregated);

                foreach ($aggregated['addresses'] ?? [] as $address) {
                    $this->insertEstateAddress($aggregatedEstatesId, $address);
                }
                foreach ($aggregated['land_plots'] ?? [] as $landPlot) {
                    $this->insertLandPlot($aggregatedEstatesId, $landPlot);
                }
                foreach ($aggregated['apartments'] ?? [] as $apartment) {
                    $id = $this->insertApartment($aggregatedEstatesId, $apartment);
                    if (isset($apartment['bfenumber'])) {
                        $apartmentIdsByBfe[(int)$apartment['bfenumber']] = $id;
                    }
                }
                foreach ($aggregated['buildings_land_by_other'] ?? [] as $building) {
                    $id = $this->insertBuildingLandByOther($aggregatedEstatesId, $building);
                    if (isset($building['bfenumber'])) {
                        $buildingIdsByBfe[(int)$building['bfenumber']] = $id;
                    }
                }
            }

            $apartmentId = null;
            if ($type === 'Ejerlejlighed') {
                $apartmentId = $apartmentIdsByBfe[$bfenummer] ?? null;
                if ($apartmentId === null && is_array($estate['apartment'] ?? null)) {
                    // Fallback for the (unexpected) case where the aggregated
                    // estate's apartment list didn't include this one.
                    $apartmentId = $this->insertApartment($aggregatedEstatesId, $estate['apartment']);
                }
            }

            $buildingsLandByOtherId = null;
            if ($type === 'Bygning på fremmed grund') {
                $buildingsLandByOtherId = $buildingIdsByBfe[$bfenummer] ?? null;
                if ($buildingsLandByOtherId === null && is_array($estate['building_land_by_other'] ?? null)) {
                    $buildingsLandByOtherId = $this->insertBuildingLandByOther($aggregatedEstatesId, $estate['building_land_by_other']);
                }
            }

            $qb = $this->db->getQueryBuilder();
            $qb->insert('opencase_estate')
                ->values([
                    'type'                       => $qb->createNamedParameter($type),
                    'bfenummer'                  => $qb->createNamedParameter($bfenummer, IQueryBuilder::PARAM_INT),
                    'aggregated_estates_id'      => $this->intOrNull($qb, $aggregatedEstatesId),
                    'apartment_id'               => $this->intOrNull($qb, $apartmentId),
                    'buildings_land_by_other_id' => $this->intOrNull($qb, $buildingsLandByOtherId),
                ])
                ->executeStatement();
            $estateId = (int)$this->db->lastInsertId('oc_opencase_estate');

            $this->db->commit();
            return $estateId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function insertAggregatedEstate(array $a): int {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('opencase_aggregated_estates')
            ->values([
                'bfenumber'            => $this->intOrNull($qb, $this->nullableInt($a['bfenumber'] ?? null)),
                'location_address'     => $this->strOrNull($qb, $a['location_address'] ?? null),
                'municipality_code'    => $this->strOrNull($qb, $a['municipality_code'] ?? null),
                'streetname'           => $this->strOrNull($qb, $a['streetname'] ?? null),
                'road_code'            => $this->strOrNull($qb, $a['road_code'] ?? null),
                'housenumber'          => $this->strOrNull($qb, $a['housenumber'] ?? null),
                'zipcode'              => $this->strOrNull($qb, $a['zipcode'] ?? null),
                'zipdistrict'          => $this->strOrNull($qb, $a['zipdistrict'] ?? null),
                'additional_city_name' => $this->strOrNull($qb, $a['additional_city_name'] ?? null),
            ])
            ->executeStatement();
        return (int)$this->db->lastInsertId('oc_opencase_aggregated_estates');
    }

    private function updateAggregatedEstate(int $id, array $a): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('opencase_aggregated_estates')
            ->set('bfenumber', $this->intOrNull($qb, $this->nullableInt($a['bfenumber'] ?? null)))
            ->set('location_address', $this->strOrNull($qb, $a['location_address'] ?? null))
            ->set('municipality_code', $this->strOrNull($qb, $a['municipality_code'] ?? null))
            ->set('streetname', $this->strOrNull($qb, $a['streetname'] ?? null))
            ->set('road_code', $this->strOrNull($qb, $a['road_code'] ?? null))
            ->set('housenumber', $this->strOrNull($qb, $a['housenumber'] ?? null))
            ->set('zipcode', $this->strOrNull($qb, $a['zipcode'] ?? null))
            ->set('zipdistrict', $this->strOrNull($qb, $a['zipdistrict'] ?? null))
            ->set('additional_city_name', $this->strOrNull($qb, $a['additional_city_name'] ?? null))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    private function updateApartment(int $id, ?int $aggregatedEstatesId, array $a): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('opencase_apartments')
            ->set('aggregated_estates_id', $this->intOrNull($qb, $aggregatedEstatesId))
            ->set('bfenumber', $this->intOrNull($qb, $this->nullableInt($a['bfenumber'] ?? null)))
            ->set('location_address', $this->strOrNull($qb, $a['location_address'] ?? null))
            ->set('floor', $this->strOrNull($qb, $a['floor'] ?? null))
            ->set('door', $this->strOrNull($qb, $a['door'] ?? null))
            ->set('apartment_number', $this->strOrNull($qb, $a['apartment_number'] ?? null))
            ->set('alloc_factor_denom', $this->intOrNull($qb, $this->nullableInt($a['alloc_factor_denom'] ?? null)))
            ->set('alloc_factor_nom', $this->intOrNull($qb, $this->nullableInt($a['alloc_factor_nom'] ?? null)))
            ->set('area', $this->intOrNull($qb, $this->nullableInt($a['area'] ?? null)))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    private function updateBuildingLandByOther(int $id, ?int $aggregatedEstatesId, array $b): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('opencase_buildings_land_by_other')
            ->set('aggregated_estates_id', $this->intOrNull($qb, $aggregatedEstatesId))
            ->set('bfenumber', $this->intOrNull($qb, $this->nullableInt($b['bfenumber'] ?? null)))
            ->set('location_address', $this->strOrNull($qb, $b['location_address'] ?? null))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    private function insertEstateAddress(?int $aggregatedEstatesId, array $a): int {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('opencase_estate_addresses')
            ->values([
                'aggregated_estates_id' => $this->intOrNull($qb, $aggregatedEstatesId),
                'location_address'      => $this->strOrNull($qb, $a['location_address'] ?? null),
                'floor'                 => $this->strOrNull($qb, $a['floor'] ?? null),
                'door'                  => $this->strOrNull($qb, $a['door'] ?? null),
            ])
            ->executeStatement();
        return (int)$this->db->lastInsertId('oc_opencase_estate_addresses');
    }

    private function insertLandPlot(?int $aggregatedEstatesId, array $l): int {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('opencase_land_plots')
            ->values([
                'aggregated_estates_id' => $this->intOrNull($qb, $aggregatedEstatesId),
                'cadastral_number'      => $this->strOrNull($qb, $l['cadastral_number'] ?? null),
                'cadastral_code'        => $this->intOrNull($qb, $this->nullableInt($l['cadastral_code'] ?? null)),
                'cadastral_name'        => $this->strOrNull($qb, $l['cadastral_name'] ?? null),
                'registred_area'        => $this->intOrNull($qb, $this->nullableInt($l['registred_area'] ?? null)),
                'road_area'             => $this->intOrNull($qb, $this->nullableInt($l['road_area'] ?? null)),
            ])
            ->executeStatement();
        return (int)$this->db->lastInsertId('oc_opencase_land_plots');
    }

    private function insertApartment(?int $aggregatedEstatesId, array $a): int {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('opencase_apartments')
            ->values([
                'aggregated_estates_id' => $this->intOrNull($qb, $aggregatedEstatesId),
                'bfenumber'             => $this->intOrNull($qb, $this->nullableInt($a['bfenumber'] ?? null)),
                'location_address'      => $this->strOrNull($qb, $a['location_address'] ?? null),
                'floor'                 => $this->strOrNull($qb, $a['floor'] ?? null),
                'door'                  => $this->strOrNull($qb, $a['door'] ?? null),
                'apartment_number'      => $this->strOrNull($qb, $a['apartment_number'] ?? null),
                'alloc_factor_denom'    => $this->intOrNull($qb, $this->nullableInt($a['alloc_factor_denom'] ?? null)),
                'alloc_factor_nom'      => $this->intOrNull($qb, $this->nullableInt($a['alloc_factor_nom'] ?? null)),
                'area'                  => $this->intOrNull($qb, $this->nullableInt($a['area'] ?? null)),
            ])
            ->executeStatement();
        return (int)$this->db->lastInsertId('oc_opencase_apartments');
    }

    private function insertBuildingLandByOther(?int $aggregatedEstatesId, array $b): int {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('opencase_buildings_land_by_other')
            ->values([
                'aggregated_estates_id' => $this->intOrNull($qb, $aggregatedEstatesId),
                'bfenumber'             => $this->intOrNull($qb, $this->nullableInt($b['bfenumber'] ?? null)),
                'location_address'      => $this->strOrNull($qb, $b['location_address'] ?? null),
            ])
            ->executeStatement();
        return (int)$this->db->lastInsertId('oc_opencase_buildings_land_by_other');
    }

    private function linkCaseEstate(int $caseId, int $estateId, ?int $roleId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('opencase_caseestates')
            ->values([
                'case_id'   => $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT),
                'estate_id' => $qb->createNamedParameter($estateId, IQueryBuilder::PARAM_INT),
                'role_id'   => $this->intOrNull($qb, $roleId),
            ])
            ->executeStatement();
    }

    private function nullableInt(mixed $value): ?int {
        return $value === null || $value === '' ? null : (int)$value;
    }

    private function intOrNull(IQueryBuilder $qb, ?int $value): mixed {
        return $qb->createNamedParameter($value, $value === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT);
    }

    private function strOrNull(IQueryBuilder $qb, ?string $value): mixed {
        return $qb->createNamedParameter($value, $value === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR);
    }
}
