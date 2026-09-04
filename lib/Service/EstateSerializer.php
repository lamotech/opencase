<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Service\Datafordeler\AggregatedEstateData;
use OCA\OpenCase\Service\Datafordeler\ApartmentData;
use OCA\OpenCase\Service\Datafordeler\BuildingLandByOtherData;
use OCA\OpenCase\Service\Datafordeler\EstateAddressData;
use OCA\OpenCase\Service\Datafordeler\EstateData;
use OCA\OpenCase\Service\Datafordeler\LandPlotData;

/**
 * Converts Datafordeler estate DTOs into the plain-array wire shape used by
 * the estate search API response and, in turn, by EstateLinkService's
 * find-or-create logic. Shared by EstateController (search) and
 * PublicCaseApiController (estate-linking on the public API).
 */
class EstateSerializer {

    public function toArray(EstateData $estate): array {
        return [
            'type'                   => $estate->type,
            'bfenummer'              => $estate->bfenummer,
            'aggregated_estate'      => $estate->aggregatedEstate !== null ? $this->aggregatedEstateToArray($estate->aggregatedEstate) : null,
            'apartment'              => $estate->apartment !== null ? $this->apartmentToArray($estate->apartment) : null,
            'building_land_by_other' => $estate->buildingLandByOther !== null ? $this->buildingToArray($estate->buildingLandByOther) : null,
        ];
    }

    private function estateAddressToArray(EstateAddressData $a): array {
        return [
            'location_address' => $a->locationAddress,
            'floor'             => $a->floor,
            'door'              => $a->door,
        ];
    }

    private function landPlotToArray(LandPlotData $l): array {
        return [
            'cadastral_number' => $l->cadastralNumber,
            'cadastral_code'   => $l->cadastralCode,
            'cadastral_name'   => $l->cadastralName,
            'registred_area'   => $l->registredArea,
            'road_area'        => $l->roadArea,
        ];
    }

    private function apartmentToArray(ApartmentData $a): array {
        return [
            'bfenumber'          => $a->bfenumber,
            'location_address'   => $a->locationAddress,
            'floor'              => $a->floor,
            'door'               => $a->door,
            'apartment_number'   => $a->apartmentNumber,
            'alloc_factor_denom' => $a->allocFactorDenom,
            'alloc_factor_nom'   => $a->allocFactorNom,
            'area'               => $a->area,
        ];
    }

    private function buildingToArray(BuildingLandByOtherData $b): array {
        return [
            'bfenumber'        => $b->bfenumber,
            'location_address' => $b->locationAddress,
        ];
    }

    private function aggregatedEstateToArray(AggregatedEstateData $e): array {
        return [
            'bfenumber'               => $e->bfenumber,
            'location_address'        => $e->locationAddress,
            'municipality_code'       => $e->municipalityCode,
            'streetname'              => $e->streetname,
            'road_code'               => $e->roadCode,
            'housenumber'             => $e->housenumber,
            'zipcode'                 => $e->zipcode,
            'zipdistrict'             => $e->zipdistrict,
            'additional_city_name'    => $e->additionalCityName,
            'addresses'               => array_map(fn ($a) => $this->estateAddressToArray($a), $e->addresses),
            'land_plots'              => array_map(fn ($l) => $this->landPlotToArray($l), $e->landPlots),
            'apartments'              => array_map(fn ($a) => $this->apartmentToArray($a), $e->apartments),
            'buildings_land_by_other' => array_map(fn ($b) => $this->buildingToArray($b), $e->buildingslandbyother),
        ];
    }
}
