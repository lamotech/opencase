<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\Serviceplatformen;

class UserData {
	public function __construct(
		public string $uuid,
		public string $username,
		public PersonData $person,
        public array $addresses,
        public array $organisations,
    ) {
    }
}
