<?php

namespace OCA\OpenCase\Service\Serviceplatformen;

use Exception;
use DOMDocument;
use DOMXPath;

/**
 * OrganisationFunctionWrapper - Wrapper for the Organisation Function SOAP service
 *
 * This class handles communication with the Organisation Function service using
 * a SAML token for authentication with holder-of-key confirmation.
 * The SOAP message is signed according to WS-Security requirements.
 */
class OrganisationFunctionWrapper extends ServiceplatformenWrapper
{
    private const SOAP_ACTION_SOEG = 'http://kombit.dk/sts/organisation/organisationfunktion/soeg';
    private const SOAP_ACTION_LIST = 'http://kombit.dk/sts/organisation/organisationfunktion/list';

    private OrganisationConfiguration $configuration;
    private SAMLToken $samlToken;
    private string $soapAction;

    public function __construct(OrganisationConfiguration $configuration, SAMLToken $token)
    {
        $this->configuration = $configuration;
        $this->samlToken = $token;
    }

    protected function getSoapAction(): string
    {
        return $this->soapAction ?? self::SOAP_ACTION_SOEG;
    }

    protected function getEndpoint(): string
    {
        return $this->configuration->getEndpoint();
    }

    public function soeg(string $UserUUIDIdentifikator): string
    {
        $this->soapAction = self::SOAP_ACTION_SOEG;
        $transactionUUID = $this->generateUUID();
        $messageId = 'urn:uuid:' . $this->generateUUID();

        $pemFiles = self::storeTempPemFiles($this->configuration->getClientCertificate());

        try {
            $soapEnvelope = $this->soegBuildSignedSoapEnvelope($transactionUUID, $messageId, $UserUUIDIdentifikator, $pemFiles);
            $response = $this->sendRequest($soapEnvelope, $pemFiles);
            return $this->extractSoegOutput($response);
        } finally {
            $this->cleanupPemFiles($pemFiles);
        }
    }

    public function list(array $UserUUIDIdentifikator): string
    {
        $this->soapAction = self::SOAP_ACTION_LIST;
        $transactionUUID = $this->generateUUID();
        $messageId = 'urn:uuid:' . $this->generateUUID();

        $pemFiles = self::storeTempPemFiles($this->configuration->getClientCertificate());

        try {
            $soapEnvelope = $this->listBuildSignedSoapEnvelope($transactionUUID, $messageId, $UserUUIDIdentifikator, $pemFiles);
            $response = $this->sendRequest($soapEnvelope, $pemFiles);
            return $this->extractListOutput($response);
        } finally {
            $this->cleanupPemFiles($pemFiles);
        }
    }

    private function extractSoegOutput(string $soapResponse): string
    {
        $doc = new DOMDocument();
        $doc->loadXML($soapResponse);

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('soap', 'http://www.w3.org/2003/05/soap-envelope');
        $xpath->registerNamespace('ns6', 'http://stoettesystemerne.dk/organisation/organisationfunktion/6/');

        $outputNodes = $xpath->query('//ns6:SoegOutput');
        if ($outputNodes->length === 0) {
            throw new Exception("SoegOutput not found in response");
        }

        return $doc->saveXML($outputNodes->item(0));
    }

    private function extractListOutput(string $soapResponse): string
    {
        $doc = new DOMDocument();
        $doc->loadXML($soapResponse);

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('soap', 'http://www.w3.org/2003/05/soap-envelope');
        $xpath->registerNamespace('ns6', 'http://stoettesystemerne.dk/organisation/organisationfunktion/6/');

        $outputNodes = $xpath->query('//ns6:ListOutput');
        if ($outputNodes->length === 0) {
            throw new Exception("ListOutput not found in response");
        }

        return $doc->saveXML($outputNodes->item(0));
    }

    private function soegBuildSignedSoapEnvelope(string $transactionUUID, string $messageId, string $UUIDIdentifikator, array $pemFiles): string
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
<a:Action s:mustUnderstand="1" u:Id="{$actionId}">{$this->escapeXml($this->soapAction)}</a:Action>
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
<SoegInput xmlns="http://stoettesystemerne.dk/organisation/organisationfunktion/6/">
<SoegRegistrering xmlns="urn:oio:sagdok:3.0.0"></SoegRegistrering>
<SoegVirkning xmlns="urn:oio:sagdok:3.0.0"></SoegVirkning>
<AttributListe xmlns="http://stoettesystemerne.dk/organisation/organisationfunktion/6/"></AttributListe>
<TilstandListe xmlns="http://stoettesystemerne.dk/organisation/organisationfunktion/6/"></TilstandListe>
<RelationListe xmlns="http://stoettesystemerne.dk/organisation/organisationfunktion/6/">
<Funktionstype xmlns="urn:oio:sagdok:3.0.0">
<ReferenceID xmlns="urn:oio:sagdok:3.0.0">
<UUIDIdentifikator>02e61900-33e0-407f-a2a7-22f70221f003</UUIDIdentifikator>
</ReferenceID>
</Funktionstype>
<TilknyttedeBrugere xmlns="urn:oio:sagdok:3.0.0">
<ReferenceID xmlns="urn:oio:sagdok:3.0.0">
<UUIDIdentifikator>{$UUIDIdentifikator}</UUIDIdentifikator>
</ReferenceID>
</TilknyttedeBrugere>
</RelationListe>
</SoegInput>
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

    private function listBuildSignedSoapEnvelope(string $transactionUUID, string $messageId, array $UUIDIdentifikators, array $pemFiles): string
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

        $uuidElements = implode("\n", array_map(
            fn(string $uuid) => "<UUIDIdentifikator xmlns='urn:oio:sagdok:3.0.0'>{$this->escapeXml($uuid)}</UUIDIdentifikator>",
            $UUIDIdentifikators
        ));

        // Build the envelope matching WCF structure (no BinarySecurityToken needed)
        $envelope = <<<XML
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:a="http://www.w3.org/2005/08/addressing" xmlns:u="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">
<s:Header>
<a:Action s:mustUnderstand="1" u:Id="{$actionId}">{$this->escapeXml($this->soapAction)}</a:Action>
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
<ListInput xmlns="http://stoettesystemerne.dk/organisation/organisationfunktion/6/">
{$uuidElements}
</ListInput>
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
