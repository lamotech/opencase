<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\Datafordeler;

class AggregatedEstateData {
	/**
	 * @param EstateAddressData[] $addresses
	 * @param LandPlotData[] $landPlots
	 * @param ApartmentData[] $apartments
	 * @param BuildingData[] $buildingslandbyother
	 */
	public function __construct(
		public readonly ?int $bfenumber = null,
		public readonly ?string $locationAddress = null,
		public readonly ?string $municipalityCode = null,
		public readonly ?string $streetname = null,
		public readonly ?string $roadCode = null,
		public readonly ?string $housenumber = null,
		public readonly ?string $zipcode = null,
		public readonly ?string $zipdistrict = null,
		public readonly ?string $additionalCityName = null,
		public readonly array $addresses = [],
		public readonly array $landPlots = [],
		public readonly array $apartments = [],
		public readonly array $buildingslandbyother = [],
	) {
	}
}
