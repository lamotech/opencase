<?php
declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\CertificateRepository;
use OCA\OpenCase\Enum\CertificateType;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Settings;
use OCP\IURLGenerator;
use OCP\ISession;
use OCA\OpenCase\Service\TraceLogger;

class SamlService {
	public function __construct(
		private IURLGenerator $urlGenerator,
		private ISession $session,
		private IdpMetadataService $idpMetadata,
		private CertificateRepository $certificateRepository,
		private Configuration $configuration,
		private TraceLogger $traceLogger,
	) {}

	private function buildSettings(): array {
		// Your SP endpoints from configuration (converted to absolute URLs)
		$entityIdPath = $this->configuration->getConfigValue('entity_id', '/index.php/apps/opencase/saml/metadata');
		$acsUrlPath = $this->configuration->getConfigValue('acs_url', '/index.php/apps/opencase/saml/acs');
		$slsUrlPath = $this->configuration->getConfigValue('sls_url', '/index.php/apps/opencase/saml/sls');

		$entityId = $this->urlGenerator->getAbsoluteURL($entityIdPath);
		$acsUrl = $this->urlGenerator->getAbsoluteURL($acsUrlPath);
		$slsUrl = $this->urlGenerator->getAbsoluteURL($slsUrlPath);

		// Load certificate from database (FKAccess type, falls back to Primary)
		$certificate = new Certificate(CertificateType::FKAccess, $this->certificateRepository);
		$spCert = $certificate->getPublicKeyBase64();
		$spKey = $certificate->getPrivateKeyBase64();


		// IdP settings from metadata (cached)
		$idp = $this->idpMetadata->getIdpSettingsFromMetadata();

		// OneLogin expects PEM without surrounding text issues; keep as-is.
		return [
			'strict' => true,
			'debug' => false,

			'sp' => [
				'entityId' => $entityId,
				'assertionConsumerService' => [
					'url' => $acsUrl,
					'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
				],
				'singleLogoutService' => [
					'url' => $slsUrl,
					'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
				],
				'x509cert' => $spCert,
				'privateKey' => $spKey,
			],

			'idp' => [
				'entityId' => $idp['entityId'],
				'singleSignOnService' => $idp['singleSignOnService'],
				'singleLogoutService' => $idp['singleLogoutService'],
				'x509cert' => $idp['x509cert'],          // or use x509certMulti
				// 'x509certMulti' => $idp['x509certMulti'] ?? null,
			],

			'security' => [
				// Tune these based on NemLogin3 requirements
				'authnRequestsSigned' => true,
				'logoutRequestSigned' => true,
				'logoutResponseSigned' => true,

				'wantAssertionsSigned' => true,
				'wantMessagesSigned' => true,

				// If IdP encrypts assertions, configure accordingly
				'wantAssertionsEncrypted' => true,
				'wantNameIdEncrypted' => true,

				// Allowed audience URIs for audience restriction validation
				// The SP entityId should be included to validate that assertions are intended for this SP
				'allowedAudienceUris' => [
					$entityId,
					// Add additional audience URIs if needed
				],

				// RequestedAuthnContext with Comparison='minimum' for NemLogin3
				// Note: OneLogin php-saml may not fully support Comparison='minimum'
				// If this doesn't work, you may need to extend the library or modify the AuthnRequest XML
				//'requestedAuthnContext' => [
				//	'https://data.gov.dk/concept/core/nsis/loa/Substantial',
				//],
				//'requestedAuthnContextComparison' => 'minimum',
			],
		];
	}

	private function auth(): Auth {
		return new Auth($this->buildSettings());
	}

	public function getSpMetadataXml(): string {
		$settings = new Settings($this->buildSettings(), true);
		$metadata = $settings->getSPMetadata();
		$errors = $settings->validateMetadata($metadata);
		if (!empty($errors)) {
			throw new \RuntimeException('Invalid SP metadata: ' . implode('; ', $errors));
		}
		return $metadata;
	}

