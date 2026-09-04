<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\Datafordeler;

class EstateData {
	public function __construct(
		public readonly string $type,
		public readonly ?int $bfenummer = null,
		public readonly ?AggregatedEstateData $aggregatedEstate = null,
		public readonly ?ApartmentData $apartment = null,
		public readonly ?BuildingLandByOtherData $buildingLandByOther = null,
	) {
	}
}
