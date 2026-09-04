<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Reconstructs the full estate detail structure (same shape
 * EstateController::search() returns from Datafordeler) from the local
 * opencase_estate and related tables, for estates already linked to a case
 * via EstateLinkService — no live Datafordeler call involved.
 */
class EstateReadService {

    public function __construct(
        private IDBConnection $db,
    ) {}

    public function getEstateById(int $id): ?array {
        $estate = $this->fetchRow('opencase_estate', $id);
        return $estate !== null ? $this->buildDetail($estate) : null;
    }

    /**
     * Find a locally-stored estate by BFE-nummer — the fallback lookup used
     * when enterprise_version=0 (no Datafordeler access, EstateClient isn't
     * present), so a BFE-nummer already linked to some case can still be
     * found. If more than one local estate row shares a BFE-nummer (e.g.
     * differing type), the most recently created one is returned.
     */
    public function findByBfenummer(int $bfenummer): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('opencase_estate')
            ->where($qb->expr()->eq('bfenummer', $qb->createNamedParameter($bfenummer, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'DESC')
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return $row !== false ? $this->buildDetail($row) : null;
    }

    /**
     * Local-DB fallback used when enterprise_version=0 (no Datafordeler
     * access, EstateClient isn't present) — searches previously-linked
     * estates by street name (and optionally house number). Returns items
     * in the same wire shape EstateSerializer::toArray() produces, so the
     * frontend doesn't need to distinguish a local result from a live one.
     */
    public function searchByAddress(string $streetname, string $housenumber = ''): array {
        $criteria = $housenumber !== ''
            ? $streetname . '% ' . $housenumber . '%'
            : $streetname . '%';

        return array_merge(
            $this->searchByAddressIn('opencase_aggregated_estates', 'aggregated_estates_id', 'Samlet fast ejendom', $criteria, 'aggregated_estate'),
            $this->searchByAddressIn('opencase_apartments', 'apartment_id', 'Ejerlejlighed', $criteria, 'apartment'),
            $this->searchByAddressIn('opencase_buildings_land_by_other', 'buildings_land_by_other_id', 'Bygning på fremmed grund', $criteria, 'building_land_by_other'),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchByAddressIn(string $table, string $joinColumn, string $type, string $criteria, string $resultKey): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('e.type', 'e.bfenummer', 't.location_address')
            ->from('opencase_estate', 'e')
            ->innerJoin('e', $table, 't', $qb->expr()->eq('t.id', 'e.' . $joinColumn))
            ->where($qb->expr()->eq('e.type', $qb->createNamedParameter($type)))
            ->andWhere($qb->expr()->like('t.location_address', $qb->createNamedParameter($criteria)));
        $result = $qb->executeQuery();

        $rows = [];
        while ($row = $result->fetch()) {
            $rows[] = [
                'type'                   => $row['type'],
                'bfenummer'              => $this->nullableInt($row['bfenummer'] ?? null),
                'aggregated_estate'      => $resultKey === 'aggregated_estate' ? ['location_address' => $row['location_address']] : null,
                'apartment'              => $resultKey === 'apartment' ? ['location_address' => $row['location_address']] : null,
                'building_land_by_other' => $resultKey === 'building_land_by_other' ? ['location_address' => $row['location_address']] : null,
            ];
        }
        $result->closeCursor();

        return $rows;
    }

    private function buildDetail(array $estate): array {
        $aggregatedEstatesId = $this->nullableInt($estate['aggregated_estates_id'] ?? null);
        $aggregatedEstate = $aggregatedEstatesId !== null ? $this->getAggregatedEstate($aggregatedEstatesId) : null;

        $apartmentId = $this->nullableInt($estate['apartment_id'] ?? null);
        $apartment = $apartmentId !== null ? $this->fetchApartment($apartmentId) : null;

        $buildingId = $this->nullableInt($estate['buildings_land_by_other_id'] ?? null);
        $buildingLandByOther = $buildingId !== null ? $this->fetchBuildingLandByOther($buildingId) : null;

        return [
            'id'                      => (int)$estate['id'],
            'type'                    => $estate['type'],
            'bfenummer'               => $this->nullableInt($estate['bfenummer'] ?? null),
            'aggregated_estate'       => $aggregatedEstate,
            'apartment'               => $apartment,
            'building_land_by_other'  => $buildingLandByOther,
        ];
    }

    private function getAggregatedEstate(int $id): ?array {
        $row = $this->fetchRow('opencase_aggregated_estates', $id);
        if ($row === null) {
            return null;
        }

        return [
            'bfenumber'               => $this->nullableInt($row['bfenumber'] ?? null),
            'location_address'        => $row['location_address'],
            'municipality_code'       => $row['municipality_code'],
            'streetname'              => $row['streetname'],
            'road_code'               => $row['road_code'],
            'housenumber'             => $row['housenumber'],
            'zipcode'                 => $row['zipcode'],
            'zipdistrict'             => $row['zipdistrict'],
            'additional_city_name'    => $row['additional_city_name'],
            'addresses'               => array_map(
                fn (array $r) => [
                    'location_address' => $r['location_address'],
                    'floor'             => $r['floor'],
                    'door'              => $r['door'],
                ],
                $this->fetchChildren('opencase_estate_addresses', $id),
            ),
            'land_plots'              => array_map(
                fn (array $r) => [
                    'cadastral_number' => $r['cadastral_number'],
                    'cadastral_code'   => $this->nullableInt($r['cadastral_code'] ?? null),
                    'cadastral_name'   => $r['cadastral_name'],
                    'registred_area'   => $this->nullableInt($r['registred_area'] ?? null),
                    'road_area'        => $this->nullableInt($r['road_area'] ?? null),
                ],
                $this->fetchChildren('opencase_land_plots', $id),
            ),
            'apartments'              => array_map(
                fn (array $r) => $this->mapApartmentRow($r),
                $this->fetchChildren('opencase_apartments', $id),
            ),
            'buildings_land_by_other' => array_map(
                fn (array $r) => $this->mapBuildingRow($r),
                $this->fetchChildren('opencase_buildings_land_by_other', $id),
            ),
        ];
    }

    private function fetchApartment(int $id): ?array {
        $row = $this->fetchRow('opencase_apartments', $id);
        return $row !== null ? $this->mapApartmentRow($row) : null;
    }

    private function fetchBuildingLandByOther(int $id): ?array {
        $row = $this->fetchRow('opencase_buildings_land_by_other', $id);
        return $row !== null ? $this->mapBuildingRow($row) : null;
    }

    private function mapApartmentRow(array $r): array {
        return [
            'bfenumber'          => $this->nullableInt($r['bfenumber'] ?? null),
            'location_address'   => $r['location_address'],
            'floor'              => $r['floor'],
            'door'               => $r['door'],
            'apartment_number'   => $r['apartment_number'],
            'alloc_factor_denom' => $this->nullableInt($r['alloc_factor_denom'] ?? null),
            'alloc_factor_nom'   => $this->nullableInt($r['alloc_factor_nom'] ?? null),
            'area'               => $this->nullableInt($r['area'] ?? null),
        ];
    }

    private function mapBuildingRow(array $r): array {
        return [
            'bfenumber'        => $this->nullableInt($r['bfenumber'] ?? null),
            'location_address' => $r['location_address'],
        ];
    }

    private function fetchRow(string $table, int $id): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($table)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row !== false ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchChildren(string $table, int $aggregatedEstatesId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($table)
            ->where($qb->expr()->eq('aggregated_estates_id', $qb->createNamedParameter($aggregatedEstatesId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC');
        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            $rows[] = $row;
        }
        $result->closeCursor();
        return $rows;
    }

    private function nullableInt(mixed $value): ?int {
        return $value === null ? null : (int)$value;
    }
}