	public function getLoginRedirectUrl(string $returnTo): string {
		$settings = new Settings($this->buildSettings(), true);
		$entityId = $this->urlGenerator->getAbsoluteURL('/index.php/apps/opencase/saml/metadata');

		$this->traceLogger->trace('SamlService - getLoginRedirectUrl', [
			'settings' => $settings,
			'entityId' => $entityId,
		]);	

		// Store return URL for after ACS
		$this->session->set('opencase.relay', $returnTo);

		// Get IdP SSO URL
		$idpData = $settings->getIdPData();
		$destination = $idpData['singleSignOnService']['url'];

		// Generate unique request ID
		$requestId = '_' . bin2hex(random_bytes(21));
		$issueInstant = gmdate('Y-m-d\TH:i:s\Z');

		// Build AuthnRequest XML matching C# implementation structure
		// Note: No embedded signature - for HTTP-Redirect binding, signature goes in query string
		$authnRequestXml = <<<XML
<samlp:AuthnRequest
	ID="{$requestId}"
	Version="2.0"
	IssueInstant="{$issueInstant}"
	Destination="{$destination}"
	ForceAuthn="false"
	IsPassive="false"
	xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol">
	<Issuer xmlns="urn:oasis:names:tc:SAML:2.0:assertion">{$entityId}</Issuer>
	<Conditions xmlns="urn:oasis:names:tc:SAML:2.0:assertion">
		<AudienceRestriction>
			<Audience>{$entityId}</Audience>
		</AudienceRestriction>
	</Conditions>
</samlp:AuthnRequest>
XML;

		$this->traceLogger->trace('SamlService - getLoginRedirectUrl', [
			'authnRequestXml' => $authnRequestXml,
		]);	

		// For HTTP-Redirect binding: deflate + base64 encode the UNSIGNED XML
		$deflated = gzdeflate($authnRequestXml);
		$samlRequest = base64_encode($deflated);

		// Build query string for signing (order matters!)
		// SAMLRequest + RelayState (if present) + SigAlg
		$queryParams = [];
		$queryParams['SAMLRequest'] = $samlRequest;

		if (!empty($returnTo)) {
			$queryParams['RelayState'] = $returnTo;
		}

		// Check if signing is required
		if ($settings->getSecurityData()['authnRequestsSigned']) {
			$spData = $settings->getSPData();
			$privateKey = $spData['privateKey'] ?? null;

			if (empty($privateKey)) {
				throw new \RuntimeException('Private key is missing from SP settings');
			}

			// Signature algorithm URI
			$sigAlg = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
			$queryParams['SigAlg'] = $sigAlg;

			// Build the string to sign (query string without Signature)
			$queryString = http_build_query($queryParams);

			// Format private key as PEM
			$privateKeyPem = Certificate::formatAsPem($privateKey, 'PRIVATE KEY');

			// Load private key and sign
			$privateKeyResource = openssl_pkey_get_private($privateKeyPem);
			if ($privateKeyResource === false) {
				throw new \RuntimeException('Failed to load private key: ' . openssl_error_string());
			}

			// Sign the query string using SHA256
			$signature = '';
			$success = openssl_sign($queryString, $signature, $privateKeyResource, OPENSSL_ALGO_SHA256);

			if (!$success) {
				throw new \RuntimeException('Failed to sign query string: ' . openssl_error_string());
			}

			// Add base64-encoded signature to query params
			$queryParams['Signature'] = base64_encode($signature);
		}

		// Build final URL
		$url = $destination . '?' . http_build_query($queryParams);

		// Store request id to validate InResponseTo (anti-replay)
		$this->session->set('opencase.request_id', $requestId);

		return $url;
	}

	/**
	 * @return array{uuid:string, displayName:string, attributes:array, nameId:string|null, sessionIndex:string|null}
	 */
	public function processAcs(): array {
		$auth = $this->auth();

		$requestId = $this->session->get('opencase.request_id');
		$auth->processResponse($requestId ?: null);

		$errors = $auth->getErrors();
		if (!empty($errors)) {
			throw new \RuntimeException('SAML ACS errors: ' . implode(', ', $errors));
		}
		if (!$auth->isAuthenticated()) {
			throw new \RuntimeException('SAML authentication failed (not authenticated)');
		}

		$attrs = $auth->getAttributes();

		// Get NameID (X509 Subject Name format)
		$nameId = $auth->getNameId();
		$sessionIndex = $auth->getSessionIndex();

		// Parse X509 Subject Name: C=DK,O=11111111,CN=Bruce Lee,Serial=f484ab2a-5fc7-4169-8641-611ce7836267
		$parsedNameId = $this->parseX509SubjectName($nameId);
		$serial = $parsedNameId['Serial'] ?? null;
		$cn = $parsedNameId['CN'] ?? null;

		// Use Serial as UUID (unique identifier), CN as display name
		$uuid = $serial ?? $this->firstAttr($attrs, 'uuid') ?? $nameId;
		$displayName = $cn ?? $this->firstAttr($attrs, 'name') ?? 'Unknown';

		if (!$uuid) {
			throw new \RuntimeException('Missing user UUID attribute / NameID');
		}

		// Save for SLO
		if ($nameId) $this->session->set('opencase.nameid', $nameId);
		if ($sessionIndex) $this->session->set('opencase.session_index', $sessionIndex);

		return [
			'uuid' => (string)$uuid,
			'displayName' => (string)$displayName,
			'serial' => $serial,
			'cn' => $cn,
			'attributes' => $attrs,
			'nameId' => $nameId,
			'sessionIndex' => $sessionIndex,
			'parsedNameId' => $parsedNameId,
		];
	}

