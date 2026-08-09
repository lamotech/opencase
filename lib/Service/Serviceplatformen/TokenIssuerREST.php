<?php

namespace OCA\OpenCase\Service\Serviceplatformen;

use OCA\OpenCase\AppInfo\Application;
use OCA\OpenCase\Service\Certificate;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\TraceLogger;
use OCP\IAppConfig;
use OCP\Server;
use Exception;

/**
 * TokenIssuerREST - WS-Trust SAML Token Issuer via REST API
 *
 * This implementation uses the REST API endpoint for token issuance with
 * client certificate authentication via TLS.
 *
 * Requirements:
 * - PHP 7.4 or higher
 * - OpenSSL extension
 * - cURL extension
 */
class TokenIssuerREST
{
    private const SAML_TOKEN_TYPE = "http://docs.oasis-open.org/wss/oasis-wss-saml-token-profile-1.1#SAMLV2.0";
    private const REQUEST_TYPE = "http://docs.oasis-open.org/ws-sx/ws-trust/200512/Issue";
    private const KEY_TYPE = "http://docs.oasis-open.org/ws-sx/ws-trust/200512/PublicKey";

    private const CACHE_KEY_PREFIX = 'token_cache_';
    // Stop serving a cached token this long before it actually expires, so a
    // token handed to a caller isn't at risk of expiring mid-use.
    private const EXPIRY_SAFETY_MARGIN = 60;

    /**
     * Issue a SAML token using the REST API
     *
     * @param string $entityId The service entity ID (AppliesTo)
     * @param Certificate $certificate The client certificate
     * @param Configuration $configuration The configuration service
     * @param TraceLogger $traceLogger The trace logger
     * @return SAMLToken The issued SAML token
     * @throws Exception
     */
    public static function issueToken(
        string $entityId,
        Certificate $certificate,
        Configuration $configuration,
        TraceLogger $traceLogger,
        ?string $cvrOverride = null,
    ): SAMLToken {
        // Get configuration
        $cvr = $cvrOverride ?? $configuration->getConfigValue('cvr', '11111111');

        // Tokens are valid for up to 8 hours; reuse a cached one per entityId+cvr
        // instead of issuing a fresh token on every call. Stored via IAppConfig
        // (DB-backed) rather than ICacheFactory, so this works even when no
        // memcached/redis distributed cache is configured.
        $appConfig = Server::get(IAppConfig::class);
        $cacheKey = self::CACHE_KEY_PREFIX . md5($entityId . '|' . $cvr);

        $cached = $appConfig->getValueString(Application::APP_ID, $cacheKey, '', lazy: true);
        if ($cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded) && ($decoded['expiresAt'] ?? 0) > time() && isset($decoded['token'])) {
                try {
                    return new SAMLToken(json_decode(base64_decode($decoded['token']), true) ?? []);
                } catch (Exception) {
                    // Corrupt/invalid cache entry — fall through and issue a fresh token
                }
            }
        }

        $tokenIssuerBaseUrl = $configuration->getConfigValue('token_issuer_base_url', 'https://n2adgangsstyring.eksterntest-stoettesystemerne.dk/');
        $tokenIssuerEndpoint = $configuration->getConfigValue('token_issuer_endpoint', '/runtime/api/rest/wstrust/v1/issue');

        // Build the REST API URL
        $apiUrl = rtrim($tokenIssuerBaseUrl, '/') . $tokenIssuerEndpoint;

		// Store certificate keys in tempora files for curl
        $pemFiles = self::storeTempPemFiles($certificate);
        try {
            // Build the request payload (needs pemFiles to extract certificate)
            $requestPayload = self::buildRequestPayload($entityId, $cvr, $certificate);

            // Send the REST request with client certificate authentication
            $response = self::sendRestRequest($apiUrl, $requestPayload, $pemFiles, $traceLogger);

            $token = new SAMLToken($response);

            $expiresAt = $token->getExpiresAt();
            if ($expiresAt !== null) {
                $safeExpiresAt = $expiresAt->getTimestamp() - self::EXPIRY_SAFETY_MARGIN;
                if ($safeExpiresAt > time()) {
                    $appConfig->setValueString(Application::APP_ID, $cacheKey, json_encode([
                        'token'     => $token->getBase64(),
                        'expiresAt' => $safeExpiresAt,
                    ]), lazy: true, sensitive: true);
                }
            }

            return $token;
        } finally {
            // Clean up temporary PEM files
            if (isset($pemFiles['cert']) && file_exists($pemFiles['cert'])) {
                unlink($pemFiles['cert']);
            }
            if (isset($pemFiles['key']) && file_exists($pemFiles['key'])) {
                unlink($pemFiles['key']);
            }
        }
    }

    /**
     * Build the request payload according to the API specification
     */
    private static function buildRequestPayload(string $appliesTo, string $cvr, Certificate $certificate): array
    {
        return [
            'AppliesTo' => [
                'EndpointReference' => [
                    'Address' => $appliesTo
                ]
            ],
            'KeyType' => self::KEY_TYPE,
            'RequestType' => self::REQUEST_TYPE,
            'TokenType' => self::SAML_TOKEN_TYPE,
            'Anvenderkontekst' => [
                'Cvr' => $cvr
            ],
            'UseKey' => $certificate->getPublicKeyBase64()
        ];
    }

    /**
     * Send the REST request with client certificate authentication
     */
    private static function sendRestRequest(string $url, array $payload, array $pemFiles, TraceLogger $traceLogger): array
    {
        $ch = curl_init($url);

        // Encode payload as JSON
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSLCERT => $pemFiles['cert'],
            CURLOPT_SSLCERTTYPE => 'PEM',
            CURLOPT_SSLKEY => $pemFiles['key'],
            CURLOPT_SSLKEYTYPE => 'PEM',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: ' . strlen($jsonPayload)
            ]
        ]);

        $traceLogger->trace('rest_request', [
            'url' => $url,
            'payload' => $payload,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $traceLogger->trace('rest_response', [
            'httpCode' => $httpCode,
            'response' => $response ? substr($response, 0, 1000) : null,
            'curlError' => $curlError ?: null,
        ]);

        if ($response === false || $curlError) {
            throw new Exception("REST request failed: " . $curlError);
        }

        if ($httpCode !== 200) {
            $errorMessage = "REST request returned HTTP {$httpCode}";
            if ($response) {
                $errorData = json_decode($response, true);
                if (isset($errorData['message'])) {
                    $errorMessage .= ": " . $errorData['message'];
                } else {
                    $errorMessage .= ". Response: " . substr($response, 0, 500);
                }
            }
            throw new Exception($errorMessage);
        }

        // Parse JSON response
        $responseData = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Failed to parse JSON response: " . json_last_error_msg());
        }

        return $responseData;
    }

    /**
     * Store certificate and key in temporary PEM files for cURL
     */
    private static function storeTempPemFiles(Certificate $certificate): array
    {
        // Create separate files for certificate and private key
        $uniqueId = uniqid();
        $certPath = sys_get_temp_dir() . '/rest_client_cert_' . $uniqueId . '.crt';
        $keyPath = sys_get_temp_dir() . '/rest_client_key_' . $uniqueId . '.key';

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
}
