<?php

namespace OCA\OpenCase\Service\Serviceplatformen;

use OCA\OpenCase\Service\Certificate;
use Exception;
use DOMDocument;
use DOMXPath;

/**
 * ServiceplatformenWrapper - Abstract base class for Serviceplatformen SOAP services
 *
 * Handles WS-Security signing, PEM file management, and HTTP transport shared
 * by all Serviceplatformen service wrappers.
 */
abstract class ServiceplatformenWrapper
{
    protected const SOAP12_NAMESPACE = 'http://www.w3.org/2003/05/soap-envelope';
    protected const WSA_NAMESPACE = 'http://www.w3.org/2005/08/addressing';
    protected const WSSE_NAMESPACE = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
    protected const WSSE11_NAMESPACE = 'http://docs.oasis-open.org/wss/oasis-wss-wssecurity-secext-1.1.xsd';
    protected const WSU_NAMESPACE = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
    protected const DS_NAMESPACE = 'http://www.w3.org/2000/09/xmldsig#';
    protected const STR_TRANSFORM = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#STR-Transform';

    abstract protected function getSoapAction(): string;

    abstract protected function getEndpoint(): string;

    protected function extractAssertionId(string $assertion): string
    {
        if (preg_match('/ID="([^"]+)"/', $assertion, $matches)) {
            return $matches[1];
        }
        throw new Exception("Could not extract assertion ID from SAML token");
    }

    protected function signSoapEnvelope(string $envelope, array $pemFiles, array $ids): string
    {
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = true;
        $doc->loadXML($envelope);

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('s', self::SOAP12_NAMESPACE);
        $xpath->registerNamespace('o', self::WSSE_NAMESPACE);
        $xpath->registerNamespace('u', self::WSU_NAMESPACE);
        $xpath->registerNamespace('a', self::WSA_NAMESPACE);

        $securityNodes = $xpath->query('//o:Security');
        if ($securityNodes->length === 0) {
            throw new Exception("Security element not found");
        }
        $securityElement = $securityNodes->item(0);

        $privateKey = openssl_pkey_get_private(file_get_contents($pemFiles['key']));
        if (!$privateKey) {
            throw new Exception("Failed to load private key: " . openssl_error_string());
        }

        // Build references in the same order as WCF
        $references = [];

        // Body
        $bodyElement = $xpath->query("//s:Body[@u:Id='{$ids['bodyId']}']")->item(0);
        if ($bodyElement) {
            $references[] = ['uri' => '#' . $ids['bodyId'], 'digest' => $this->computeDigest($bodyElement)];
        }

        // Action
        $actionElement = $xpath->query("//a:Action[@u:Id='{$ids['actionId']}']")->item(0);
        if ($actionElement) {
            $references[] = ['uri' => '#' . $ids['actionId'], 'digest' => $this->computeDigest($actionElement)];
        }

        // MessageID
        $messageIdElement = $xpath->query("//a:MessageID[@u:Id='{$ids['messageIdId']}']")->item(0);
        if ($messageIdElement) {
            $references[] = ['uri' => '#' . $ids['messageIdId'], 'digest' => $this->computeDigest($messageIdElement)];
        }

        // ReplyTo
        $replyToElement = $xpath->query("//a:ReplyTo[@u:Id='{$ids['replyToId']}']")->item(0);
        if ($replyToElement) {
            $references[] = ['uri' => '#' . $ids['replyToId'], 'digest' => $this->computeDigest($replyToElement)];
        }

        // To
        $toElement = $xpath->query("//a:To[@u:Id='{$ids['toId']}']")->item(0);
        if ($toElement) {
            $references[] = ['uri' => '#' . $ids['toId'], 'digest' => $this->computeDigest($toElement)];
        }

        // Timestamp
        $timestampElement = $xpath->query("//u:Timestamp[@u:Id='{$ids['timestampId']}']")->item(0);
        if ($timestampElement) {
            $references[] = ['uri' => '#' . $ids['timestampId'], 'digest' => $this->computeDigest($timestampElement)];
        }

        // STR-Transform reference for the SecurityTokenReference (proves holder-of-key)
        $assertionElement = $xpath->query("//*[@ID='{$ids['assertionId']}']")->item(0);
        if ($assertionElement) {
            $references[] = [
                'uri' => '#' . $ids['strId'],
                'digest' => $this->computeDigest($assertionElement),
                'strTransform' => true
            ];
        }

        // Build SignedInfo (without namespace - will be added during signature building)
        $signedInfoContent = $this->buildSignedInfoForSignature($references);

        // KeyInfo references the SAML assertion via KeyIdentifier (matching WCF format)
        $keyInfoXml = '<KeyInfo>' .
            '<o:SecurityTokenReference b:TokenType="http://docs.oasis-open.org/wss/oasis-wss-saml-token-profile-1.1#SAMLV2.0" xmlns:o="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd" xmlns:b="http://docs.oasis-open.org/wss/oasis-wss-wssecurity-secext-1.1.xsd">' .
            '<o:KeyIdentifier ValueType="http://docs.oasis-open.org/wss/oasis-wss-saml-token-profile-1.1#SAMLID">' . $ids['assertionId'] . '</o:KeyIdentifier>' .
            '</o:SecurityTokenReference>' .
            '</KeyInfo>';

        // Build Signature element first (without signature value) to get proper namespace inheritance
        $signatureTemplate = '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">' .
            $signedInfoContent .
            '<SignatureValue></SignatureValue>' .
            $keyInfoXml .
            '</Signature>';

        // Parse the template and canonicalize SignedInfo with inherited namespace
        $sigTempDoc = new DOMDocument();
        $sigTempDoc->loadXML($signatureTemplate);
        $signedInfoNode = $sigTempDoc->getElementsByTagName('SignedInfo')->item(0);
        $canonicalSignedInfo = $signedInfoNode->C14N(true);

        $signature = '';
        if (!openssl_sign($canonicalSignedInfo, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new Exception("Failed to sign: " . openssl_error_string());
        }
        $signatureValue = base64_encode($signature);

        // Build final Signature element with actual signature value
        $signatureXml = '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">' .
            $signedInfoContent .
            '<SignatureValue>' . $signatureValue . '</SignatureValue>' .
            $keyInfoXml .
            '</Signature>';

        // Insert signature at the end of Security header
        $signatureDoc = new DOMDocument();
        $signatureDoc->loadXML($signatureXml);
        $signatureElement = $doc->importNode($signatureDoc->documentElement, true);
        $securityElement->appendChild($signatureElement);

        return $doc->saveXML();
    }

    protected function computeDigest(\DOMElement $element): string
    {
        $canonical = $element->C14N(true);
        return base64_encode(hash('sha256', $canonical, true));
    }

    protected function buildSignedInfoForSignature(array $references): string
    {
        $refsXml = '';
        foreach ($references as $ref) {
            // Note: CanonicalizationMethod inside TransformationParameters needs explicit namespace
            // because it's inside o: namespace context
            $transformXml = isset($ref['strTransform'])
                ? '<Transform Algorithm="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#STR-Transform">' .
                  '<o:TransformationParameters xmlns:o="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">' .
                  '<CanonicalizationMethod xmlns="http://www.w3.org/2000/09/xmldsig#" Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"></CanonicalizationMethod>' .
                  '</o:TransformationParameters>' .
                  '</Transform>'
                : '<Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"></Transform>';

            $refsXml .= '<Reference URI="' . $ref['uri'] . '">' .
                '<Transforms>' . $transformXml . '</Transforms>' .
                '<DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"></DigestMethod>' .
                '<DigestValue>' . $ref['digest'] . '</DigestValue>' .
                '</Reference>';
        }

        // No namespace here - it will inherit from parent Signature element
        return '<SignedInfo>' .
            '<CanonicalizationMethod Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"></CanonicalizationMethod>' .
            '<SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"></SignatureMethod>' .
            $refsXml .
            '</SignedInfo>';
    }

    protected function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    protected function sendRequest(string $soapEnvelope, array $pemFiles): string
    {
        $endpoint = $this->getEndpoint();

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $soapEnvelope,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/soap+xml; charset=utf-8; action="' . $this->getSoapAction() . '"',
                // Signed envelopes (with the inline SAML assertion) are large enough that
                // curl adds "Expect: 100-continue" by default. Some gateways don't handle
                // that handshake and process the request before the body arrives, which
                // looks like an empty request body server-side. Disable it explicitly.
                'Expect:',
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLCERT => $pemFiles['cert'],
            CURLOPT_SSLCERTTYPE => 'PEM',
            CURLOPT_SSLKEY => $pemFiles['key'],
            CURLOPT_SSLKEYTYPE => 'PEM',
        ]);

