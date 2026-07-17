<?php

declare(strict_types=1);

namespace OCA\OpenCase\Middleware;

use OCP\AppFramework\Http;

/**
 * Thrown by PublicApiMiddleware when a request fails mTLS authentication.
 */
class PublicApiAuthException extends \RuntimeException {

    public function __construct(string $message, private int $statusCode = Http::STATUS_UNAUTHORIZED) {
        parent::__construct($message);
    }

    public function getStatusCode(): int {
        return $this->statusCode;
    }
}
