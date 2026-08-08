<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller\PublicApi;

use OCP\AppFramework\Http;

/**
 * Internal control-flow exception for PublicDocumentApiController — carries
 * the HTTP status to use for the JSON/XML error response.
 */
class PublicDocumentApiError extends \RuntimeException {

    public function __construct(string $message, private int $statusCode = Http::STATUS_BAD_REQUEST) {
        parent::__construct($message);
    }

    public function getStatusCode(): int {
        return $this->statusCode;
    }
}
