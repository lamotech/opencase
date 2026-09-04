<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\Datafordeler;

class LandPlotData {
	public function __construct(
		public readonly ?string $cadastralNumber = null,
		public readonly ?int $cadastralCode = null,
		public readonly ?string $cadastralName = null,
		public readonly ?int $registredArea = null,
		public readonly ?int $roadArea = null,
	) {
	}
}
