<?php

declare(strict_types=1);

namespace OCA\OpenCase\Middleware;

use OCA\OpenCase\Controller\PublicApi\PublicApiController;
use OCA\OpenCase\Db\ApiClientRepository;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Guards all PublicApiController endpoints with mTLS client certificate auth.
 *
 * nginx terminates TLS and forwards three headers that this middleware trusts:
 *
 *   X-SSL-Client-Verify      — "SUCCESS" when the client presented a valid cert
 *   X-SSL-Client-Fingerprint — SHA-256 fingerprint (lowercase hex, no colons)
 *   X-SSL-Client-DN          — Subject DN of the client certificate
 *
 * SECURITY: nginx must strip any client-supplied versions of these headers
 * before setting its own (proxy_set_header X-SSL-Client-Verify ""; then set
 * to $ssl_client_verify). Without this, a caller could spoof the header.
 *
 * The fingerprint is matched against the opencase_api_client table. Requests
 * from unknown or inactive clients are rejected with HTTP 403.
 */
class PublicApiMiddleware extends Middleware {

    public function __construct(
        private IRequest $request,
        private ApiClientRepository $apiClientRepository,
        private LoggerInterface $logger,
    ) {}

    public function beforeController(Controller $controller, string $methodName): void {
        if (!$controller instanceof PublicApiController) {
            return;
        }

        $verify = $this->request->getHeader('X-SSL-Client-Verify');
        if ($verify !== 'SUCCESS') {
            $this->logger->warning('Public API request rejected: missing or invalid client certificate', [
                'app'    => 'opencase',
                'verify' => $verify,
                'ip'     => $this->request->getRemoteAddress(),
            ]);
            throw new PublicApiAuthException('Client certificate required', Http::STATUS_UNAUTHORIZED);
        }

        $fingerprint = $this->request->getHeader('X-SSL-Client-Fingerprint');
        if (empty($fingerprint)) {
            throw new PublicApiAuthException('Client certificate fingerprint missing', Http::STATUS_UNAUTHORIZED);
        }

        $client = $this->apiClientRepository->findActiveByFingerprint($fingerprint);
        if ($client === null) {
            $this->logger->warning('Public API request rejected: certificate not registered or inactive', [
                'app'         => 'opencase',
                'fingerprint' => $fingerprint,
                'dn'          => $this->request->getHeader('X-SSL-Client-DN'),
            ]);
            throw new PublicApiAuthException('Certificate not authorised', Http::STATUS_FORBIDDEN);
        }

        // Check expiry stored in the registry (belt-and-suspenders; nginx also
        // validates the certificate's own NotAfter via ssl_verify_client).
        if ($client['expires_at'] !== null) {
            $expiresAt = new \DateTimeImmutable($client['expires_at']);
            if (new \DateTimeImmutable() > $expiresAt) {
                throw new PublicApiAuthException('API client certificate has expired', Http::STATUS_FORBIDDEN);
            }
        }
    }

    public function afterException(Controller $controller, string $methodName, \Exception $exception): Response {
        if (!($exception instanceof PublicApiAuthException)) {
            throw $exception;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<error><code>' . $exception->getStatusCode() . '</code>'
            . '<message>' . htmlspecialchars($exception->getMessage(), ENT_XML1) . '</message>'
            . '</error>';

        return new DataDisplayResponse($xml, $exception->getStatusCode(), ['Content-Type' => 'text/xml; charset=UTF-8']);
    }
}
