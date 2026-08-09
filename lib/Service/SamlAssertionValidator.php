<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Exception\SamlValidationException;
use OneLogin\Saml2\Utils as Saml2Utils;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Validates inbound SAML messages before any identity in them is trusted.
 *
 * The assertion arriving at the ACS endpoint is encrypted to this SP's public
 * key — but that key is published in our own metadata, so encryption proves
 * nothing about who produced the assertion. Anyone can fetch the metadata,
 * craft an assertion naming any user with any privileges, encrypt it to us and
 * post it. Only the IdP's *signature* distinguishes a real assertion from a
 * forged one, so every check here fails closed.
 *
 * What is enforced:
 *   - an XML signature over the Response and/or the decrypted Assertion, made
 *     by one of the IdP's published signing certificates (at least one of the
 *     two must be signed and valid)
 *   - the signature actually covers the element we go on to read, with no
 *     second signature or duplicate ID to swap in (XML signature wrapping)
 *   - Response status is Success
 *   - Issuer is the IdP we configured
 *   - Destination / SubjectConfirmationData Recipient is our own ACS URL
 *   - Conditions NotBefore / NotOnOrAfter, within a small clock skew
 *   - AudienceRestriction names this SP
 *   - InResponseTo matches the AuthnRequest we sent, when we still have it
 *
 * Replay protection is the caller's job (it needs a cache): validateAssertion()
 * returns the assertion ID and expiry to key it on.
 */
class SamlAssertionValidator {

    private const NS_SAML  = 'urn:oasis:names:tc:SAML:2.0:assertion';
    private const NS_SAMLP = 'urn:oasis:names:tc:SAML:2.0:protocol';
    private const NS_DS    = 'http://www.w3.org/2000/09/xmldsig#';

    private const STATUS_SUCCESS = 'urn:oasis:names:tc:SAML:2.0:status:Success';

    private const ENVELOPED_SIGNATURE = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    /** Tolerated clock difference between this server and the IdP, in seconds. */
    private const CLOCK_SKEW = 300;

    public function __construct(
        private IdpMetadataService $idpMetadata,
        private Configuration $configuration,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
    ) {}

    // ── Public entry points ──────────────────────────────────────────────────

    /**
     * Validate the outer <samlp:Response> envelope.
     *
     * @param string|null $expectedRequestId ID of the AuthnRequest we sent, if
     *                                       we still have it in the session.
     * @return bool True when the Response itself carried a valid signature.
     * @throws SamlValidationException
     */
    public function validateResponse(\DOMDocument $doc, ?string $expectedRequestId): bool {
        $root = $doc->documentElement;
        if ($root === null
            || $root->localName !== 'Response'
            || $root->namespaceURI !== self::NS_SAMLP) {
            throw new SamlValidationException('SAML response is not a samlp:Response');
        }

        $signed = $this->verifySignature($doc, 'Response');

        $xpath = $this->xpath($doc);

        $status = $this->firstAttribute($xpath, '/samlp:Response/samlp:Status/samlp:StatusCode', 'Value');
        if ($status !== self::STATUS_SUCCESS) {
            $subStatus = $this->firstAttribute(
                $xpath,
                '/samlp:Response/samlp:Status/samlp:StatusCode/samlp:StatusCode',
                'Value',
            );
            throw new SamlValidationException(
                'SAML response status is not Success: ' . ($status ?? 'missing')
                . ($subStatus !== null ? ' / ' . $subStatus : '')
            );
        }

        $this->assertIssuer($xpath, '/samlp:Response/saml:Issuer', 'Response');

        // Destination, when present, must be the endpoint the message actually
        // arrived at — this is what stops a response minted for another SP (or
        // another endpoint of ours) being replayed here.
        $destination = $root->getAttribute('Destination');
        if ($destination !== '' && !$this->urlsMatch($destination, $this->acsUrl())) {
            throw new SamlValidationException(
                'SAML response Destination does not match our ACS URL: ' . $destination
            );
        }

        $inResponseTo = $root->getAttribute('InResponseTo');
        $this->assertInResponseTo($inResponseTo !== '' ? $inResponseTo : null, $expectedRequestId, 'Response');

        return $signed;
    }

