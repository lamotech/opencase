<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\Datafordeler;

class ApartmentData {
	public function __construct(
		public readonly ?int $bfenumber = null,
		public readonly ?string $locationAddress = null,
		public readonly ?string $floor = null,
		public readonly ?string $door = null,
		public readonly ?string $apartmentNumber = null,
		public readonly ?int $allocFactorDenom = null,
		public readonly ?int $allocFactorNom = null,
		public readonly ?int $area = null,
		public readonly ?AggregatedEstateData $aggregatedEstate = null,
	) {
	}
}