        /*
        $serviceCertPath = $this->configuration->getOrganisationServiceCertificatePath();
        if (!empty($serviceCertPath) && file_exists($serviceCertPath)) {
            curl_setopt($ch, CURLOPT_CAINFO, $serviceCertPath);
        }
        */

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);

        curl_close($ch);

        if ($errno !== 0) {
            throw new Exception("cURL error ({$errno}): {$error}");
        }

        if ($httpCode >= 400) {
            throw new Exception("HTTP error {$httpCode}: {$response}");
        }

        return $response;
    }

    /**
     * Convert PKCS#12 certificate to PEM format
     */
    protected static function storeTempPemFiles(Certificate $certificate): array
    {
        // Create separate files for certificate and private key
        $uniqueId = uniqid();
        $certPath = sys_get_temp_dir() . '/org_client_cert_' . $uniqueId . '.pem';
        $keyPath = sys_get_temp_dir() . '/org_client_key_' . $uniqueId . '.pem';

        // Write certificate file
        if (file_put_contents($certPath, $certificate->getPublicKey()) === false) {
            throw new Exception("Failed to write certificate file");
        }

        // Write private key file
        if (file_put_contents($keyPath, $certificate->getPrivateKey()) === false) {
            throw new Exception("Failed to write private key file");
        }

        return [
            'cert' => $certPath,
            'key' => $keyPath
        ];
    }

    protected function cleanupPemFiles(array $pemFiles): void
    {
        if (isset($pemFiles['cert']) && file_exists($pemFiles['cert'])) {
            unlink($pemFiles['cert']);
        }
        if (isset($pemFiles['key']) && file_exists($pemFiles['key'])) {
            unlink($pemFiles['key']);
        }
    }

    protected function generateUUID(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
