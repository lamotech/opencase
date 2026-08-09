<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

/**
 * Thrown when a requested entity is not found or the user does not
 * have access to it.
 *
 * The controller layer maps this to a 404 response. By using the
 * same exception for "not found" and "access denied", we avoid
 * leaking information about the existence of resources the user
 * cannot access.
 */
class NotFoundException extends \Exception {
}
