<?php
declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\CertificateRepository;
use OCA\OpenCase\Enum\CertificateType;
use OneLogin\Saml2\Settings;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use OCP\IURLGenerator;
use DOMDocument;
use DOMXPath;
use DOMElement;

class SamlMetadataService {
	public function __construct(
		private IdpMetadataService $idpMetadata,
		private CertificateRepository $certificateRepository,
		private Configuration $configuration,
		private IURLGenerator $urlGenerator,
	) {}

	public function createSAMLMetadata(): string {

		// ---- SP config from database ----
		$entityIdPath = $this->configuration->getConfigValue('entity_id', '/index.php/apps/opencase/saml/metadata');
		$acsUrlPath = $this->configuration->getConfigValue('acs_url', '/index.php/apps/opencase/saml/acs');
		$slsUrlPath = $this->configuration->getConfigValue('sls_url', '/index.php/apps/opencase/saml/sls');

		$entityId = $this->urlGenerator->getAbsoluteURL($entityIdPath);
		$acsUrl = $this->urlGenerator->getAbsoluteURL($acsUrlPath);
		$slsUrl = $this->urlGenerator->getAbsoluteURL($slsUrlPath);
		$slsResponseUrl   = $slsUrl;

		// Your SP keys/certs (PEM)
		$certificate = new Certificate(CertificateType::FKAccess, $this->certificateRepository);
		$spPublicCert = $certificate->getPublicKeyBase64();
		$spPrivateKey = $certificate->getPrivateKeyBase64();

		// IdP settings from metadata (cached)
		$idp = $this->idpMetadata->getIdpSettingsFromMetadata();

		// Minimal example settings for an SP
		$settingsInfo = [
			'strict' => true,
			'debug'  => false,
		
			'sp' => [
				'entityId' => $entityId,
				'assertionConsumerService' => [
				  'url'     => $acsUrl,
				  'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
				],
				'singleLogoutService' => [
				  'url'     => $slsUrl,
				  'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
				],
				'NameIDFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:X509SubjectName',
			
				// published in metadata:
				'x509cert'   => $spPublicCert,
			
				// used at runtime for signing requests etc (not exported in metadata):
				'privateKey' => $spPrivateKey,
			  ],
		
			// IdP section can be empty for generating SP metadata,
			// but the library still expects the key to exist in many configs
			'idp' => [
				'entityId' => $idp['entityId'],
				'singleSignOnService' => $idp['singleSignOnService'],
				'singleLogoutService' => $idp['singleLogoutService'],
				'x509cert' => $idp['x509cert'], 
			],
		
			// security flags that show up in SPSSODescriptor as in your sample
			'security' => [
				'authnRequestsSigned' => true,
				'wantAssertionsSigned' => true,
				'wantAssertionsEncrypted' => true,
				'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
				'digestAlgorithm'    => 'http://www.w3.org/2001/04/xmlenc#sha256',
			],
		];
		
		$settings = new Settings($settingsInfo);
		
		// Generate metadata XML
		$metadata = $settings->getSPMetadata();
		
		// Validate metadata against schema rules
		$errors = $settings->validateMetadata($metadata);
			

		// ---- 2) Load DOM and ensure EntityDescriptor has an ID to reference ----
		$doc = new DOMDocument();
		$doc->preserveWhiteSpace = false;
		$doc->formatOutput = true;
		$doc->loadXML($metadata);

		$xpath = new \DOMXPath($doc);

		// Find SLS node
		$sls = $xpath->query('//*[local-name()="SingleLogoutService" and namespace-uri()="urn:oasis:names:tc:SAML:2.0:metadata"]')->item(0);
		if ($sls instanceof \DOMElement) {
			// Add ResponseLocation (optional but you want it)
			$sls->setAttribute('ResponseLocation', $slsResponseUrl);
		}
		
		// Find ACS nodes
		$acsNodes = $xpath->query('//*[local-name()="AssertionConsumerService" and namespace-uri()="urn:oasis:names:tc:SAML:2.0:metadata"]');
		if ($acsNodes && $acsNodes->length > 0) {
		
			// If you only have one ACS, make it index 0 and default
			/** @var \DOMElement $acs */
			$acs = $acsNodes->item(0);
		
			$acs->setAttribute('index', '0');
			$acs->setAttribute('isDefault', 'true');
		
			// (Optional) if your generator produced multiple ACS entries, ensure only one is default:
			for ($i = 1; $i < $acsNodes->length; $i++) {
				$acsNodes->item($i)->removeAttribute('isDefault');
			}
		}
		

		$doc->preserveWhiteSpace = true;   // keep exactly what is there
		$doc->formatOutput = false;        // IMPORTANT: do not re-indent

		/*
		$entityDescriptor = $doc->documentElement; // <EntityDescriptor ...>
		if (!$entityDescriptor->hasAttribute('ID')) {
		// Create an ID similar to your example: _uuid
		$id = $this->uuidv4();
		$entityDescriptor->setAttribute('ID', $id);
		} else {
		$id = $entityDescriptor->getAttribute('ID');
		}
		*/

		$entityDescriptor = $doc->documentElement;
		// Remove accidental "Id" attribute (case-sensitive!)
		/*
		if ($entityDescriptor->hasAttribute('Id')) {
		  $entityDescriptor->removeAttribute('Id');
		}
		
		// Ensure we have a single ID
		if (!$entityDescriptor->hasAttribute('ID')) {
		  $entityDescriptor->setAttribute('ID', '_' . $this->uuidv4());
		}
		*/
		// Tell DOM / xmlsec this attribute is of type ID
		/*
		$entityDescriptor->setAttribute('Id', '_' . $this->uuidv4());
		$entityDescriptor->setIdAttribute('Id', true);
		$id = $entityDescriptor->getAttribute('Id');
*/

/*
		$this->removeBlankTextNodes($entityDescriptor);
		$entityDescriptor->removeAttribute('validUntil');
		$entityDescriptor->removeAttribute('cacheDuration');

		$entityDescriptor->setAttribute('ID', $id);
		$entityDescriptor->setIdAttribute('ID', true);
		// ---- 4) Sign (enveloped) with xmlseclibs: exc-c14n, rsa-sha256, sha256 digest ----
		$dsig = new XMLSecurityDSig();
		$dsig->idKeys = ['ID'];
		$dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
*/

		$id = '_' . $this->uuidv4();
		$this->removeBlankTextNodes($entityDescriptor);            // before signing
		$entityDescriptor->removeAttribute('validUntil');
		$entityDescriptor->removeAttribute('cacheDuration');
		$entityDescriptor->removeAttribute('Id');
		$entityDescriptor->setAttribute('ID', $id);
		$entityDescriptor->setIdAttribute('ID', true);

		$dsig = new XMLSecurityDSig();
		$dsig->idKeys = ['ID'];
		$dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);

