<?php

declare(strict_types=1);

namespace OCA\OpenCase\Exception;

/**
 * Thrown when an inbound SAML message fails validation — bad or missing
 * signature, wrong audience, expired conditions, replay, and so on.
 *
 * Every path that raises this must refuse the request. A SAML message that
 * does not validate carries no identity, so there is nothing to fall back to.
 */
class SamlValidationException extends \RuntimeException {}
