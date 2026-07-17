<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\Serviceplatformen;

class PersonData {
	public function __construct(
		public string $uuid,
        public string $name,
	) {
	}
}
