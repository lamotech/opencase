<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\Datafordeler;

class CompanyData {
	public function __construct(
		public readonly string $cvr,
		public readonly string $name,
		public readonly string $streetname,
		public readonly string $housenumber,
		public readonly string $floor,
		public readonly string $door,
		public readonly string $zipcode,
		public readonly string $zipdistrict,
		public readonly string $phone,
		public readonly string $email,
		public readonly ?string $pnumber = null,
	) {
	}
}