    /**
     * Validate a decrypted <saml:Assertion>.
     *
     * @param string|null $expectedRequestId ID of the AuthnRequest we sent, if
     *                                       we still have it in the session.
     * @param bool $responseWasSigned Whether the enclosing Response carried a
     *                                valid signature. When it did, the
     *                                signature over the EncryptedAssertion
     *                                already authenticates this assertion.
     * @return array{id: string, notOnOrAfter: ?\DateTimeImmutable}
     * @throws SamlValidationException
     */
    public function validateAssertion(
        \DOMDocument $doc,
        ?string $expectedRequestId,
        bool $responseWasSigned,
    ): array {
        $root = $doc->documentElement;
        if ($root === null
            || $root->localName !== 'Assertion'
            || $root->namespaceURI !== self::NS_SAML) {
            throw new SamlValidationException('Decrypted SAML payload is not a saml:Assertion');
        }

        $assertionSigned = $this->verifySignature($doc, 'Assertion');

        if (!$assertionSigned && !$responseWasSigned) {
            throw new SamlValidationException(
                'SAML assertion is unsigned and the enclosing response was unsigned — refusing to trust it'
            );
        }

        $id = $root->getAttribute('ID');
        if ($id === '') {
            throw new SamlValidationException('SAML assertion has no ID');
        }

        $xpath = $this->xpath($doc);

        $this->assertIssuer($xpath, '/saml:Assertion/saml:Issuer', 'Assertion');

        $notOnOrAfter = $this->assertConditions($xpath);
        $this->assertAudience($xpath);
        $this->assertSubjectConfirmation($xpath, $expectedRequestId);

        return [
            'id'           => $id,
            'notOnOrAfter' => $notOnOrAfter,
        ];
    }

    // ── Signature verification ───────────────────────────────────────────────

    /**
     * Verify the enveloped signature on $doc's root element, if it has one.
     *
     * @return bool False when the document carries no signature at all; true
     *              when it carries one that validates.
     * @throws SamlValidationException when a signature is present but does not
     *         validate, or is shaped so that it doesn't actually cover the
     *         element we're about to read.
     */
    private function verifySignature(\DOMDocument $doc, string $what): bool {
        $xpath = $this->xpath($doc);

        $signatures = $xpath->query('//ds:Signature');
        if ($signatures === false || $signatures->length === 0) {
            return false;
        }

        // XML signature wrapping defence. A wrapped message smuggles a second,
        // legitimately signed fragment into the document and points the
        // signature at that, while the data we read comes from an unsigned
        // fragment. Requiring exactly one signature, sitting directly on the
        // element we parse and referencing that element's own ID, removes the
        // room to do it.
        if ($signatures->length !== 1) {
            throw new SamlValidationException(
                "SAML {$what} carries {$signatures->length} signatures; expected exactly one"
            );
        }

        /** @var \DOMElement $signature */
        $signature = $signatures->item(0);
        $root      = $doc->documentElement;

        if ($signature->parentNode !== $root) {
            throw new SamlValidationException(
                "SAML {$what} signature is not a direct child of the signed element"
            );
        }

        $rootId = $root->getAttribute('ID');
        if ($rootId === '') {
            throw new SamlValidationException("SAML {$what} has no ID for its signature to reference");
        }

        $this->assertReferencesRoot($xpath, $signature, $rootId, $what);

        $certificates = $this->idpMetadata->getSigningCertificates();
        if (empty($certificates)) {
            throw new SamlValidationException(
                'No IdP signing certificate available from metadata — cannot verify SAML signatures'
            );
        }

        foreach ($certificates as $certificate) {
            try {
                if (Saml2Utils::validateSign($doc, $certificate) === true) {
                    return true;
                }
            } catch (\Throwable $e) {
                // Did not validate against this certificate (or the signature
                // is structurally broken) — try the next trust anchor before
                // giving up, so key rotation doesn't break logins.
                $this->logger->debug(
                    'OpenCase: SAML {what} signature did not validate against a candidate IdP certificate: {msg}',
                    ['app' => 'opencase', 'what' => $what, 'msg' => $e->getMessage()],
                );
            }
        }

        throw new SamlValidationException(
            "SAML {$what} signature did not validate against any published IdP signing certificate"
        );
    }

