<?php
declare(strict_types=1);

namespace OCA\OpenCase\Service\Serviceplatformen;

use OCA\OpenCase\Service\Serviceplatformen\OrganisationData;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCA\OpenCase\Service\Serviceplatformen\TokenIssuerREST;
use OCA\OpenCase\Service\Serviceplatformen\SAMLToken;
use OCA\OpenCase\Db\CertificateRepository;
use OCA\OpenCase\Service\Certificate;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\TraceLogger;
use OCA\OpenCase\Enum\CertificateType;
use OCA\OpenCase\Service\Serviceplatformen\OrganisationConfiguration;
use OCA\OpenCase\Service\Serviceplatformen\OrganisationWrapper;
use OCA\OpenCase\Service\Serviceplatformen\AddressWrapper;
use OCA\OpenCase\Service\Serviceplatformen\AddressData;
use DOMDocument;
use DOMXPath;

class OrganisationClient {
	public function __construct(
		private IClientService $http,
		private IConfig $config,
		private CertificateRepository $certificateRepository,
		private Configuration $configuration,
		private TraceLogger $traceLogger,
	) {}

	public function fetchOrganisation(string $uuid): OrganisationData {
		$certificate = new Certificate(CertificateType::FKOrganisation, $this->certificateRepository);
		$entityId = $this->configuration->getConfigValue('entity_id_organisation', 'http://stoettesystemerne.dk/service/organisation/3');
		$samlToken = TokenIssuerREST::issueToken(
			$entityId,
			$certificate,
			$this->configuration,
			$this->traceLogger
		);

		$organisationConfiguration = new OrganisationConfiguration();
		$endpoint = $this->configuration->getConfigValue('endpoint_organisation_enhed', 'https://organisation.eksterntest-stoettesystemerne.dk/organisation/organisationenhed/6/');
		$organisationConfiguration->setEndpoint($endpoint);
		$organisationConfiguration->setClientCertificate($certificate);
		$organisationWrapper = new OrganisationWrapper($organisationConfiguration, $samlToken);

		$response = $organisationWrapper->laes($uuid);

		$this->traceLogger->trace('OrganisationClient_fetchOrganisation', [
			'uuid' => $uuid,
			'responseLength' => strlen($response),
			'response' => substr($response, 0, 2000),
		]);

		$doc = new DOMDocument();
		$doc->loadXML($response);

		$xpath = new DOMXPath($doc);
		$xpath->registerNamespace('ns2', 'urn:oio:sagdok:3.0.0');
		$xpath->registerNamespace('ns5', 'http://stoettesystemerne.dk/organisation/organisationenhed/6/');

		$nameNodes = $xpath->query('//ns2:EnhedNavn');
		$name = $nameNodes->length > 0 ? $nameNodes->item(0)->textContent : '';

		$parentUuidNodes = $xpath->query('//ns2:Overordnet/ns2:ReferenceID/ns2:UUIDIdentifikator');
		$parentUuid = $parentUuidNodes->length > 0 ? $parentUuidNodes->item(0)->textContent : '';

		$addresses = [];
		$adresserNodes = $xpath->query('//ns2:Adresser');
		foreach ($adresserNodes as $adresse) {
			$uuidNodes = $xpath->query('ns2:ReferenceID/ns2:UUIDIdentifikator', $adresse);
			$roleUuidNodes = $xpath->query('ns2:Rolle/ns2:UUIDIdentifikator', $adresse);
			$labelNodes = $xpath->query('ns2:Rolle/ns2:Label', $adresse);

			$addressUuid = $uuidNodes->length > 0 ? $uuidNodes->item(0)->textContent : '';
			$roleUuid = $roleUuidNodes->length > 0 ? $roleUuidNodes->item(0)->textContent : '';
			$label = $labelNodes->length > 0 ? $labelNodes->item(0)->textContent : '';

			$addresses[] = $this->lookupAddress($samlToken, $addressUuid, $roleUuid, $label);
		}

		return new OrganisationData($uuid, $name, $parentUuid, $addresses);
	}

