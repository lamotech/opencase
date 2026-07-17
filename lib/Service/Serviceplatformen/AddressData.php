<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\Serviceplatformen;

class AddressData {
	public function __construct(
		public string $uuid,
		public string $roleUuid,
        public string $label,
        public string $text,
	) {
	}
}