	/**
	 * Parse X509 Subject Name format into key-value pairs
	 * Example: "C=DK,O=11111111,CN=Bruce Lee,Serial=f484ab2a-5fc7-4169-8641-611ce7836267"
	 * Returns: ['C' => 'DK', 'O' => '11111111', 'CN' => 'Bruce Lee', 'Serial' => 'f484ab2a-...']
	 */
	private function parseX509SubjectName(?string $subjectName): array {
		if (empty($subjectName)) {
			return [];
		}

		$result = [];

		// Split by comma, but handle values that might contain commas (quoted)
		// For simple cases without quotes, we can split by comma
		$parts = preg_split('/,(?=[A-Za-z]+=)/', $subjectName);

		foreach ($parts as $part) {
			$part = trim($part);
			$eqPos = strpos($part, '=');
			if ($eqPos !== false) {
				$key = trim(substr($part, 0, $eqPos));
				$value = trim(substr($part, $eqPos + 1));
				$result[$key] = $value;
			}
		}

		return $result;
	}

	/**
	 * Build a SAML LogoutRequest URL matching NemLogin3/KOMBIT format
	 * with HTTP-Redirect binding and query string signature
	 */
	public function buildLogoutRedirectUrl(?string $returnTo = null): string {
		$settings = new Settings($this->buildSettings(), true);
		$entityId = $this->urlGenerator->getAbsoluteURL('/index.php/apps/opencase/saml/metadata');

		// Get session data stored during login
		$nameId = $this->session->get('opencase.nameid');
		$sessionIndex = $this->session->get('opencase.session_index');

		if (empty($nameId)) {
			throw new \RuntimeException('No NameID found in session - cannot create LogoutRequest');
		}

		// Get IdP SLO URL
		$idpData = $settings->getIdPData();
		$sloService = $idpData['singleLogoutService'] ?? null;
		if (!$sloService || empty($sloService['url'])) {
			throw new \RuntimeException('IdP Single Logout Service URL not configured');
		}
		$destination = $sloService['url'];

		// Generate unique request ID
		$requestId = '_' . bin2hex(random_bytes(21));
		$issueInstant = gmdate('Y-m-d\TH:i:s\Z');

		// Store request ID for response validation
		$this->session->set('opencase.logout_request_id', $requestId);
		if ($returnTo) {
			$this->session->set('opencase.logout_relay', $returnTo);
		}

		// Build LogoutRequest XML matching the sample format
		// Note: saml2p/saml2 prefixes are equivalent to samlp/saml - IdP accepts both
		$sessionIndexElement = '';
		if (!empty($sessionIndex)) {
			$sessionIndexElement = "\n\t<saml2p:SessionIndex>{$sessionIndex}</saml2p:SessionIndex>";
		}

		$logoutRequestXml = <<<XML
<saml2p:LogoutRequest
	ID="{$requestId}"
	Version="2.0"
	IssueInstant="{$issueInstant}"
	Destination="{$destination}"
	xmlns:saml2p="urn:oasis:names:tc:SAML:2.0:protocol">
	<saml2:Issuer xmlns:saml2="urn:oasis:names:tc:SAML:2.0:assertion">{$entityId}</saml2:Issuer>
	<saml2:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:X509SubjectName" xmlns:saml2="urn:oasis:names:tc:SAML:2.0:assertion">{$nameId}</saml2:NameID>{$sessionIndexElement}
</saml2p:LogoutRequest>
XML;

		// For HTTP-Redirect binding: deflate + base64 encode the UNSIGNED XML
		$deflated = gzdeflate($logoutRequestXml);
		$samlRequest = base64_encode($deflated);

		// Build query string for signing (order matters!)
		$queryParams = [];
		$queryParams['SAMLRequest'] = $samlRequest;

		if (!empty($returnTo)) {
			$queryParams['RelayState'] = $returnTo;
		}

		// Sign the request if configured
		if ($settings->getSecurityData()['logoutRequestSigned']) {
			$spData = $settings->getSPData();
			$privateKey = $spData['privateKey'] ?? null;

			if (empty($privateKey)) {
				throw new \RuntimeException('Private key is missing from SP settings');
			}

			// Signature algorithm URI
			$sigAlg = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
			$queryParams['SigAlg'] = $sigAlg;

			// Build the string to sign (query string without Signature)
			$queryString = http_build_query($queryParams);

			// Format private key as PEM
			$privateKeyPem = Certificate::formatAsPem($privateKey, 'PRIVATE KEY');

			// Load private key and sign
			$privateKeyResource = openssl_pkey_get_private($privateKeyPem);
			if ($privateKeyResource === false) {
				throw new \RuntimeException('Failed to load private key: ' . openssl_error_string());
			}

			// Sign the query string using SHA256
			$signature = '';
			$success = openssl_sign($queryString, $signature, $privateKeyResource, OPENSSL_ALGO_SHA256);

			if (!$success) {
				throw new \RuntimeException('Failed to sign query string: ' . openssl_error_string());
			}

			// Add base64-encoded signature to query params
			$queryParams['Signature'] = base64_encode($signature);
		}

		// Build final URL
		return $destination . '?' . http_build_query($queryParams);
	}

