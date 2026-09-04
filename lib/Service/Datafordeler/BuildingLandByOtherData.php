<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\Datafordeler;

class BuildingLandByOtherData {
	public function __construct(
		public readonly ?int $bfenumber = null,
		public readonly ?string $locationAddress = null,
		public readonly ?AggregatedEstateData $aggregatedEstate = null,
	) {
	}
}
