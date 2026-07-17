<?php
declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\IRequest;
use OCA\OpenCase\Db\CertificateRepository;
use OCA\OpenCase\Db\RoleMapper;
use OCA\OpenCase\Db\UserRoleMapper;
use OCA\OpenCase\Enum\CertificateType;
use OCA\OpenCase\Service\Certificate;
use OCA\OpenCase\Service\SamlService;
use OCA\OpenCase\Service\SamlMetadataService;
use OCA\OpenCase\Service\PrivilegeService;
use OCA\OpenCase\Service\TraceLogger;
use OCA\OpenCase\Service\UserSyncService;
use OCP\IURLGenerator;
use OCP\ISession;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Authentication\Token\IToken;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\User\Events\UserFirstTimeLoggedInEvent;
use OCP\IGroupManager;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use OCA\OpenCase\Service\Configuration as OpenCaseConfiguration;
/**
 * These endpoints must be callable by the IdP, so they are public and no CSRF.
 */
class SamlController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private SamlService $samlService,
		private SamlMetadataService $samlMetadataService,
		private IURLGenerator $urlGenerator,
		private ISession $session,
		private IUserManager $userManager,
		private IUserSession $userSession,
		private readonly IEventDispatcher $eventDispatcher,
		private CertificateRepository $certificateRepository,
		private IGroupManager $groupManager,
		private RoleMapper $roleMapper,
		private UserRoleMapper $userRoleMapper,
		private TraceLogger $traceLogger,
		private UserSyncService $userSyncService,
		private PrivilegeService $privilegeService,
		private IConfig $systemConfig,
		private OpenCaseConfiguration $openCaseConfig,
		private ICacheFactory $cacheFactory,
		private IConfig $config,
		private ISecureRandom $secureRandom,		
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function login(): RedirectResponse {
		$this->traceLogger->trace('SamlController - login');		

		$params = $this->request->getParams();
		$returnTo = '';
	
		if (isset($params['redirect_url']) && is_string($params['redirect_url']) && $params['redirect_url'] !== '') {
			// Nextcloud redirect_url might be a relative path; make it absolute if needed
			$redirectUrl = $params['redirect_url'];
			$returnTo = str_starts_with($redirectUrl, 'http://') || str_starts_with($redirectUrl, 'https://')
				? $redirectUrl
				: $this->urlGenerator->getAbsoluteURL($redirectUrl);
		} else {
			$returnTo = $this->urlGenerator->linkToRouteAbsolute('dashboard.dashboard.index');
		}
	
		$url = $this->samlService->getLoginRedirectUrl($returnTo);
		$this->traceLogger->trace('SamlController: redirecting_to_idp', [
			'returnTo' => $returnTo,
			'redirectUrlParam' => $params['redirect_url'] ?? null,
			'finalRedirectUrl' => $url,
		]);
		return new RedirectResponse($url);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function metadata(): Response {
		$xml = $this->samlMetadataService->createSAMLMetadata();

		$response = new class($xml) extends Response implements ICallbackResponse {
			private string $content;

			public function __construct(string $content) {
				parent::__construct();
				$this->content = $content;
				$this->addHeader('Content-Type', 'application/xml; charset=utf-8');
				$this->addHeader('Content-Disposition', 'attachment; filename="metadata.xml"');
			}

			public function callback(IOutput $output): void {
				echo $this->content;
			}
		};

		return $response;
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function acs(): Response {
		// Log the raw SAML token for debugging
		$samlResponseB64 = $this->request->getParam('SAMLResponse', '');
		$relayState = $this->request->getParam('RelayState', '');
		$samlResponseXml = $samlResponseB64 ? base64_decode($samlResponseB64) : '';

		$this->traceLogger->trace('SamlController - acs', [
			'SAMLResponse_base64' => $samlResponseB64,
			'SAMLResponse_decoded' => $samlResponseXml,
			'RelayState' => $relayState,
		]);	

		// Prevent duplicate/replay SAML assertion processing
		// Use a hash of the SAML response as a one-time token
		$responseHash = hash('sha256', $samlResponseB64);
		$cacheKey = 'saml_processed_' . $responseHash;
		$cache = $this->cacheFactory->createDistributed('opencase');

		// Check if this exact SAML response was already processed
		if ($cache->get($cacheKey) !== null) {
			$this->traceLogger->trace('duplicate_saml_response_blocked', [
				'responseHash' => substr($responseHash, 0, 16) . '...',
			]);

			// Redirect to dashboard - the previous request should have set up the session
			return new RedirectResponse($this->urlGenerator->linkToRouteAbsolute('dashboard.dashboard.index'));
		}

		// Mark this response as being processed (with 60 second TTL)
		$cache->set($cacheKey, time(), 60);

		$logData = [
			'timestamp' => date('Y-m-d H:i:s'),
			'SAMLResponse_base64' => $samlResponseB64,
			'SAMLResponse_decoded' => $samlResponseXml,
			'RelayState' => $relayState,
		];

		// Try to decrypt the EncryptedAssertion
		try {
			$decryptedAssertion = $this->decryptSamlAssertion($samlResponseXml);
			$logData['DecryptedAssertion'] = $decryptedAssertion;
		} catch (\Exception $e) {
			$logData['DecryptionError'] = $e->getMessage();
		}

		// Parse the decrypted assertion directly (bypass OneLogin library which can't handle RSA-OAEP SHA256)
		if (!isset($decryptedAssertion)) {
			throw new \RuntimeException('Failed to decrypt SAML assertion');
		}

		$principal = $this->parseDecryptedAssertion($decryptedAssertion);
		$this->traceLogger->trace('principal_parsed', $principal);

		// Check user privileges from the assertion
		$privilege = $this->getUserPrivilege($principal);
		$this->traceLogger->trace('privilege', $privilege);

		// Create Nextcloud user session
		$uuid = $principal['uuid'] ?? null;
		if (!$uuid) {
			throw new \RuntimeException('No UUID found in SAML assertion');
		}

		// User ID format: opencase_ + uuid
		$userId = 'opencase_' . $uuid;
		$displayName = $principal['displayName'] ?? $userId;

		// Look up the user
		$user = $this->userManager->get($userId);
		$userExists = ($user !== null);

		// Log privilege check
		$this->traceLogger->trace('privilege_check', [
			'userId' => $userId,
			'userExists' => $userExists,
			'isUser' => $privilege['isUser'],
			'isAdministrator' => $privilege['isAdministrator'],
			'grantedOrganisations' => $privilege['grantedOrganisations'],
		]);

		// Handle user access based on privilege
		if ($userExists && !$privilege['isUser']) {
			// User exists but no longer has access - deactivate and show no access page
			$user->setEnabled(false);
			$this->traceLogger->trace('user_deactivated', [
				'userId' => $userId,
				'reason' => 'isUser=false',
			]);
			return $this->showNoAccessPage();
		}

		if (!$userExists && !$privilege['isUser']) {
			// User doesn't exist and has no access - show no access page
			$this->traceLogger->trace('access_denied', [
				'userId' => $userId,
				'reason' => 'user does not exist and isUser=false',
			]);
			return $this->showNoAccessPage();
		}

		// Track if this is a new user - we'll need to initialize their storage after login
		$isNewUser = false;

		if (!$userExists && $privilege['isUser']) {
			// User doesn't exist but has access - create the user
			$user = $this->createNextcloudUser($userId, $displayName);
			$isNewUser = true;
			$this->traceLogger->trace('user_created', [
				'userId' => $userId,
				'displayName' => $displayName,
			]);
		}

		// At this point: user exists and isUser=true - continue login flow
		// Re-enable user if they were previously disabled
		if (!$user->isEnabled()) {
			$user->setEnabled(true);
			$this->traceLogger->trace('user_reactivated', [
				'userId' => $userId,
			]);
		}

		// Update display name if changed
		if ($user->getDisplayName() !== $displayName) {
			$user->setDisplayName($displayName);
			$this->traceLogger->trace('displayname_updated', [
				'userId' => $userId,
				'displayName' => $displayName,
			]);
		}

		// Update user info
		$this->userSyncService->updateUser($uuid);

		// Sync administrator group membership
		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup !== null) {
			if ($privilege['isAdministrator'] && !$adminGroup->inGroup($user)) {
				$adminGroup->addUser($user);
				$this->traceLogger->trace('admin_group_added', [
					'userId' => $userId,
				]);
			} elseif (!$privilege['isAdministrator'] && $adminGroup->inGroup($user)) {
				$adminGroup->removeUser($user);
				$this->traceLogger->trace('admin_group_removed', [
					'userId' => $userId,
				]);
			}
		}

		// Sync OpenCase user roles
		$isOpenCaseUser = $privilege['isOpenCaseUser'];
		$isOpenCaseAdministrator = $privilege['isOpenCaseAdministrator'];
		$isTemplateAdministrator = $privilege['isTemplateAdministrator'];

		$this->syncOpenCaseRoles($userId, $isOpenCaseUser, $isOpenCaseAdministrator, $isTemplateAdministrator);

		// Sync privilege constraints and rebuild access-profile grants
		$this->privilegeService->syncPrivileges($userId, $privilege['privilegeGroups']);

		// Sync organisation group memberships
		$grantedGroupIds = array_map(fn(string $uuid) => 'org_' . $uuid, $privilege['grantedOrganisations']);
		$userGroups = $this->groupManager->getUserGroups($user);
		// Remove from org_ groups the user should no longer be in
		foreach ($userGroups as $group) {
			$groupId = $group->getGID();
			if (str_starts_with($groupId, 'org_') && !in_array($groupId, $grantedGroupIds)) {
				$group->removeUser($user);
				$this->traceLogger->trace('org_group_removed', [
					'userId' => $userId,
					'groupId' => $groupId,
				]);
			}
		}
		// Add to granted org_ groups
		foreach ($grantedGroupIds as $groupId) {
			$group = $this->groupManager->get($groupId);
			if ($group === null) {
				$group = $this->groupManager->createGroup($groupId);
			}
			if ($group !== null && !$group->inGroup($user)) {
				$group->addUser($user);
				$this->traceLogger->trace('org_group_added', [
					'userId' => $userId,
					'groupId' => $groupId,
				]);
			}
		}

		// Log before login attempt
		$this->traceLogger->trace('login_attempt', [
			'userId' => $userId,
			'userExists' => true,
			'userEnabled' => $user->isEnabled(),
		]);

		// Initialize relay before try block
		$savedRelay = '';

		try {
			// Use Nextcloud's internal session manager for proper session persistence
			$userSession = $this->userSession;
			$secureRandom = $this->secureRandom;			

			// Store the old session ID for debugging
			$oldSessionId = session_id();

			// IMPORTANT: Call completeLogin FIRST - it regenerates the session
			// We need to create the token AFTER this with the new session ID
			$loginResult = $userSession->completeLogin(
				$user,
				[
					'loginName' => $userId,
					'password' => '',
				]
			);

			$afterCompleteLoginSessionId = session_id();

			// Prepare "remember me" cookies - these are what Nextcloud uses to persist login
			// Get cookie lifetime from config (default 15 days like standard Nextcloud)
			$rememberMeDuration = $this->config->getSystemValueInt('remember_login_cookie_lifetime', 60 * 60 * 24 * 15);
			$cookieExpires = time() + $rememberMeDuration;
			$secureCookie = $this->request->getServerProtocol() === 'https';
			$cookiePath = \OC::$WEBROOT ? \OC::$WEBROOT . '/' : '/';

			// Store session info for the callback response
			$sessionName = session_name();
			$newSessionId = session_id();

			// Set the user
			$userSession->setUser($user);
			\OC_User::setUserId($userId);

			// Create a passwordless session token (null password) so Nextcloud's 5-minute
			// credential re-check (checkTokenCredentials) hits the PasswordlessTokenException
			// path and skips checkPassword. A random password would cause checkPassword to
			// fail every 5 minutes, logging the user out and clearing the nc_* cookies.
			$tokenCreated = $userSession->createSessionToken(
				$this->request,
				$userId,
				$userId,
				null,            // passwordless — SAML has no NC password to store
				IToken::REMEMBER // persist the session across browser restarts
			);

			// Mark the session token so Nextcloud's JS skips the password-confirmation
			// prompt (the "sudo mode" dialog). SAML users have no NC password, so the
			// prompt can never succeed. Setting SCOPE_SKIP_PASSWORD_VALIDATION=true
			// makes JSConfigHelper set backendAllowsPasswordConfirmation=false, which
			// hides the prompt entirely in personal settings and other sensitive pages.
			try {
				$tokenProvider = \OC::$server->get(\OC\Authentication\Token\IProvider::class);
				$sessionToken = $tokenProvider->getToken(session_id());
				$sessionToken->setScope([
					IToken::SCOPE_SKIP_PASSWORD_VALIDATION => true,
					IToken::SCOPE_FILESYSTEM               => true,
				]);
				$tokenProvider->updateToken($sessionToken);
			} catch (\Exception $e) {
				// Non-fatal: the prompt may still appear, but login is unaffected.
				$this->traceLogger->trace('scope_skip_password_validation_failed', ['error' => $e->getMessage()]);
			}

			$afterTokenSessionId = session_id();

			// Set up "remember me" cookies for loginWithCookie() to work
			// Use createRememberMeToken which: generates token, stores in DB, sets cookies
			if ($userSession instanceof \OC\User\Session) {
				// createRememberMeToken does everything: generate, store, set cookies
				$userSession->createRememberMeToken($user);

				// Verify storage
				$ncConfig = $this->config;
				$storedTokens = $ncConfig->getUserKeys($userId, 'login_token');

				$this->traceLogger->trace('remember_token_created', [
					'userId' => $userId,
					'sessionId' => session_id(),
					'storedTokensCount' => count($storedTokens),
				]);
			} else {
				// Fallback if we can't access the concrete class
				$currentSessionId = session_id();
				$secureCookie = $this->request->getServerProtocol() === 'https';
				$rememberExpires = time() + $rememberMeDuration;
				$webroot = \OC::$WEBROOT ?: '';

				// Generate and store token manually
				$rememberToken = $secureRandom->generate(32);
				$ncConfig = $this->config;
				$ncConfig->setUserValue($userId, 'login_token', $rememberToken, (string)time());

				setcookie('nc_username', $userId, $rememberExpires, $webroot . '/', '', $secureCookie, true);
				setcookie('nc_token', $rememberToken, $rememberExpires, $webroot . '/', '', $secureCookie, true);
				setcookie('nc_session_id', $currentSessionId, $rememberExpires, $webroot . '/', '', $secureCookie, true);

				$this->traceLogger->trace('manual_cookies_set_fallback', [
					'userId' => $userId,
					'sessionId' => $currentSessionId,
					'rememberToken' => $rememberToken,
				]);
			}

			// Store in Nextcloud session that this is a SAML login
			// Do this AFTER all login operations to ensure session is stable
			$this->session->set('opencase.saml_login', true);
			$this->session->set('opencase.user_id', $userId);

			// For new users, initialize their storage AFTER login is complete
			// This ensures the filesystem is set up in the correct user context
			if ($isNewUser) {
				$this->initializeUserStorage($userId, $user);
				$this->traceLogger->trace('storage_initialized_post_login', [
					'userId' => $userId,
				]);
			}

			// Get the relay state BEFORE we close the session
			$savedRelay = $this->session->get('opencase.relay') ?: '';

			// Check if login was successful BEFORE we close the session
			$isLoggedIn = $userSession->isLoggedIn();
			$currentUser = $userSession->getUser();

			// Get the current session ID for logging
			$finalSessionId = session_id();

			// Log all relevant info
			$this->traceLogger->trace('login_result', [
				'tokenCreated' => $tokenCreated,
				'loginResult' => $loginResult,
				'isLoggedIn' => $isLoggedIn,
				'currentUserId' => $currentUser ? $currentUser->getUID() : null,
				'oldSessionId' => $oldSessionId,
				'afterCompleteLoginSessionId' => $afterCompleteLoginSessionId,
				'afterTokenSessionId' => $afterTokenSessionId,
				'finalSessionId' => $finalSessionId,
				'sessionName' => session_name(),
				'ncSessionId' => $this->session->getId(),
				'headers_sent' => headers_sent(),
				'cookies_from_request' => array_keys($_COOKIE),
				'rememberMeDays' => $rememberMeDuration / 86400,
			]);

		} catch (\Exception $e) {
			$this->traceLogger->error('login_error', $e);
			throw $e;
		}

		// Determine where to redirect (use savedRelay since session is closed)
		$returnTo = (string)($this->request->getParam('RelayState', '') ?: $savedRelay ?: '');

		// Ensure we redirect to a local path, not back to IdP
		if ($returnTo === '' || strpos($returnTo, 'saml') !== false || strpos($returnTo, 'eksterntest') !== false) {
			$returnTo = $this->urlGenerator->linkToRouteAbsolute('dashboard.dashboard.index');
		}

		$this->traceLogger->trace('redirect', [
			'returnTo' => $returnTo,
			'finalSessionId' => $finalSessionId ?? 'unknown',
		]);

		// setMagicInCookie() already set the remember-me cookies, just redirect
		return new RedirectResponse($returnTo);
	}

	/**
	 * Decrypt the EncryptedAssertion from a SAML Response
	 * Manual decryption for RSA-OAEP with SHA256 + AES-256-GCM
	 */
	private function decryptSamlAssertion(string $samlResponseXml): string {
		if (empty($samlResponseXml)) {
			throw new \RuntimeException('Empty SAML response');
		}

		$doc = new \DOMDocument();
		$doc->loadXML($samlResponseXml);

		$xpath = new \DOMXPath($doc);
		$xpath->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');
		$xpath->registerNamespace('xenc', 'http://www.w3.org/2001/04/xmlenc#');
		$xpath->registerNamespace('xenc11', 'http://www.w3.org/2009/xmlenc11#');
		$xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

		// Find the EncryptedAssertion
		$encryptedAssertion = $xpath->query('//saml:EncryptedAssertion')->item(0);
		if (!$encryptedAssertion) {
			throw new \RuntimeException('No EncryptedAssertion found in response');
		}

		// Get the encrypted symmetric key (RSA-OAEP encrypted)
		$encryptedKeyValueNode = $xpath->query('.//xenc:EncryptedKey//xenc:CipherValue', $encryptedAssertion)->item(0);
		if (!$encryptedKeyValueNode) {
			// Try alternative path
			$encryptedKeyValueNode = $xpath->query('.//ds:KeyInfo//xenc:EncryptedKey//xenc:CipherValue', $encryptedAssertion)->item(0);
		}
		if (!$encryptedKeyValueNode) {
			throw new \RuntimeException('Could not find encrypted key CipherValue');
		}
		$encryptedKeyB64 = trim($encryptedKeyValueNode->textContent);
		$encryptedKey = base64_decode($encryptedKeyB64);

		// Get the encrypted data (AES-GCM encrypted)
		$encryptedDataValueNode = $xpath->query('.//xenc:EncryptedData/xenc:CipherData/xenc:CipherValue', $encryptedAssertion)->item(0);
		if (!$encryptedDataValueNode) {
			throw new \RuntimeException('Could not find encrypted data CipherValue');
		}
		$encryptedDataB64 = trim($encryptedDataValueNode->textContent);
		$encryptedData = base64_decode($encryptedDataB64);

		// Load the SP private key
		$certificate = new Certificate(CertificateType::FKAccess, $this->certificateRepository);
		$privateKeyPem = $certificate->getPrivateKey();

		// Decrypt the AES key using RSA-OAEP with SHA256
		// PHP's openssl_private_decrypt with OPENSSL_PKCS1_OAEP_PADDING uses SHA1
		// For SHA256, we need to use phpseclib or handle it manually
		// Try with standard OAEP first (SHA1), then try SHA256 if available
		$aesKey = $this->decryptRsaOaepSha256($encryptedKey, $privateKeyPem);

		// Decrypt the assertion using AES-256-GCM
		// GCM format: IV (12 bytes) + ciphertext + tag (16 bytes)
		$ivLength = 12;
		$tagLength = 16;

		if (strlen($encryptedData) < $ivLength + $tagLength) {
			throw new \RuntimeException('Encrypted data too short for AES-GCM');
		}

		$iv = substr($encryptedData, 0, $ivLength);
		$tag = substr($encryptedData, -$tagLength);
		$ciphertext = substr($encryptedData, $ivLength, -$tagLength);

		$decrypted = openssl_decrypt(
			$ciphertext,
			'aes-256-gcm',
			$aesKey,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ($decrypted === false) {
			throw new \RuntimeException('AES-GCM decryption failed: ' . openssl_error_string());
		}

		return $decrypted;
	}

	/**
	 * Decrypt using RSA-OAEP with SHA256
	 * Uses phpseclib3 if available, falls back to openssl
	 */
	private function decryptRsaOaepSha256(string $encryptedKey, string $privateKeyPem): string {
		// Try phpseclib3 first (supports RSA-OAEP with SHA256)
		if (class_exists('\phpseclib3\Crypt\RSA')) {
			try {
				$rsa = \phpseclib3\Crypt\RSA::loadPrivateKey($privateKeyPem);
				$rsa = $rsa->withHash('sha256')
					->withMGFHash('sha256')
					->withPadding(\phpseclib3\Crypt\RSA::ENCRYPTION_OAEP);
				$decrypted = $rsa->decrypt($encryptedKey);
				if ($decrypted !== false) {
					return $decrypted;
				}
			} catch (\Exception $e) {
				// Fall through to try openssl
			}
		}

		// Try OpenSSL with OAEP (uses SHA1 by default)
		$privateKey = openssl_pkey_get_private($privateKeyPem);
		if ($privateKey === false) {
			throw new \RuntimeException('Failed to load private key: ' . openssl_error_string());
		}

		$aesKey = '';
		$success = openssl_private_decrypt(
			$encryptedKey,
			$aesKey,
			$privateKey,
			OPENSSL_PKCS1_OAEP_PADDING
		);

		if ($success && !empty($aesKey)) {
			return $aesKey;
		}

		throw new \RuntimeException(
			'RSA-OAEP decryption failed. The IdP uses RSA-OAEP with SHA256, ' .
			'but PHP openssl only supports SHA1. Install phpseclib3: composer require phpseclib/phpseclib:^3.0'
		);
	}

	/**
	 * Parse decrypted SAML assertion to extract user information
	 * Bypasses OneLogin library which can't handle RSA-OAEP SHA256 decryption
	 */
	private function parseDecryptedAssertion(string $assertionXml): array {
		$doc = new \DOMDocument();
		$doc->loadXML($assertionXml);

		$xpath = new \DOMXPath($doc);
		$xpath->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');

		// Extract NameID
		$nameIdNode = $xpath->query('//saml:Subject/saml:NameID')->item(0);
		$nameId = $nameIdNode ? trim($nameIdNode->textContent) : null;

		// Extract SessionIndex from AuthnStatement
		$authnStatementNode = $xpath->query('//saml:AuthnStatement')->item(0);
		$sessionIndex = $authnStatementNode ? $authnStatementNode->getAttribute('SessionIndex') : null;

		// Extract attributes
		$attributes = [];
		$attributeNodes = $xpath->query('//saml:AttributeStatement/saml:Attribute');
		foreach ($attributeNodes as $attrNode) {
			$attrName = $attrNode->getAttribute('Name');
			$values = [];
			$valueNodes = $xpath->query('.//saml:AttributeValue', $attrNode);
			foreach ($valueNodes as $valueNode) {
				$values[] = trim($valueNode->textContent);
			}
			$attributes[$attrName] = $values;
		}

		// Parse X509 Subject Name from NameID
		// Format: C=DK,O=11111111,CN=Bruce Lee,Serial=f484ab2a-5fc7-4169-8641-611ce7836267
		$parsedNameId = $this->parseX509SubjectName($nameId);
		$serial = $parsedNameId['Serial'] ?? null;
		$cn = $parsedNameId['CN'] ?? null;

		// Use Serial as UUID, CN as display name
		$uuid = $serial ?? $nameId;
		$displayName = $cn ?? 'Unknown';

		if (!$uuid) {
			throw new \RuntimeException('Missing user UUID in SAML assertion');
		}

		// Store for SLO
		if ($nameId) $this->session->set('opencase.nameid', $nameId);
		if ($sessionIndex) $this->session->set('opencase.session_index', $sessionIndex);

		// Check for Privileges_intermediate attribute and decode if present
		$privileges = null;
		$privilegesAttrName = 'dk:gov:saml:attribute:Privileges_intermediate';
		if (isset($attributes[$privilegesAttrName]) && !empty($attributes[$privilegesAttrName][0])) {
			$privileges = base64_decode($attributes[$privilegesAttrName][0]);
		}
		/************ TEST  *************
		$privileges64 = "PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz48YnBwOlByaXZpbGVnZUxpc3QgeG1sbnM6YnBwPSJodHRwOi8vaXRzdC5kay9vaW9zYW1sL2Jhc2ljX3ByaXZpbGVnZV9wcm9maWxlIiB4bWxuczp4c2k9Imh0dHA6Ly93d3cudzMub3JnLzIwMDEvWE1MU2NoZW1hLWluc3RhbmNlIj48UHJpdmlsZWdlR3JvdXAgU2NvcGU9InVybjpkazpnb3Y6c2FtbDpjdnJOdW1iZXJJZGVudGlmaWVyOjExMTExMTExIj48UHJpdmlsZWdlPmh0dHA6Ly9rb3JzYmFlay5sYW1vdGVjaC5kay9yb2xlcy91c2Vyc3lzdGVtcm9sZS9zeXN0ZW1hZG1pbmlzdHJhdG9yLzE8L1ByaXZpbGVnZT48L1ByaXZpbGVnZUdyb3VwPjwvYnBwOlByaXZpbGVnZUxpc3Q+";
		$privileges64 = "PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz48YnBwOlByaXZpbGVnZUxpc3QgeG1sbnM6YnBwPSJodHRwOi8vaXRzdC5kay9vaW9zYW1sL2Jhc2ljX3ByaXZpbGVnZV9wcm9maWxlIiB4bWxuczp4c2k9Imh0dHA6Ly93d3cudzMub3JnLzIwMDEvWE1MU2NoZW1hLWluc3RhbmNlIj48UHJpdmlsZWdlR3JvdXAgU2NvcGU9InVybjpkazpnb3Y6c2FtbDpjdnJOdW1iZXJJZGVudGlmaWVyOjExMTExMTExIj48UHJpdmlsZWdlPmh0dHA6Ly9rb3JzYmFlay5sYW1vdGVjaC5kay9yb2xlcy91c2Vyc3lzdGVtcm9sZS91c2VyLzE8L1ByaXZpbGVnZT48Q29uc3RyYWludCBOYW1lPSJodHRwOi8vc3RzLmtvbWJpdC5kay9jb25zdHJhaW50cy9vcmdlbmhlZC8xIj40MDUzZDU4ZC1iZDVlLTQ4ZDktYjlkOC0yYzA0MWZhZWNjZDU8L0NvbnN0cmFpbnQ+PC9Qcml2aWxlZ2VHcm91cD48L2JwcDpQcml2aWxlZ2VMaXN0Pg==";
		$privileges64 = "PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz48YnBwOlByaXZpbGVnZUxpc3QgeG1sbnM6YnBwPSJodHRwOi8vaXRzdC5kay9vaW9zYW1sL2Jhc2ljX3ByaXZpbGVnZV9wcm9maWxlIiB4bWxuczp4c2k9Imh0dHA6Ly93d3cudzMub3JnLzIwMDEvWE1MU2NoZW1hLWluc3RhbmNlIj48UHJpdmlsZWdlR3JvdXAgU2NvcGU9InVybjpkazpnb3Y6c2FtbDpjdnJOdW1iZXJJZGVudGlmaWVyOjExMTExMTExIj48UHJpdmlsZWdlPmh0dHA6Ly9rb3JzYmFlay5sYW1vdGVjaC5kay9yb2xlcy91c2Vyc3lzdGVtcm9sZS91c2VyLzE8L1ByaXZpbGVnZT48Q29uc3RyYWludCBOYW1lPSJodHRwOi8vc3RzLmtvbWJpdC5kay9jb25zdHJhaW50cy9vcmdlbmhlZC8xIj40MDUzZDU4ZC1iZDVlLTQ4ZDktYjlkOC0yYzA0MWZhZWNjZDUsIDdhYzU2Y2M3LWEzZDgtNDBlYi1iMmQ2LWY5NDA5NmEyNzFiZjwvQ29uc3RyYWludD48L1ByaXZpbGVnZUdyb3VwPjwvYnBwOlByaXZpbGVnZUxpc3Q+";
		$privileges = base64_decode($privileges64);
		/********************************/

		return [
			'uuid' => (string)$uuid,
			'displayName' => (string)$displayName,
			'serial' => $serial,
			'cn' => $cn,
			'attributes' => $attributes,
			'nameId' => $nameId,
			'sessionIndex' => $sessionIndex,
			'parsedNameId' => $parsedNameId,
			'privileges' => $privileges,
		];
	}

	/**
	 * Parse X509 Subject Name format into key-value pairs
	 */
	private function parseX509SubjectName(?string $subjectName): array {
		if (empty($subjectName)) {
			return [];
		}

		$result = [];
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
	 * Get user privilege information from the principal's privileges XML.
	 *
	 * Returns role flags (for NC group/role sync) plus the raw privilege groups
	 * needed to persist constraints and compute access-profile grants.
	 *
	 * @param array $principal The principal data including privileges XML
	 * @return array{
	 *   isUser: bool,
	 *   isAdministrator: bool,
	 *   isOpenCaseUser: bool,
	 *   isOpenCaseAdministrator: bool,
	 *   isTemplateAdministrator: bool,
	 *   grantedOrganisations: array<string>,
	 *   privilegeGroups: array<array{
	 *     privilege_type: string,
	 *     foelsomhed: string[],
	 *     kle: string[],
	 *     orgenhed: string[]
	 *   }>
	 * }
	 */
	private function getUserPrivilege(array $principal): array {
		$userUuid = $principal['uuid'] ?? 'unknown';
		$this->traceLogger->trace('getUserPrivilege', [
			'userUuid' => $userUuid,
		]);

		if (empty($principal['privileges'])) {
			$this->traceLogger->trace('getUserPrivilege', [
				'userUuid' => $userUuid,
				'message' => 'No privileges found in principal data',
			]);
			if ($this->openCaseConfig->getConfigValue('privilege_test_mode') === 'true') {
				$dataDir = rtrim($this->systemConfig->getSystemValueString('datadirectory', ''), '/');
				$testFile = $dataDir . '/opencase_storage/_testdata/_privileges/' . $userUuid . '.xml';
				$this->traceLogger->trace('getUserPrivilege', [
					'userUuid' => $userUuid,
					'testFile' => $testFile,
				]);
				if (file_exists($testFile)) {
					$this->traceLogger->trace('getUserPrivilege', [
						'userUuid' => $userUuid,
						'message' => 'Loading privileges from test file',
					]);
					$principal['privileges'] = file_get_contents($testFile);
				}
			}
		}

		$empty = [
			'isUser'                => false,
			'isAdministrator'       => false,
			'isOpenCaseUser'        => false,
			'isOpenCaseAdministrator' => false,
			'isTemplateAdministrator' => false,
			'grantedOrganisations'  => [],
			'privilegeGroups'       => [],
		];

		$privilegesXml = $principal['privileges'] ?? null;
		if ($privilegesXml === null) {
			return $empty;
		}

		$doc = new \DOMDocument();
		if (!$doc->loadXML($privilegesXml)) {
			return $empty;
		}

		$xpath = new \DOMXPath($doc);
		$xpath->registerNamespace('bpp', 'http://itst.dk/oiosaml/basic_privilege_profile');

		$isUser                  = false;
		$isAdministrator         = false;
		$isOpenCaseUser          = false;
		$isOpenCaseAdministrator = false;
		$isTemplateAdministrator = false;
		$grantedOrganisations    = [];
		$privilegeGroups         = [];

		$allGroups = $xpath->query('//bpp:PrivilegeList/PrivilegeGroup');
		foreach ($allGroups as $group) {
			$privNodes = $xpath->query('Privilege', $group);
			foreach ($privNodes as $privNode) {
				$text = trim($privNode->textContent);

				if (str_contains($text, '/roles/usersystemrole/systemadministrator/1')) {
					$isUser          = true;
					$isAdministrator = true;
					$privilegeGroups[] = ['privilege_type' => 'systemadministrator', 'foelsomhed' => [], 'kle' => [], 'orgenhed' => []];

				} elseif (str_contains($text, '/roles/usersystemrole/opencaseadministrator/1')) {
					$isUser                  = true;
					$isOpenCaseAdministrator = true;
					$privilegeGroups[] = ['privilege_type' => 'opencaseadministrator', 'foelsomhed' => [], 'kle' => [], 'orgenhed' => []];

				} elseif (str_contains($text, '/roles/usersystemrole/opencasetemplateadministrator/1')) {
					$isUser                  = true;
					$isTemplateAdministrator = true;
					$privilegeGroups[] = ['privilege_type' => 'opencasetemplateadministrator', 'foelsomhed' => [], 'kle' => [], 'orgenhed' => []];

				} elseif (str_contains($text, '/roles/usersystemrole/opencaseuser/1')) {
					$isUser         = true;
					$isOpenCaseUser = true;

					// Extract constraints for this privilege group
					$foelsomhed = $this->extractConstraintValues(
						$xpath, $group, 'http://sts.kombit.dk/constraints/foelsomhed/1'
					);
					$kle = $this->extractConstraintValues(
						$xpath, $group, 'http://sts.kombit.dk/constraints/KLE/1'
					);
					$orgenhed = $this->extractConstraintValues(
						$xpath, $group, 'http://sts.kombit.dk/constraints/orgenhed/1'
					);

					// Collect granted organisations for backward-compat
					foreach ($orgenhed as $orgUuid) {
						if (!in_array($orgUuid, $grantedOrganisations, true)) {
							$grantedOrganisations[] = $orgUuid;
						}
					}

					$privilegeGroups[] = [
						'privilege_type' => 'opencaseuser',
						'foelsomhed'     => $foelsomhed,
						'kle'            => $kle,
						'orgenhed'       => $orgenhed,
					];

				} elseif (str_contains($text, '/roles/usersystemrole/opencasereaduser/1')) {
					$isUser         = true;
					$isOpenCaseUser = true;

					$foelsomhed = $this->extractConstraintValues(
						$xpath, $group, 'http://sts.kombit.dk/constraints/foelsomhed/1'
					);
					$kle = $this->extractConstraintValues(
						$xpath, $group, 'http://sts.kombit.dk/constraints/KLE/1'
					);
					$orgenhed = $this->extractConstraintValues(
						$xpath, $group, 'http://sts.kombit.dk/constraints/orgenhed/1'
					);

					foreach ($orgenhed as $orgUuid) {
						if (!in_array($orgUuid, $grantedOrganisations, true)) {
							$grantedOrganisations[] = $orgUuid;
						}
					}

					$privilegeGroups[] = [
						'privilege_type' => 'opencasereaduser',
						'foelsomhed'     => $foelsomhed,
						'kle'            => $kle,
						'orgenhed'       => $orgenhed,
					];
				}
			}
		}

		return [
			'isUser'                  => $isUser,
			'isAdministrator'         => $isAdministrator,
			'isOpenCaseUser'          => $isOpenCaseUser,
			'isOpenCaseAdministrator' => $isOpenCaseAdministrator,
			'isTemplateAdministrator' => $isTemplateAdministrator,
			'grantedOrganisations'    => $grantedOrganisations,
			'privilegeGroups'         => $privilegeGroups,
		];
	}

	/**
	 * Extract comma-separated values from a named Constraint element within a PrivilegeGroup.
	 *
	 * @return string[]
	 */
	private function extractConstraintValues(\DOMXPath $xpath, \DOMNode $group, string $constraintName): array {
		$nodes = $xpath->query(
			'Constraint[@Name="' . $constraintName . '"]',
			$group
		);
		$values = [];
		foreach ($nodes as $node) {
			$parts = explode(',', trim($node->textContent));
			foreach ($parts as $part) {
				$part = trim($part);
				if ($part !== '') {
					$values[] = $part;
				}
			}
		}
		return $values;
	}

	/**
	 * Create a new Nextcloud user for SAML login
	 * Note: Storage initialization is done separately after login completes
	 *
	 * @param string $userId The user ID (opencase_ + uuid)
	 * @param string $displayName The user's display name
	 * @return \OCP\IUser The created user
	 */
	private function createNextcloudUser(string $userId, string $displayName): \OCP\IUser {
		// Generate a random password (SAML users don't use passwords)
		$secureRandom = $this->secureRandom;
		$password = $secureRandom->generate(32);

		// Create the user
		$user = $this->userManager->createUser($userId, $password);
		if ($user === false) {
			throw new \RuntimeException('Failed to create user: ' . $userId);
		}

		// Set display name
		$user->setDisplayName($displayName);

		// Note: Storage initialization is done in initializeUserStorage()
		// which must be called AFTER the user is logged in

		return $user;
	}

	/**
	 * Initialize storage/home folder for a newly created user
	 * IMPORTANT: This must be called AFTER the user is logged in to ensure
	 * the filesystem is set up in the correct user context
	 *
	 * @param string $userId The user ID
	 * @param \OCP\IUser $user The user object
	 */
	private function initializeUserStorage(string $userId, \OCP\IUser $user): void {
		try {
			/*
			// Tear down any existing filesystem mounts to ensure clean state
			\OC_Util::tearDownFS();

			// Set up the filesystem for the user - this creates the home folder
			\OC_Util::setupFS($userId);

			// Initialize mount points for the user explicitly
			\OC\Files\Filesystem::initMountPoints($userId);

			// Get the user folder - this triggers folder creation if it doesn't exist
			$userFolder = \OC::$server->getUserFolder($userId);

			// Copy skeleton files to the new user's folder
			\OC_Util::copySkeleton($userId, $userFolder);

			// Trigger first-time login event for any other initialization
			$this->eventDispatcher->dispatchTyped(new UserFirstTimeLoggedInEvent($user));

			// Scan the user's files directory to ensure file cache is properly populated
			// This is critical for newly created users to see their files
			$scanner = new \OC\Files\Utils\Scanner(
				$userId,
				\OC::$server->getDatabaseConnection(),
				$this->eventDispatcher,
				\OC::$server->get(\Psr\Log\LoggerInterface::class)
			);
			$scanner->scan('/' . $userId . '/files', true);
			*/

			$userFolder = \OC::$server->getUserFolder($userId);
			try {
				// copy skeleton
				\OC_Util::copySkeleton($userId, $userFolder);
			} catch (NotPermittedException) {
				// read only uses
			}
			// trigger any other initialization
			$user = $this->userManager->get($userId);
			$this->eventDispatcher->dispatchTyped(new UserFirstTimeLoggedInEvent($user));

			$this->traceLogger->trace('storage_init_success', [
				'userId' => $userId,
				'userFolderPath' => $userFolder->getPath(),
				'scanned' => true,
			]);
		} catch (\Exception $e) {
			// Log but don't fail - the folder may be created on first real access
			$this->traceLogger->error('storage_init_error', $e, [
				'userId' => $userId,
			]);
		}
	}

	/**
	 * Show the "no access" page
	 *
	 * @return TemplateResponse
	 */
	private function syncOpenCaseRoles(string $userId, bool $isOpenCaseUser, bool $isOpenCaseAdministrator, bool $isTemplateAdministrator): void {
		$desired = [
			'User'              => $isOpenCaseUser,
			'Administrator'     => $isOpenCaseAdministrator,
			'Template Designer' => $isTemplateAdministrator,
		];

		foreach ($desired as $roleName => $shouldHave) {
			$role = $this->roleMapper->findByName($roleName);
			if ($role === null) {
				$this->traceLogger->trace('opencase_role_not_found', ['role' => $roleName]);
				continue;
			}

			if ($shouldHave) {
				$this->userRoleMapper->assignRole($userId, $role->getId());
				$this->traceLogger->trace('opencase_role_assigned', ['userId' => $userId, 'role' => $roleName]);
			} else {
				$this->userRoleMapper->revokeRole($userId, $role->getId());
				$this->traceLogger->trace('opencase_role_revoked', ['userId' => $userId, 'role' => $roleName]);
			}
		}
	}

	private function showNoAccessPage(): TemplateResponse {
		return new TemplateResponse(
			'opencase',
			'noaccess',
			[
				'loginUrl' => $this->urlGenerator->linkToRouteAbsolute('opencase.saml.login'),
			],
			'guest'
		);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function noaccess(): TemplateResponse {
		return $this->showNoAccessPage();
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function logout(): Response {
		try {
			// Get return URL from params (where to go after logout completes)
			$params = $this->request->getParams();
			$returnTo = isset($params['redirect_url']) ? (string)$params['redirect_url'] : null;

			// If no returnTo specified, use our logged-out page
			if (empty($returnTo)) {
				$returnTo = $this->urlGenerator->linkToRouteAbsolute('opencase.saml.loggedout');
			}

			// Build the LogoutRequest URL
			$logoutUrl = $this->samlService->buildLogoutRedirectUrl($returnTo);

			$this->traceLogger->trace('sp_initiated_logout', [
				'logoutUrl' => $logoutUrl,
				'returnTo' => $returnTo,
			]);

			// Log out from Nextcloud first
			$this->userSession->logout();

			// Redirect to IdP for SAML logout
			return new RedirectResponse($logoutUrl);

		} catch (\Exception $e) {
			$this->traceLogger->error('logout_error', $e);

			// If SAML logout fails, still log out locally and show logged out page
			$this->userSession->logout();
			return new RedirectResponse(
				$this->urlGenerator->linkToRouteAbsolute('opencase.saml.loggedout')
			);
		}
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function sls(): Response {
		return $this->handleSls();
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function slsPost(): Response {
		return $this->handleSls();
	}

	/**
	 * Common SLS handler for both GET and POST
	 */
	private function handleSls(): Response {
		$samlResponse = $this->request->getParam('SAMLResponse');
		$samlRequest = $this->request->getParam('SAMLRequest');

		$this->traceLogger->trace('sls_received', [
			'hasSAMLResponse' => !empty($samlResponse),
			'hasSAMLRequest' => !empty($samlRequest),
			'method' => $this->request->getMethod(),
		]);

		try {
			$result = $this->samlService->processSls($samlResponse, $samlRequest);

			$this->traceLogger->trace('sls_processed', [
				'result' => $result,
			]);

			// Log out from Nextcloud
			$this->userSession->logout();

			// For IdP-initiated logout (LogoutRequest), redirect to send LogoutResponse
			if ($result['type'] === 'logout_request' && !empty($result['returnTo'])) {
				return new RedirectResponse($result['returnTo']);
			}

			// For SP-initiated logout (LogoutResponse), show logged out page
			return new RedirectResponse(
				$this->urlGenerator->linkToRouteAbsolute('opencase.saml.loggedout')
			);

		} catch (\Exception $e) {
			$this->traceLogger->error('sls_error', $e);

			// Even on error, log out locally
			$this->userSession->logout();

			return new RedirectResponse(
				$this->urlGenerator->linkToRouteAbsolute('opencase.saml.loggedout')
			);
		}
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function loggedout(): TemplateResponse {
		return new TemplateResponse(
			'opencase',
			'loggedout',
			[
				'loginUrl' => $this->urlGenerator->linkToRouteAbsolute('opencase.saml.login'),
			],
			'guest'
		);
	}
}
