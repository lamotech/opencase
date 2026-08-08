<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\Serviceplatformen;

class OrganisationData {
	public function __construct(
		public readonly string $uuid,
		public readonly string $name,
		public readonly string $parentUuid,
		public array $addresses,
	) {
	}
}
