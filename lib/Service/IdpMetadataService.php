<?php
declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OneLogin\Saml2\IdPMetadataParser;
use OCP\Http\Client\IClientService;

class IdpMetadataService {
	public function __construct(
		private Configuration $configuration,
		private IClientService $http,
	) {}

	public function getIdpSettingsFromMetadata(): array {
		$url = $this->configuration->getConfigValue('idp_metadata_url', 'https://n2adgangsstyring.eksterntest-stoettesystemerne.dk/runtime/saml2/metadata.idp?samlprofile=nemlogin3');
		if ($url === '') {
			throw new \RuntimeException('idp_metadata_url not configured');
		}

		$client = $this->http->newClient();
		$res = $client->get($url, ['timeout' => 20]);
		$xml = (string)$res->getBody();

		// Parse metadata → OneLogin settings
		$parsed = IdPMetadataParser::parseXML($xml);

		// Normalize minimal fields needed by SamlService
		return [
			'entityId' => $parsed['idp']['entityId'],
			'singleSignOnService' => $parsed['idp']['singleSignOnService'],
			'singleLogoutService' => $parsed['idp']['singleLogoutService'] ?? null,
			'x509cert' => $parsed['idp']['x509cert'] ?? '',
			// 'x509certMulti' => $parsed['idp']['x509certMulti'] ?? null,
		];
	}
}
