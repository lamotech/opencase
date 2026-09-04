<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\Datafordeler;

class EstateAddressData {
	public function __construct(
		public readonly ?string $locationAddress = null,
		public readonly ?string $floor = null,
		public readonly ?string $door = null,
	) {
	}
}