		// Reference the EntityDescriptor by its ID
		$dsig->addReference(
			$entityDescriptor,
			XMLSecurityDSig::SHA256,
			[
				'http://www.w3.org/2000/09/xmldsig#enveloped-signature',
				'http://www.w3.org/2001/10/xml-exc-c14n#',
			],
			[
				'id_name'   => 'ID',         // use ID attribute name
				'force_uri' => true,         // do not auto-generate pfx... URIs
				'uri'       => '#' . $id,    // the URI you want
			]
		);

			/*
		//$this->removeBlankTextNodes($entityDescriptor);
		$id = $entityDescriptor->getAttribute('Id');
		$entityDescriptor->removeAttribute('Id');
		$entityDescriptor->setAttribute('ID', $id);
		$entityDescriptor->setIdAttribute('ID', true);  // also important
*/

		//$entityDescriptor->removeAttribute('validUntil');
		//$entityDescriptor->removeAttribute('cacheDuration');


		// Format certificates to proper PEM format
		$spPrivateKeyPem = $this->formatAsPem($spPrivateKey, 'PRIVATE KEY');
		$spPublicCertPem = $this->formatAsPem($spPublicCert, 'CERTIFICATE');

		$key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
		$key->loadKey($spPrivateKeyPem, false, false);

		/*
		if ($entityDescriptor->hasAttribute('Id')) {
			$entityDescriptor->removeAttribute('Id');
		  }
*/
		
		
		// Create signature and insert it right under EntityDescriptor (like your sample)
		$dsig->sign($key);
		$dsig->add509Cert($spPublicCertPem, true, false, ['issuerSerial' => false]);
		$dsig->insertSignature($entityDescriptor, $entityDescriptor->firstChild);

		// Save XML without the XML declaration
		$metadata = $doc->saveXML($doc->documentElement);

		return $metadata;
	}

	// ---------------- helpers ----------------

	function removeBlankTextNodes(DOMElement $node): void {
		if (!$node->hasChildNodes()) return;
	  
		// iterate backwards because we may remove nodes
		for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
		  $child = $node->childNodes->item($i);
	  
		  if ($child->nodeType === XML_TEXT_NODE && trim($child->nodeValue) === '') {
			$node->removeChild($child);
			continue;
		  }
		  if ($child instanceof DOMElement && $child->hasChildNodes()) {
			$this->removeBlankTextNodes($child);
		  }
		}
	  }

	  
	/**
	 * Format a base64 string as PEM (with proper headers and line breaks)
	 */
	private function formatAsPem(string $base64String, string $type): string {
		// Remove any existing whitespace
		$base64String = preg_replace('/\s+/', '', $base64String);
		
		// Add line breaks every 64 characters
		$formatted = chunk_split($base64String, 64, "\n");
		
		// Remove trailing newline
		$formatted = rtrim($formatted, "\n");
		
		// Add PEM headers
		return "-----BEGIN {$type}-----\n{$formatted}\n-----END {$type}-----\n";
	}

	function uuidv4(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
		$hex = bin2hex($data);
		return sprintf('%s-%s-%s-%s-%s',
		substr($hex, 0, 8),
		substr($hex, 8, 4),
		substr($hex, 12, 4),
		substr($hex, 16, 4),
		substr($hex, 20, 12)
		);
	}
  
	/**
	 * Force a particular prefix for a namespace throughout the document.
	 * This is ONLY needed if someone hard-requires "m:" rather than "md:".
	 */
	function forcePrefix(DOMDocument $doc, string $ns, string $prefix): void {
		$xpath = new DOMXPath($doc);
		// Find all elements in the namespace (XPath doesn't need a prefix if we use local-name + namespace-uri)
		$nodes = $xpath->query('//*[namespace-uri()="'.$ns.'"]');
		if (!$nodes) return;
	
		/** @var DOMElement $el */
		foreach ($nodes as $el) {
		// Rename node with desired prefix
		$doc->renameNode($el, $ns, $prefix . ':' . $el->localName);
		}
	
		// Ensure root has xmlns:prefix mapping
		$root = $doc->documentElement;
		if ($root && !$root->hasAttribute('xmlns:' . $prefix)) {
		$root->setAttribute('xmlns:' . $prefix, $ns);
		}
    }	
}

