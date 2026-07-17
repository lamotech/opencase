<?php

namespace OCA\OpenCase\Service\Serviceplatformen;

use Exception;

/**
 * SAMLToken - Represents a SAML 2.0 Security Token
 *
 * This class encapsulates the SAML token response from the STS service,
 * providing convenient access to the assertion and token metadata.
 */
class SAMLToken
{
    private array $rawResponse;
    private string $assertion;
    private string $appliesTo;
    private string $keyType;
    private string $lifetime;
    private string $requestType;
    private string $tokenType;

    /**
     * Create a SAMLToken from the REST API response
     *
     * @param array $response The response array from the REST API
     * @throws Exception
     */
    public function __construct(array $response)
    {
        // Validate required fields
        if (!isset($response['RequestedSecurityToken']['Assertion'])) {
            throw new Exception("No assertion found in token response");
        }

        // Extract and decode the base64-encoded assertion
        $base64Assertion = $response['RequestedSecurityToken']['Assertion'];
        $assertionXml = base64_decode($base64Assertion, true);

        if ($assertionXml === false) {
            throw new Exception("Failed to decode base64 assertion");
        }

        $this->rawResponse = $response;
        $this->assertion = $assertionXml;

        // Extract metadata
        $this->appliesTo = $response['AppliesTo']['EndpointReference']['Address'] ?? '';
        $this->keyType = $response['KeyType'] ?? '';
        $this->lifetime = $response['Lifetime'] ?? '';
        $this->requestType = $response['RequestType'] ?? '';
        $this->tokenType = $response['TokenType'] ?? '';
    }

    /**
     * Get the SAML assertion XML
     *
     * @return string The decoded SAML assertion as XML string
     */
    public function getAssertion(): string
    {
        return $this->assertion;
    }

    /**
     * Get the base64-encoded SAML assertion
     *
     * @return string The base64-encoded assertion
     */
    public function getAssertionBase64(): string
    {
        return base64_encode($this->assertion);
    }

    public function getBase64(): string
    {
        return base64_encode(json_encode($this->rawResponse));
    }

    /**
     * Get the AppliesTo endpoint address
     *
     * @return string The service endpoint this token applies to
     */
    public function getAppliesTo(): string
    {
        return $this->appliesTo;
    }

    /**
     * Get the key type
     *
     * @return string The key type URI
     */
    public function getKeyType(): string
    {
        return $this->keyType;
    }

    /**
     * Get the token lifetime
     *
     * @return string The token expiration timestamp
     */
    public function getLifetime(): string
    {
        return $this->lifetime;
    }

    /**
     * Get the request type
     *
     * @return string The request type URI
     */
    public function getRequestType(): string
    {
        return $this->requestType;
    }

    /**
     * Get the token type
     *
     * @return string The token type URI (SAML 2.0)
     */
    public function getTokenType(): string
    {
        return $this->tokenType;
    }

    /**
     * Convert the token to a string representation (returns the assertion XML)
     *
     * @return string The SAML assertion XML
     */
    public function __toString(): string
    {
        return $this->assertion;
    }

    /**
     * Get all token metadata as an associative array
     *
     * @return array Token metadata
     */
    public function getMetadata(): array
    {
        return [
            'AppliesTo' => $this->appliesTo,
            'KeyType' => $this->keyType,
            'Lifetime' => $this->lifetime,
            'RequestType' => $this->requestType,
            'TokenType' => $this->tokenType
        ];
    }
}