	/**
	 * @return OrganisationData[]
	 */
	public function fetchOrganisations(): array {
		// Get a SAML Token
		$certificate = new Certificate(CertificateType::FKOrganisation, $this->certificateRepository);
		$entityId = $this->configuration->getConfigValue('entity_id_organisation', 'http://stoettesystemerne.dk/service/organisation/3');
		$samlToken = TokenIssuerREST::issueToken(
			$entityId,
			$certificate,
			$this->configuration,
			$this->traceLogger
		);

		// Setup Organisation Service
		$organisationConfiguration = new OrganisationConfiguration();
		$endpoint = $this->configuration->getConfigValue('endpoint_organisation', 'https://organisation.eksterntest-stoettesystemerne.dk/organisation/organisationsystem/6/');
		$organisationConfiguration->setEndpoint($endpoint);
		$organisationConfiguration->setClientCertificate($certificate);
		$organisationWrapper = new OrganisationWrapper($organisationConfiguration, $samlToken);

		// Get organisations
		$organisations = [];
		$limit = 500;
		$offset = 0;

		while (true) {
			$response = $organisationWrapper->fremsoeg(limit: $limit, offset: $offset);

			$this->traceLogger->trace('organisation_fremsoeg_response', [
				'limit' => $limit,
				'offset' => $offset,
				'responseLength' => strlen($response),
				'response' => substr($response, 0, 2000),
			]);

			// Parse XML and extract EnhedNavn values
			$doc = new DOMDocument();
			$doc->loadXML($response);

			$xpath = new DOMXPath($doc);
			// Register namespaces
			$xpath->registerNamespace('ns2', 'urn:oio:sagdok:3.0.0');
			$xpath->registerNamespace('ns5', 'http://stoettesystemerne.dk/organisation/organisationenhed/6/');
			$xpath->registerNamespace('ns6', 'http://stoettesystemerne.dk/organisation/organisationsystem/6/');

			// Loop through OrganisationEnheder
			$nodes = $xpath->query('//ns6:OrganisationEnheder//ns6:FiltreretOejebliksbillede');

			if (count($nodes) === 0) {
				break;
			}

			foreach ($nodes as $node) {
				$uuid = $xpath->evaluate('string(ns5:ObjektType/ns2:UUIDIdentifikator)', $node);
				$name = $xpath->evaluate('string(ns5:Registrering/ns5:AttributListe/ns5:Egenskab/ns2:EnhedNavn)', $node);
				$parentUuid = $xpath->evaluate('string(ns5:Registrering/ns5:RelationListe/ns2:Overordnet/ns2:ReferenceID/ns2:UUIDIdentifikator)', $node);

				$organisations[] = new OrganisationData($uuid, $name, $parentUuid, []);
			}

			$offset += $limit;
		}

		return $organisations;
	}

	private function lookupAddress(SAMLToken $samlToken, string $addressUuid, string $roleUuid, string $label): AddressData {
		$certificate = new Certificate(CertificateType::FKOrganisation, $this->certificateRepository);
		$addressConfiguration = new OrganisationConfiguration();
		$endpoint = $this->configuration->getConfigValue('endpoint_address', 'https://organisation.eksterntest-stoettesystemerne.dk/organisation/adresse/6/');
		$addressConfiguration->setEndpoint($endpoint);
		$addressConfiguration->setClientCertificate($certificate);
		$addressWrapper = new AddressWrapper($addressConfiguration, $samlToken);

		$addressResponse = $addressWrapper->laes($addressUuid);
		$this->traceLogger->trace('OrganisationClient_lookupAddress', [
			'uuid' => $addressUuid,
			'responseLength' => strlen($addressResponse),
			'response' => substr($addressResponse, 0, 2000),
		]);

		$doc = new DOMDocument();
		$doc->loadXML($addressResponse);

		$xpath = new DOMXPath($doc);
		$xpath->registerNamespace('ns4', 'urn:oio:sts:6');

		$textNodes = $xpath->query('//ns4:AdresseTekst');
		$text = $textNodes->length > 0 ? $textNodes->item(0)->textContent : '';

		return new AddressData($addressUuid, $roleUuid, $label, $text);
	}
}