	/**
	 * Legacy method using OneLogin library
	 * @deprecated Use buildLogoutRedirectUrl instead
	 */
	public function getLogoutRedirectUrl(?string $returnTo = null): string {
		return $this->buildLogoutRedirectUrl($returnTo);
	}

	/**
	 * Process SAML SLS (Single Logout Service) request
	 * Handles both LogoutResponse (SP-initiated logout reply) and LogoutRequest (IdP-initiated logout)
	 *
	 * @param string|null $samlResponse The SAMLResponse parameter (LogoutResponse)
	 * @param string|null $samlRequest The SAMLRequest parameter (LogoutRequest from IdP)
	 * @return array{type: string, success: bool, message: string, returnTo: ?string}
	 */
	public function processSls(?string $samlResponse, ?string $samlRequest): array {
		// Handle LogoutResponse (reply to our LogoutRequest)
		if (!empty($samlResponse)) {
			return $this->processLogoutResponse($samlResponse);
		}

		// Handle LogoutRequest (IdP-initiated logout)
		if (!empty($samlRequest)) {
			return $this->processLogoutRequest($samlRequest);
		}

		throw new \RuntimeException('No SAMLResponse or SAMLRequest found in SLS request');
	}

	/**
	 * Process LogoutResponse from IdP (reply to our LogoutRequest)
	 */
	private function processLogoutResponse(string $samlResponseB64): array {
		// Decode (HTTP-Redirect uses deflate+base64, HTTP-POST uses just base64)
		$decoded = base64_decode($samlResponseB64);

		// Try to inflate (HTTP-Redirect binding uses deflate)
		$inflated = @gzinflate($decoded);
		$xml = $inflated !== false ? $inflated : $decoded;

		$doc = new \DOMDocument();
		$doc->loadXML($xml);

		$xpath = new \DOMXPath($doc);
		$xpath->registerNamespace('samlp', 'urn:oasis:names:tc:SAML:2.0:protocol');
		$xpath->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');

		// Get InResponseTo and validate it matches our request
		$logoutResponse = $xpath->query('//samlp:LogoutResponse')->item(0);
		if (!$logoutResponse) {
			throw new \RuntimeException('Invalid LogoutResponse XML');
		}

		$inResponseTo = $logoutResponse->getAttribute('InResponseTo');
		$storedRequestId = $this->session->get('opencase.logout_request_id');

		if (!empty($storedRequestId) && $inResponseTo !== $storedRequestId) {
			throw new \RuntimeException('LogoutResponse InResponseTo does not match our request ID');
		}

		// Check status
		$statusCodeNode = $xpath->query('//samlp:Status/samlp:StatusCode', $logoutResponse)->item(0);
		$statusValue = $statusCodeNode ? $statusCodeNode->getAttribute('Value') : '';

		$success = $statusValue === 'urn:oasis:names:tc:SAML:2.0:status:Success';

		// Clear session data
		$returnTo = $this->session->get('opencase.logout_relay') ?: null;
		$this->clearSamlSessionData();

		return [
			'type' => 'logout_response',
			'success' => $success,
			'message' => $success ? 'Logout successful' : 'Logout failed: ' . $statusValue,
			'returnTo' => $returnTo,
		];
	}

