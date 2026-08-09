<?php
declare(strict_types=1);

namespace OCA\OpenCase\Service\Serviceplatformen;

use OCA\OpenCase\Service\Serviceplatformen\UserData;
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
use OCA\OpenCase\Service\Serviceplatformen\UserWrapper;
use OCA\OpenCase\Service\Serviceplatformen\PersonWrapper;
use OCA\OpenCase\Service\Serviceplatformen\PersonData;
use OCA\OpenCase\Service\Serviceplatformen\AddressData;
use DOMDocument;
use DOMXPath;

class UserClient {
	public function __construct(
		private IClientService $http,
		private IConfig $config,
		private CertificateRepository $certificateRepository,
		private Configuration $configuration,
		private TraceLogger $traceLogger,
	) {}

	/**
	 * @return UserData
	 */
	public function fetchUser(string $UserUUIDIdentifikator): UserData {
		// Get a SAML Token
		$certificate = new Certificate(CertificateType::FKOrganisation, $this->certificateRepository);
		$entityId = $this->configuration->getConfigValue('entity_id_organisation', 'http://entityid.kombit.dk/service/organisation/7');
		$samlToken = TokenIssuerREST::issueToken(
			$entityId,
			$certificate,
			$this->configuration,
			$this->traceLogger
		);

		// Setup Organisation Service
		$organisationConfiguration = new OrganisationConfiguration();
		$organisationConfiguration->setClientCertificate($certificate);

		$endpoint = $this->configuration->getConfigValue('endpoint_user', 'https://organisation.eksterntest-stoettesystemerne.dk/organisation/bruger/6/');
		$organisationConfiguration->setEndpoint($endpoint);
        $userWrapper = new UserWrapper($organisationConfiguration, $samlToken, $this->traceLogger);

        // Get user
        $userResponse = $userWrapper->laes($UserUUIDIdentifikator);
        $this->traceLogger->trace('UserClient_fetchUser', [
            'uuid' => $UserUUIDIdentifikator,
            'responseLength' => strlen($userResponse),
            'response' => substr($userResponse, 0, 2000),
        ]);
        // Parse XML and extract EnhedNavn values
        $doc = new DOMDocument();
        $doc->loadXML($userResponse);

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('ns2', 'urn:oio:sagdok:3.0.0');
        $xpath->registerNamespace('ns3', 'http://stoettesystemerne.dk/organisation/bruger/6/');

        // Extract BrugerNavn
        $brugerNavnNodes = $xpath->query('//ns2:BrugerNavn');
        $username = $brugerNavnNodes->length > 0 ? $brugerNavnNodes->item(0)->textContent : '';

        // Extract first TilknyttedePersoner UUID
        $personNodes = $xpath->query('//ns2:TilknyttedePersoner[1]/ns2:ReferenceID/ns2:UUIDIdentifikator');
        $personUuid = $personNodes->length > 0 ? $personNodes->item(0)->textContent : '';
        $person = $this->lookupPerson($samlToken, $personUuid);

        // Extract all Adresser
        $addresses = [];
        $adresserNodes = $xpath->query('//ns2:Adresser');
        foreach ($adresserNodes as $adresse) {
            $uuidNodes = $xpath->query('ns2:ReferenceID/ns2:UUIDIdentifikator', $adresse);
            $roleUuidNodes = $xpath->query('ns2:Rolle/ns2:UUIDIdentifikator', $adresse);
            $labelNodes = $xpath->query('ns2:Rolle/ns2:Label', $adresse);

            $uuid = $uuidNodes->length > 0 ? $uuidNodes->item(0)->textContent : '';
            $roleUuid = $roleUuidNodes->length > 0 ? $roleUuidNodes->item(0)->textContent : '';
            $label = $labelNodes->length > 0 ? $labelNodes->item(0)->textContent : '';

            $address = $this->lookupAddress($samlToken, $uuid, $roleUuid, $label);
            $addresses[] = $address;
        }

        $organisationUuids = $this->lookupOrganisations($samlToken, $UserUUIDIdentifikator);
        $user = new UserData($UserUUIDIdentifikator, $username, $person, $addresses, $organisationUuids);

		return $user;
	}

