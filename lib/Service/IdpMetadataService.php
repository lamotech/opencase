<?php
declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OneLogin\Saml2\IdPMetadataParser;
use OneLogin\Saml2\Utils as Saml2Utils;
use OCP\Http\Client\IClientService;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

class IdpMetadataService {

	/** How long a successful metadata fetch is reused before refetching. */
	private const CACHE_TTL = 3600;

	/**
	 * How long the last successful fetch is kept as a fallback. Metadata is
	 * needed on every login now that assertions are signature-verified, so a
	 * transient IdP/network failure must not take logins down with it.
	 */
	private const FALLBACK_TTL = 86400;

	public function __construct(
		private Configuration $configuration,
		private IClientService $http,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {}

	public function getIdpSettingsFromMetadata(): array {
		$url = $this->configuration->getConfigValue('idp_metadata_url', 'https://n2adgangsstyring.eksterntest-stoettesystemerne.dk/runtime/saml2/metadata.idp?samlprofile=nemlogin3');
		if ($url === '') {
			throw new \RuntimeException('idp_metadata_url not configured');
		}

		$cache        = $this->cacheFactory->createDistributed('opencase/idp/');
		$cacheKey     = 'metadata_' . md5($url);
		$fallbackKey  = 'metadata_fallback_' . md5($url);

		$cached = $cache->get($cacheKey);
		if (is_string($cached)) {
			$decoded = json_decode($cached, true);
			if (is_array($decoded)) {
				return $decoded;
			}
		}

		try {
			$client = $this->http->newClient();
			$res = $client->get($url, ['timeout' => 20]);
			$xml = (string)$res->getBody();

			// Parse metadata → OneLogin settings
			$parsed = IdPMetadataParser::parseXML($xml);

			// Normalize minimal fields needed by SamlService
			$settings = [
				'entityId' => $parsed['idp']['entityId'],
				'singleSignOnService' => $parsed['idp']['singleSignOnService'],
				'singleLogoutService' => $parsed['idp']['singleLogoutService'] ?? null,
				'x509cert' => $parsed['idp']['x509cert'] ?? '',
				'x509certMulti' => $parsed['idp']['x509certMulti'] ?? null,
			];
		} catch (\Throwable $e) {
			$stale = $cache->get($fallbackKey);
			if (is_string($stale)) {
				$decoded = json_decode($stale, true);
				if (is_array($decoded)) {
					$this->logger->warning(
						'OpenCase: IdP metadata fetch failed, using last known good copy: {msg}',
						['app' => 'opencase', 'msg' => $e->getMessage()],
					);
					return $decoded;
				}
			}
			throw $e;
		}

		$encoded = json_encode($settings);
		$cache->set($cacheKey, $encoded, self::CACHE_TTL);
		$cache->set($fallbackKey, $encoded, self::FALLBACK_TTL);

		return $settings;
	}

	/**
	 * The IdP's signing certificates, PEM-formatted, as the trust anchors for
	 * verifying inbound assertions and logout messages.
	 *
	 * More than one can be published at once — an IdP rotating its key serves
	 * both the outgoing and incoming certificate for the overlap window, and a
	 * message signed by either must verify.
	 *
	 * @return list<string> PEM certificates, possibly empty.
	 */
	public function getSigningCertificates(): array {
		$idp = $this->getIdpSettingsFromMetadata();

		$certs = [];

		// When the IdP publishes distinct signing/encryption keys the parser
		// keeps them apart; only the signing ones may validate a signature.
		$multi = $idp['x509certMulti'] ?? null;
		if (is_array($multi) && !empty($multi['signing'])) {
			$certs = array_merge($certs, (array)$multi['signing']);
		}

		// Otherwise the parser collapses a single shared key into x509cert.
		if (empty($certs) && !empty($idp['x509cert'])) {
			$certs[] = $idp['x509cert'];
		}

		$formatted = [];
		foreach ($certs as $cert) {
			$cert = trim((string)$cert);
			if ($cert === '') {
				continue;
			}
			// Metadata certs arrive as bare base64; xmlseclibs needs PEM heads.
			$formatted[] = Saml2Utils::formatCert($cert, true);
		}

		return array_values(array_unique($formatted));
	}
}
