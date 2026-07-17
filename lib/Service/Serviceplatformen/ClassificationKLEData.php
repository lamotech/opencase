<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\Serviceplatformen;

class ClassificationKLEData {
	public function __construct(
		public readonly string $uuid,
		public readonly string $code,
		public readonly string $title,
		public readonly string $description,
	) {
	}
}