    /**
     * Require the signature to have exactly one Reference, pointing at the
     * root element's own ID, with an enveloped-signature transform — and that
     * ID to be unique in the document.
     */
    private function assertReferencesRoot(
        \DOMXPath $xpath,
        \DOMElement $signature,
        string $rootId,
        string $what,
    ): void {
        $references = $xpath->query('./ds:SignedInfo/ds:Reference', $signature);
        if ($references === false || $references->length !== 1) {
            $count = $references === false ? 0 : $references->length;
            throw new SamlValidationException(
                "SAML {$what} signature has {$count} references; expected exactly one"
            );
        }

        /** @var \DOMElement $reference */
        $reference = $references->item(0);
        $uri       = $reference->getAttribute('URI');

        // Either the SAML-profile form (reference the element by its own ID)
        // or the whole-document form. The latter is safe against wrapping for
        // the same reason: with an enveloped transform its digest covers every
        // node in the document except the signature itself, so there is
        // nowhere to hide a substituted fragment.
        if ($uri !== '' && $uri !== '#' . $rootId) {
            throw new SamlValidationException(
                "SAML {$what} signature does not reference the signed element (URI={$uri})"
            );
        }

        $transforms = $xpath->query('./ds:Transforms/ds:Transform[@Algorithm="' . self::ENVELOPED_SIGNATURE . '"]', $reference);
        if ($transforms === false || $transforms->length === 0) {
            throw new SamlValidationException(
                "SAML {$what} signature is missing the enveloped-signature transform"
            );
        }

        // Two elements sharing the ID would make "which element did we verify"
        // ambiguous — xmlseclibs resolves the reference to whichever it finds
        // first, which need not be the one we parse.
        $sameId = $xpath->query('//*[@ID="' . $this->escapeXPathLiteral($rootId) . '"]');
        if ($sameId !== false && $sameId->length > 1) {
            throw new SamlValidationException(
                "SAML {$what} contains {$sameId->length} elements with ID {$rootId}"
            );
        }
    }

    // ── Assertion content checks ─────────────────────────────────────────────

    private function assertIssuer(\DOMXPath $xpath, string $query, string $what): void {
        $issuer = $this->firstValue($xpath, $query);
        if ($issuer === null) {
            throw new SamlValidationException("SAML {$what} has no Issuer");
        }

        $expected = $this->idpEntityId();
        if ($expected === '' ) {
            throw new SamlValidationException('IdP entityId is unknown — cannot check Issuer');
        }

        if ($issuer !== $expected) {
            throw new SamlValidationException(
                "SAML {$what} Issuer '{$issuer}' is not the configured IdP '{$expected}'"
            );
        }
    }

    /**
     * @return \DateTimeImmutable|null The assertion's NotOnOrAfter, if it has one.
     */
    private function assertConditions(\DOMXPath $xpath): ?\DateTimeImmutable {
        $conditions = $xpath->query('/saml:Assertion/saml:Conditions');
        if ($conditions === false || $conditions->length === 0) {
            throw new SamlValidationException('SAML assertion has no Conditions element');
        }

        /** @var \DOMElement $el */
        $el  = $conditions->item(0);
        $now = new \DateTimeImmutable();

        $notBefore = $this->parseInstant($el->getAttribute('NotBefore'), 'Conditions/@NotBefore');
        if ($notBefore !== null && $now < $notBefore->modify('-' . self::CLOCK_SKEW . ' seconds')) {
            throw new SamlValidationException('SAML assertion is not yet valid');
        }

        $notOnOrAfter = $this->parseInstant($el->getAttribute('NotOnOrAfter'), 'Conditions/@NotOnOrAfter');
        if ($notOnOrAfter !== null && $now >= $notOnOrAfter->modify('+' . self::CLOCK_SKEW . ' seconds')) {
            throw new SamlValidationException('SAML assertion has expired');
        }

        return $notOnOrAfter;
    }

    private function assertAudience(\DOMXPath $xpath): void {
        $audiences = $xpath->query(
            '/saml:Assertion/saml:Conditions/saml:AudienceRestriction/saml:Audience'
        );
        if ($audiences === false || $audiences->length === 0) {
            throw new SamlValidationException('SAML assertion has no AudienceRestriction');
        }

        $expected = $this->spEntityId();
        foreach ($audiences as $audience) {
            if (trim($audience->textContent) === $expected) {
                return;
            }
        }

        throw new SamlValidationException(
            "SAML assertion is not addressed to this service provider ({$expected})"
        );
    }

