<?php

declare(strict_types=1);

namespace OCA\OpenCase\Middleware;

use OCA\OpenCase\Controller\PublicApi\PublicDataApiController;
use OCA\OpenCase\Db\ApiClientRepository;
use OCA\OpenCase\Service\Certificate;
use OCA\OpenCase\Service\Configuration;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OneLogin\Saml2\Utils as Saml2Utils;
use Psr\Log\LoggerInterface;

/**
 * Guards all PublicDataApiController endpoints with bearer-token auth.
 *
 * The Authorization header must be:
 *
 *   Authorization: Bearer <base64-encoded SAML 2.0 assertion>
 *
 * shaped like lib/Service/Serviceplatformen/TokenSamples/SAMLToken.xml.
 *
 * Validation performed here, in order:
 *   1. The assertion's XML signature must verify against a registered,
 *      active opencase_api_client certificate with valid_for = 'Adgangsstyring'
 *      (the IdP's own signing certificate — a trust anchor configured by an
 *      admin, NOT the certificate embedded in the token itself). Nothing
 *      else in the token is trusted until this passes: without it, an
 *      attacker who observed one legitimate token could forge new ones
 *      with any audience/expiry/subject-cert by reusing the same embedded
 *      certificate.
 *   2. The assertion's Audience must match opencase_config.entity_id_opencase_api
 *   3. The assertion must be within its Conditions validity window
 *   4. The certificate embedded in
 *      Subject/SubjectConfirmation/SubjectConfirmationData/KeyInfo/X509Data/X509Certificate
 *      must match a registered, active opencase_api_client row with valid_for = 'API'
 *   5. That client's mapped_to_user becomes the acting Nextcloud user for the
 *      remainder of the request — the controller runs with that user's
 *      permissions
 */
class PublicDataApiMiddleware extends Middleware {

    private const NS_SAML          = 'urn:oasis:names:tc:SAML:2.0:assertion';
    private const NS_DS            = 'http://www.w3.org/2000/09/xmldsig#';
    private const DEFAULT_AUDIENCE = 'http://opencase.dk/service/api/1';
    private const VALID_FOR_API      = 'API';
    private const VALID_FOR_SIGNING  = 'Adgangsstyring';