	/**
	 * Process LogoutRequest from IdP (IdP-initiated logout)
	 * Returns the LogoutResponse URL to redirect back to IdP
	 */
	private function processLogoutRequest(string $samlRequestB64): array {
		// Decode
		$decoded = base64_decode($samlRequestB64);
		$inflated = @gzinflate($decoded);
		$xml = $inflated !== false ? $inflated : $decoded;

		$doc = new \DOMDocument();
		$doc->loadXML($xml);

		$xpath = new \DOMXPath($doc);
		$xpath->registerNamespace('samlp', 'urn:oasis:names:tc:SAML:2.0:protocol');
		$xpath->registerNamespace('saml2p', 'urn:oasis:names:tc:SAML:2.0:protocol');
		$xpath->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');
		$xpath->registerNamespace('saml2', 'urn:oasis:names:tc:SAML:2.0:assertion');

		// Get request ID from either samlp or saml2p namespace
		$logoutRequest = $xpath->query('//samlp:LogoutRequest | //saml2p:LogoutRequest')->item(0);
		if (!$logoutRequest) {
			throw new \RuntimeException('Invalid LogoutRequest XML');
		}

		$requestId = $logoutRequest->getAttribute('ID');

		// Clear Nextcloud session - the user should be logged out
		$this->clearSamlSessionData();

		// Build LogoutResponse
		$responseUrl = $this->buildLogoutResponse($requestId);

		return [
			'type' => 'logout_request',
			'success' => true,
			'message' => 'IdP-initiated logout processed',
			'returnTo' => $responseUrl,
		];
	}

	/**
	 * Build a LogoutResponse to send back to IdP
	 */
	private function buildLogoutResponse(string $inResponseTo): string {
		$settings = new Settings($this->buildSettings(), true);
		$entityId = $this->urlGenerator->getAbsoluteURL('/index.php/apps/opencase/saml/metadata');

		// Get IdP SLO URL
		$idpData = $settings->getIdPData();
		$sloService = $idpData['singleLogoutService'] ?? null;
		$destination = $sloService['url'] ?? '';

		// Generate response
		$responseId = '_' . bin2hex(random_bytes(21));
		$issueInstant = gmdate('Y-m-d\TH:i:s\Z');

		$logoutResponseXml = <<<XML
<samlp:LogoutResponse
	ID="{$responseId}"
	Version="2.0"
	IssueInstant="{$issueInstant}"
	Destination="{$destination}"
	InResponseTo="{$inResponseTo}"
	xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol">
	<saml:Issuer xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion">{$entityId}</saml:Issuer>
	<samlp:Status>
		<samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success" />
	</samlp:Status>
</samlp:LogoutResponse>
XML;

		// Deflate + base64
		$deflated = gzdeflate($logoutResponseXml);
		$samlResponse = base64_encode($deflated);

		$queryParams = ['SAMLResponse' => $samlResponse];

		// Sign if configured
		if ($settings->getSecurityData()['logoutResponseSigned']) {
			$spData = $settings->getSPData();
			$privateKey = $spData['privateKey'] ?? null;

			if (!empty($privateKey)) {
				$sigAlg = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
				$queryParams['SigAlg'] = $sigAlg;

				$queryString = http_build_query($queryParams);
				$privateKeyPem = Certificate::formatAsPem($privateKey, 'PRIVATE KEY');
				$privateKeyResource = openssl_pkey_get_private($privateKeyPem);

				if ($privateKeyResource !== false) {
					$signature = '';
					if (openssl_sign($queryString, $signature, $privateKeyResource, OPENSSL_ALGO_SHA256)) {
						$queryParams['Signature'] = base64_encode($signature);
					}
				}
			}
		}

		return $destination . '?' . http_build_query($queryParams);
	}

	/**
	 * Clear all SAML-related session data
	 */
	private function clearSamlSessionData(): void {
		$this->session->remove('opencase.nameid');
		$this->session->remove('opencase.session_index');
		$this->session->remove('opencase.saml_login');
		$this->session->remove('opencase.user_id');
		$this->session->remove('opencase.relay');
		$this->session->remove('opencase.request_id');
		$this->session->remove('opencase.logout_request_id');
		$this->session->remove('opencase.logout_relay');
	}

	private function firstAttr(array $attrs, string $key): ?string {
		if (!isset($attrs[$key]) || !is_array($attrs[$key]) || count($attrs[$key]) === 0) {
			return null;
		}
		return (string)$attrs[$key][0];
	}

}