    private function assertSubjectConfirmation(\DOMXPath $xpath, ?string $expectedRequestId): void {
        $data = $xpath->query(
            '/saml:Assertion/saml:Subject/saml:SubjectConfirmation/saml:SubjectConfirmationData'
        );
        if ($data === false || $data->length === 0) {
            // Nothing to check — Conditions and the signature already bound the
            // assertion to us.
            return;
        }

        /** @var \DOMElement $el */
        $el  = $data->item(0);
        $now = new \DateTimeImmutable();

        $notOnOrAfter = $this->parseInstant(
            $el->getAttribute('NotOnOrAfter'),
            'SubjectConfirmationData/@NotOnOrAfter',
        );
        if ($notOnOrAfter !== null && $now >= $notOnOrAfter->modify('+' . self::CLOCK_SKEW . ' seconds')) {
            throw new SamlValidationException('SAML subject confirmation has expired');
        }

        $recipient = $el->getAttribute('Recipient');
        if ($recipient !== '' && !$this->urlsMatch($recipient, $this->acsUrl())) {
            throw new SamlValidationException(
                'SAML subject confirmation Recipient does not match our ACS URL: ' . $recipient
            );
        }

        $inResponseTo = $el->getAttribute('InResponseTo');
        $this->assertInResponseTo(
            $inResponseTo !== '' ? $inResponseTo : null,
            $expectedRequestId,
            'SubjectConfirmationData',
        );
    }

    /**
     * When we still hold the AuthnRequest ID, the message must answer it.
     *
     * If the session was lost we cannot check this; the signature, audience,
     * expiry and replay cache still apply, so the login is allowed through but
     * the gap is logged.
     */
    private function assertInResponseTo(?string $actual, ?string $expected, string $what): void {
        if ($expected === null || $expected === '') {
            if ($actual !== null) {
                // Expected in normal operation: the IdP's POST back to the ACS
                // endpoint is cross-site, so a SameSite=Lax session cookie is
                // not sent and the stored AuthnRequest ID is not visible here.
                // The signature, Issuer, audience, expiry and replay cache all
                // still apply.
                $this->logger->info(
                    'OpenCase: cannot check SAML {what} InResponseTo — no AuthnRequest ID in session',
                    ['app' => 'opencase', 'what' => $what],
                );
            }
            return;
        }

        if ($actual === null) {
            throw new SamlValidationException(
                "SAML {$what} has no InResponseTo but we sent AuthnRequest {$expected}"
            );
        }

        if (!hash_equals($expected, $actual)) {
            throw new SamlValidationException(
                "SAML {$what} InResponseTo '{$actual}' does not match our AuthnRequest '{$expected}'"
            );
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function xpath(\DOMDocument $doc): \DOMXPath {
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('saml', self::NS_SAML);
        $xpath->registerNamespace('samlp', self::NS_SAMLP);
        $xpath->registerNamespace('ds', self::NS_DS);
        return $xpath;
    }

    private function firstValue(\DOMXPath $xpath, string $query): ?string {
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }
        return trim($nodes->item(0)->textContent);
    }

    private function firstAttribute(\DOMXPath $xpath, string $query, string $attribute): ?string {
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }
        /** @var \DOMElement $el */
        $el = $nodes->item(0);
        $value = $el->getAttribute($attribute);
        return $value === '' ? null : $value;
    }

    private function parseInstant(string $value, string $what): ?\DateTimeImmutable {
        if ($value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new SamlValidationException("SAML {$what} is not a valid timestamp: {$value}");
        }
    }

    /**
     * Compare two absolute URLs for endpoint equality, ignoring only a
     * trailing slash. Deliberately strict: this check is what ties the message
     * to this endpoint.
     */
    private function urlsMatch(string $a, string $b): bool {
        return rtrim($a, '/') === rtrim($b, '/');
    }

    /** This SP's entityId, as published in our metadata. */
    private function spEntityId(): string {
        return $this->urlGenerator->getAbsoluteURL(
            $this->configuration->getConfigValue('entity_id', '/index.php/apps/opencase/saml/metadata')
        );
    }

    /** This SP's ACS URL, as published in our metadata. */
    private function acsUrl(): string {
        return $this->urlGenerator->getAbsoluteURL(
            $this->configuration->getConfigValue('acs_url', '/index.php/apps/opencase/saml/acs')
        );
    }

    private function idpEntityId(): string {
        $idp = $this->idpMetadata->getIdpSettingsFromMetadata();
        return (string)($idp['entityId'] ?? '');
    }

    /** Guard against an ID containing a quote breaking out of the XPath literal. */
    private function escapeXPathLiteral(string $value): string {
        return str_replace('"', '', $value);
    }
}
