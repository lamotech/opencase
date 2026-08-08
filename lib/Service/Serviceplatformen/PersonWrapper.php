<?php

namespace OCA\OpenCase\Service\Serviceplatformen;

use Exception;
use DOMDocument;
use DOMXPath;

/**
 * PersonWrapper - Wrapper for the Person SOAP service
 *
 * This class handles communication with the Person service using
 * a SAML token for authentication with holder-of-key confirmation.
 * The SOAP message is signed according to WS-Security requirements.
 */
class PersonWrapper extends ServiceplatformenWrapper
{
    private const SOAP_ACTION = 'http://kombit.dk/sts/organisation/person/laes';

    private OrganisationConfiguration $configuration;
    private SAMLToken $samlToken;

    public function __construct(OrganisationConfiguration $configuration, SAMLToken $token)
    {
        $this->configuration = $configuration;
        $this->samlToken = $token;
    }

    protected function getSoapAction(): string
    {
        return self::SOAP_ACTION;
    }

    protected function getEndpoint(): string
    {
        return $this->configuration->getEndpoint();
    }

    public function laes(string $UUIDIdentifikator): string
    {
        $transactionUUID = $this->generateUUID();
        $messageId = 'urn:uuid:' . $this->generateUUID();

        $pemFiles = self::storeTempPemFiles($this->configuration->getClientCertificate());

        try {
            $soapEnvelope = $this->buildSignedSoapEnvelope($transactionUUID, $messageId, $UUIDIdentifikator, $pemFiles);
            $response = $this->sendRequest($soapEnvelope, $pemFiles);
            return $this->extractLaesOutput($response);
        } finally {
            $this->cleanupPemFiles($pemFiles);
        }
    }

    private function extractLaesOutput(string $soapResponse): string
    {
        $doc = new DOMDocument();
        $doc->loadXML($soapResponse);

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('soap', 'http://www.w3.org/2003/05/soap-envelope');
        $xpath->registerNamespace('ns6', 'http://stoettesystemerne.dk/organisation/person/6/');

        $outputNodes = $xpath->query('//ns6:LaesOutput');
        if ($outputNodes->length === 0) {
            throw new Exception("LaesOutput not found in response");
        }

        return $doc->saveXML($outputNodes->item(0));
    }

    private function buildSignedSoapEnvelope(string $transactionUUID, string $messageId, string $UUIDIdentifikator, array $pemFiles): string
    {
        $endpoint = $this->configuration->getEndpoint();
        $samlAssertion = $this->samlToken->getAssertion();

        // Get the assertion ID
        $assertionId = $this->extractAssertionId($samlAssertion);

        // Generate IDs matching WCF format
        $bodyId = '_1';
        $actionId = '_2';
        $messageIdId = '_3';
        $replyToId = '_4';
        $toId = '_5';
        $timestampId = 'uuid-' . $this->generateUUID() . '-3';
        // WCF format: _str followed by assertion ID (which starts with _)
        $strId = '_str' . $assertionId;

        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $expires = clone $now;
        $expires->modify('+5 minutes');
        $created = $now->format('Y-m-d\TH:i:s.v\Z');
        $expiresStr = $expires->format('Y-m-d\TH:i:s.v\Z');

        // Build the envelope matching WCF structure (no BinarySecurityToken needed)
        $envelope = <<<XML
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:a="http://www.w3.org/2005/08/addressing" xmlns:u="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">
<s:Header>
<a:Action s:mustUnderstand="1" u:Id="{$actionId}">{$this->escapeXml(self::SOAP_ACTION)}</a:Action>
<h:RequestHeader xmlns:h="http://kombit.dk/xml/schemas/RequestHeader/1/" xmlns="http://kombit.dk/xml/schemas/RequestHeader/1/"><TransactionUUID>{$this->escapeXml($transactionUUID)}</TransactionUUID></h:RequestHeader>
<a:MessageID u:Id="{$messageIdId}">{$this->escapeXml($messageId)}</a:MessageID>
<a:ReplyTo u:Id="{$replyToId}"><a:Address>http://www.w3.org/2005/08/addressing/anonymous</a:Address></a:ReplyTo>
<a:To s:mustUnderstand="1" u:Id="{$toId}">{$this->escapeXml($endpoint)}</a:To>
<o:Security s:mustUnderstand="1" xmlns:o="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
<u:Timestamp u:Id="{$timestampId}">
<u:Created>{$created}</u:Created>
<u:Expires>{$expiresStr}</u:Expires>
</u:Timestamp>
{$samlAssertion}
<o:SecurityTokenReference b:TokenType="http://docs.oasis-open.org/wss/oasis-wss-saml-token-profile-1.1#SAMLV2.0" u:Id="{$strId}" xmlns:b="http://docs.oasis-open.org/wss/oasis-wss-wssecurity-secext-1.1.xsd">
<o:KeyIdentifier ValueType="http://docs.oasis-open.org/wss/oasis-wss-saml-token-profile-1.1#SAMLID">{$assertionId}</o:KeyIdentifier>
</o:SecurityTokenReference>
</o:Security>
</s:Header>
<s:Body u:Id="{$bodyId}">
<LaesInput xmlns="http://stoettesystemerne.dk/organisation/person/6/">
<UUIDIdentifikator xmlns="urn:oio:sagdok:3.0.0">{$UUIDIdentifikator}</UUIDIdentifikator>
</LaesInput>
</s:Body>
</s:Envelope>
XML;

        return $this->signSoapEnvelope($envelope, $pemFiles, [
            'bodyId' => $bodyId,
            'actionId' => $actionId,
            'messageIdId' => $messageIdId,
            'replyToId' => $replyToId,
            'toId' => $toId,
            'timestampId' => $timestampId,
            'strId' => $strId,
            'assertionId' => $assertionId
        ]);
    }
}
