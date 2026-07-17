<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\Serviceplatformen;

class UserOrganisationData {
	public function __construct(
		public string $orgUuid,
		public string $role,
    ) {
    }
}