    public function __construct(
        private IRequest $request,
        private ApiClientRepository $apiClientRepository,
        private Configuration $configuration,
        private IUserManager $userManager,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {}

    public function beforeController(Controller $controller, string $methodName): void {
        if (!$controller instanceof PublicDataApiController) {
            return;
        }

        $assertionDoc = $this->extractAssertion();
        $this->assertValidSignature($assertionDoc);

        $xpath = new \DOMXPath($assertionDoc);
        $xpath->registerNamespace('saml', self::NS_SAML);
        $xpath->registerNamespace('ds', self::NS_DS);

        $audience         = $this->firstXPathValue($xpath, '//saml:Conditions/saml:AudienceRestriction/saml:Audience');
        $expectedAudience = $this->configuration->getConfigValue('entity_id_opencase_api', self::DEFAULT_AUDIENCE);
        if ($audience === null || $audience !== $expectedAudience) {
            $this->logger->warning('Public data API request rejected: audience mismatch', [
                'app'      => 'opencase',
                'audience' => $audience,
            ]);
            throw new PublicDataApiAuthException('Invalid audience', Http::STATUS_UNAUTHORIZED);
        }

        $this->assertWithinValidityWindow($xpath);

        $certBase64 = $this->firstXPathValue(
            $xpath,
            '//saml:Subject/saml:SubjectConfirmation/saml:SubjectConfirmationData/ds:KeyInfo/ds:X509Data/ds:X509Certificate',
        );
        if ($certBase64 === null) {
            throw new PublicDataApiAuthException('Certificate not found in token', Http::STATUS_UNAUTHORIZED);
        }

        $fingerprint = $this->fingerprintFromBase64Certificate($certBase64);

        $client = $this->apiClientRepository->findActiveByFingerprintAndValidFor($fingerprint, self::VALID_FOR_API);
        if ($client === null) {
            $this->logger->warning('Public data API request rejected: certificate not registered for API', [
                'app'         => 'opencase',
                'fingerprint' => $fingerprint,
            ]);
            throw new PublicDataApiAuthException('Certificate not authorised', Http::STATUS_FORBIDDEN);
        }

        if ($client['expires_at'] !== null) {
            $expiresAt = new \DateTimeImmutable($client['expires_at']);
            if (new \DateTimeImmutable() > $expiresAt) {
                throw new PublicDataApiAuthException('API client certificate has expired', Http::STATUS_FORBIDDEN);
            }
        }

        $mappedToUser = $client['mapped_to_user'] ?? null;
        if (empty($mappedToUser)) {
            $this->logger->error('Public data API request rejected: client has no mapped_to_user', [
                'app'       => 'opencase',
                'client_id' => $client['id'],
            ]);
            throw new PublicDataApiAuthException('API client is not mapped to a user', Http::STATUS_FORBIDDEN);
        }

        $user = $this->userManager->get($mappedToUser);
        if ($user === null || !$user->isEnabled()) {
            $this->logger->error('Public data API request rejected: mapped_to_user is missing or disabled', [
                'app'            => 'opencase',
                'mapped_to_user' => $mappedToUser,
            ]);
            throw new PublicDataApiAuthException('Mapped Nextcloud user is not available', Http::STATUS_FORBIDDEN);
        }

        $this->userSession->setUser($user);
    }

    public function afterException(Controller $controller, string $methodName, \Exception $exception): Response {
        if (!($exception instanceof PublicDataApiAuthException)) {
            throw $exception;
        }

        return new JSONResponse(['error' => $exception->getMessage()], $exception->getStatusCode());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function extractAssertion(): \DOMDocument {
        $header = $this->request->getHeader('Authorization');
        if (stripos($header, 'Bearer ') !== 0) {
            throw new PublicDataApiAuthException('Bearer token required', Http::STATUS_UNAUTHORIZED);
        }

        $token = trim(substr($header, strlen('Bearer ')));
        if ($token === '') {
            throw new PublicDataApiAuthException('Bearer token required', Http::STATUS_UNAUTHORIZED);
        }

        $xml = base64_decode($token, true);
        if ($xml === false) {
            throw new PublicDataApiAuthException('Bearer token is not valid base64', Http::STATUS_UNAUTHORIZED);
        }

        $doc = new \DOMDocument();
        $previousUseErrors = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        // Only non-fatal libxml warnings (e.g. namespace/entity notices) can
        // show up here even for a document that parsed successfully — only
        // reject on load failure or an actual LIBXML_ERR_ERROR/FATAL entry.
        $fatalErrors = array_filter($errors, static fn (\LibXMLError $e) => $e->level >= LIBXML_ERR_ERROR);

        if (!$loaded || !empty($fatalErrors)) {
            $this->logger->warning('Public data API request rejected: failed to parse bearer token XML', [
                'app'    => 'opencase',
                'errors' => array_map(
                    static fn (\LibXMLError $e) => trim($e->message) . " (line {$e->line})",
                    $errors,
                ),
            ]);
            throw new PublicDataApiAuthException('Bearer token is not a valid SAML assertion', Http::STATUS_UNAUTHORIZED);
        }

        return $doc;
    }

    /**
     * Verify the assertion's XML signature against every active
     * 'Adgangsstyring' trust-anchor certificate (there may be more than one
     * during a certificate rotation window). Fails closed: if no trust
     * anchor is registered, or none of them validate the signature, the
     * request is rejected.
     */
    private function assertValidSignature(\DOMDocument $assertionDoc): void {
        $trustAnchors = $this->apiClientRepository->findAllActiveByValidFor(self::VALID_FOR_SIGNING);
        $certificates = array_filter(array_map(
            static fn (array $row) => $row['certificate'] ?? null,
            $trustAnchors,
        ));

        if (empty($certificates)) {
            $this->logger->error('Public data API request rejected: no active Adgangsstyring trust anchor certificate registered', [
                'app' => 'opencase',
            ]);
            throw new PublicDataApiAuthException('No trusted signing certificate configured', Http::STATUS_FORBIDDEN);
        }

        foreach ($certificates as $certificatePem) {
            try {
                if (Saml2Utils::validateSign($assertionDoc, $certificatePem)) {
                    return;
                }
            } catch (\Throwable $e) {
                // Signature didn't validate against this trust anchor — try the next one.
                continue;
            }
        }

        $this->logger->warning('Public data API request rejected: token signature did not validate against any registered Adgangsstyring certificate', [
            'app' => 'opencase',
        ]);
        throw new PublicDataApiAuthException('Token signature is invalid', Http::STATUS_UNAUTHORIZED);
    }

    private function assertWithinValidityWindow(\DOMXPath $xpath): void {
        $conditions = $xpath->query('//saml:Conditions');
        if ($conditions === false || $conditions->length === 0) {
            return;
        }

        /** @var \DOMElement $conditionsEl */
        $conditionsEl = $conditions->item(0);
        $now          = new \DateTimeImmutable();

        $notBeforeAttr = $conditionsEl->getAttribute('NotBefore');
        if ($notBeforeAttr !== '') {
            $notBefore = new \DateTimeImmutable($notBeforeAttr);
            if ($now < $notBefore) {
                throw new PublicDataApiAuthException('Token is not yet valid', Http::STATUS_UNAUTHORIZED);
            }
        }

        $notOnOrAfterAttr = $conditionsEl->getAttribute('NotOnOrAfter');
        if ($notOnOrAfterAttr !== '') {
            $notOnOrAfter = new \DateTimeImmutable($notOnOrAfterAttr);
            if ($now >= $notOnOrAfter) {
                throw new PublicDataApiAuthException('Token has expired', Http::STATUS_UNAUTHORIZED);
            }
        }
    }

    private function firstXPathValue(\DOMXPath $xpath, string $query): ?string {
        $result = $xpath->query($query);
        if ($result === false || $result->length === 0) {
            return null;
        }
        return trim($result->item(0)->textContent);
    }

    private function fingerprintFromBase64Certificate(string $certBase64): string {
        try {
            $pem = Certificate::formatAsPem($certBase64, 'CERTIFICATE');
        } catch (\InvalidArgumentException $e) {
            throw new PublicDataApiAuthException('Malformed certificate in token', Http::STATUS_UNAUTHORIZED);
        }

        $cert = openssl_x509_read($pem);
        if ($cert === false) {
            throw new PublicDataApiAuthException('Failed to parse certificate from token', Http::STATUS_UNAUTHORIZED);
        }

        $fingerprint = openssl_x509_fingerprint($cert, 'sha1');
        if ($fingerprint === false) {
            throw new PublicDataApiAuthException('Failed to compute certificate fingerprint', Http::STATUS_UNAUTHORIZED);
        }

        return strtolower(str_replace(':', '', $fingerprint));
    }
}
