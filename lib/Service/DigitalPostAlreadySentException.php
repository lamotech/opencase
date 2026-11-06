<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

/**
 * A document is sent with Digital Post once (DigitalPostService::createShipment()).
 */
class DigitalPostAlreadySentException extends \InvalidArgumentException {
}