    private function lookupPerson(SAMLToken $samlToken, string $personUuid): PersonData {
        $certificate = new Certificate(CertificateType::FKOrganisation, $this->certificateRepository);
        $personConfiguration = new OrganisationConfiguration();
        $endpoint = $this->configuration->getConfigValue('endpoint_person', 'https://organisation.eksterntest-stoettesystemerne.dk/organisation/person/6/');
        $personConfiguration->setEndpoint($endpoint);
        $personConfiguration->setClientCertificate($certificate);
        $personWrapper = new PersonWrapper($personConfiguration, $samlToken);

        $personResponse = $personWrapper->laes($personUuid);

        $this->traceLogger->trace('UserClient_lookupPerson', [
            'uuid' => $personUuid,
            'responseLength' => strlen($personResponse),
            'response' => substr($personResponse, 0, 2000),
        ]);

        $doc = new DOMDocument();
        $doc->loadXML($personResponse);

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('ns2', 'urn:oio:sagdok:3.0.0');
        $xpath->registerNamespace('ns3', 'http://stoettesystemerne.dk/organisation/person/6/');

        $navnNodes = $xpath->query('//ns3:NavnTekst');
        $name = $navnNodes->length > 0 ? $navnNodes->item(0)->textContent : '';

        return new PersonData($personUuid, $name);
    }

    private function lookupAddress(SAMLToken $samlToken, string $addressUuid, string $roleUuid, string $label): AddressData {
        $certificate = new Certificate(CertificateType::FKOrganisation, $this->certificateRepository);
        $addressConfiguration = new OrganisationConfiguration();
        $endpoint = $this->configuration->getConfigValue('endpoint_address', 'https://organisation.eksterntest-stoettesystemerne.dk/organisation/adresse/6/');
        $addressConfiguration->setEndpoint($endpoint);
        $addressConfiguration->setClientCertificate($certificate);
        $addressWrapper = new AddressWrapper($addressConfiguration, $samlToken);

        $addressResponse = $addressWrapper->laes($addressUuid);
        $this->traceLogger->trace('UserClient_lookupAddress', [
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

    private function lookupOrganisations(SAMLToken $samlToken, string $userUuid): array {
        $certificate = new Certificate(CertificateType::FKOrganisation, $this->certificateRepository);
        $orgFunctionConfiguration = new OrganisationConfiguration();
        $endpoint = $this->configuration->getConfigValue('endpoint_organisation_function', 'https://organisation.eksterntest-stoettesystemerne.dk/organisation/organisationfunktion/6/');
        $orgFunctionConfiguration->setEndpoint($endpoint);
        $orgFunctionConfiguration->setClientCertificate($certificate);
        $orgFunctionWrapper = new OrganisationFunctionWrapper($orgFunctionConfiguration, $samlToken);

        $orgFuncSoegResponse = $orgFunctionWrapper->soeg($userUuid);
        $this->traceLogger->trace('UserClient_lookupOrganisations_orgFuncSoegResponse', [
            'uuid' => $userUuid,
            'responseLength' => strlen($orgFuncSoegResponse),
            'response' => substr($orgFuncSoegResponse, 0, 2000),
        ]);

        $soegDoc = new DOMDocument();
        $soegDoc->loadXML($orgFuncSoegResponse);
        $soegXpath = new DOMXPath($soegDoc);
        $soegXpath->registerNamespace('ns2', 'urn:oio:sagdok:3.0.0');

        $userFuncUuids = [];
        $idNodes = $soegXpath->query('//ns2:IdListe/ns2:UUIDIdentifikator');
        foreach ($idNodes as $node) {
            $userFuncUuids[] = $node->textContent;
        }
        $this->traceLogger->trace('UserClient_lookupOrganisations_userFuncUuids', [
            'uuid' => $userUuid,
            'userFuncUuids' => $userFuncUuids,
        ]);

        if (empty($userFuncUuids)) {
            return [];
        }

        $orgFuncListResponse = $orgFunctionWrapper->list($userFuncUuids);
        $this->traceLogger->trace('UserClient_lookupOrganisations_orgFuncListResponse', [
            'uuid' => $userUuid,
            'responseLength' => strlen($orgFuncListResponse),
            'response' => substr($orgFuncListResponse, 0, 2000),
        ]);

        $listDoc = new DOMDocument();
        $listDoc->loadXML($orgFuncListResponse);
        $listXpath = new DOMXPath($listDoc);
        $listXpath->registerNamespace('ns2', 'urn:oio:sagdok:3.0.0');
        $listXpath->registerNamespace('ns3', 'http://stoettesystemerne.dk/organisation/organisationfunktion/6/');

        $organisationUuids = [];
        $registreringNodes = $listXpath->query('//ns3:FiltreretOejebliksbillede/ns3:Registrering');

        foreach ($registreringNodes as $node) {
            $role = $listXpath->evaluate('string(ns3:AttributListe/ns3:Egenskab/ns2:FunktionNavn)', $node);
            $orgUuid = $listXpath->evaluate('string(ns3:RelationListe/ns2:TilknyttedeEnheder/ns2:ReferenceID/ns2:UUIDIdentifikator)', $node);
            $organisationUuids[] = new UserOrganisationData($orgUuid, $role);
        }
        $this->traceLogger->trace('UserClient_lookupOrganisations_organisationUuids', [
            'uuid' => $userUuid,
            'organisationUuids' => $organisationUuids,
        ]);

        return $organisationUuids;
    }
}
