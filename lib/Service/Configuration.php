<?php
declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCP\IDBConnection;

class Configuration {
	private array $cache = [];

	public function __construct(
		private IDBConnection $db,
	) {}

	public function getConfigValue(string $key, ?string $default = null): ?string {
		if (isset($this->cache[$key])) {
			return $this->cache[$key];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('config_value')
			->from('opencase_config')
			->where($qb->expr()->eq('config_key', $qb->createNamedParameter($key)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			return $default;
		}

		$this->cache[$key] = $row['config_value'];
		return $row['config_value'];
	}

	public function setConfigValue(string $key, ?string $value): void {
		$existing = $this->getConfigValue($key);

		$qb = $this->db->getQueryBuilder();

		if ($existing !== null || $this->configKeyExists($key)) {
			$qb->update('opencase_config')
				->set('config_value', $qb->createNamedParameter($value))
				->where($qb->expr()->eq('config_key', $qb->createNamedParameter($key)))
				->executeStatement();
		} else {
			$qb->insert('opencase_config')
				->values([
					'config_key' => $qb->createNamedParameter($key),
					'config_value' => $qb->createNamedParameter($value),
				])
				->executeStatement();
		}

		$this->cache[$key] = $value;
	}

	public function deleteConfigValue(string $key): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('opencase_config')
			->where($qb->expr()->eq('config_key', $qb->createNamedParameter($key)))
			->executeStatement();

		unset($this->cache[$key]);
	}

	public function getAllConfigValues(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('config_key', 'config_value')
			->from('opencase_config');

		$result = $qb->executeQuery();
		$configs = [];

		while ($row = $result->fetch()) {
			$configs[$row['config_key']] = $row['config_value'];
			$this->cache[$row['config_key']] = $row['config_value'];
		}

		$result->closeCursor();
		return $configs;
	}

	private function configKeyExists(string $key): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('config_key')
			->from('opencase_config')
			->where($qb->expr()->eq('config_key', $qb->createNamedParameter($key)));

		$result = $qb->executeQuery();
		$exists = $result->fetch() !== false;
		$result->closeCursor();

		return $exists;
	}

	/**
	 * Ensure default configuration values exist in the database
	 * This is called when the settings page is loaded
	 */
	public function ensureDefaultsExist(): void {
		$defaults = [
			'organisation_enable' => '0',
			'cvr' => '11111111',
			'token_issuer_base_url' => 'https://n2adgangsstyring.eksterntest-stoettesystemerne.dk/',
			'token_issuer_endpoint' => '/runtime/api/rest/wstrust/v1/issue',
			'entity_id_organisation' => 'http://stoettesystemerne.dk/service/organisation/3',
			'endpoint_organisation' => 'https://organisation.eksterntest-stoettesystemerne.dk/organisation/organisationsystem/6/',
			'endpoint_organisation_enhed' => 'https://organisation.eksterntest-stoettesystemerne.dk/organisation/organisationenhed/6/',
			'endpoint_organisation_function' => 'https://organisation.eksterntest-stoettesystemerne.dk/organisation/organisationfunktion/6/',
			'endpoint_user' => 'https://organisation.eksterntest-stoettesystemerne.dk/organisation/bruger/6/',
			'endpoint_person' => 'https://organisation.eksterntest-stoettesystemerne.dk/organisation/person/6/',
			'endpoint_address' => 'https://organisation.eksterntest-stoettesystemerne.dk/organisation/adresse/6/',
			'entity_id_fordeling' => 'http://sp.serviceplatformen.dk/service/fordeling/3',
			'endpoint_fordeling' => 'https://exttest.serviceplatformen.dk/service/SP/Distribution/3',
			'access_control_enable' => '0',
			'idp_metadata_url' => 'https://n2adgangsstyring.eksterntest-stoettesystemerne.dk/runtime/saml2/metadata.idp?samlprofile=nemlogin3',
			'entity_id' => '/index.php/apps/opencase/saml/metadata',
			'acs_url' => '/index.php/apps/opencase/saml/acs',
			'sls_url' => '/index.php/apps/opencase/saml/sls',
			'entity_id_classification' => 'http://entityid.kombit.dk/service/klassifikation/7',
			'endpoint_classification' => 'https://klassifikation.eksterntest-stoettesystemerne.dk/klassifikationsystem/7/',
			'digitalpost_cvr' => '19435075',
			'digitalpost_sender_label' => 'KOMBIT',
			'entity_id_kombipostafsend' => 'http://entityid.kombit.dk/service/kombipostafsend/1',
			'endpoint_kombipostafsend' => 'https://exttest.serviceplatformen.dk/service/KombiPostAfsend_1/kombi',
			'entity_id_postforespoerg' => 'http://entityid.kombit.dk/service/postforespoerg/1',
			'endpoint_postforespoerg' => 'https://exttest.serviceplatformen.dk/service/PostForespoerg_1',
			'datafordeler_endpoint_cvr' => 'https://graphql.datafordeler.dk/flexibleCurrent/v1',
			'sftp_host' => 'sftpexttest.serviceplatformen.dk',
			'sftp_user_name' => 'opencase',
			'sftp_ssh_private_key_full_fileName' => '/var/certificates/sshkeyfile.ssh',
			'search_max_result_count' => '500',
			'search_page_size' => '50',
			'history_count' => '20',
			'msoffice_addin_port' => '1443',
			'msoffice_addin_name' => 'OpenCase',
			'enterprise_version' => '0',
			'entity_id_opencase_api' => 'http://opencase.dk/service/api/1',
		];

		foreach ($defaults as $key => $value) {
			// Only insert if the key doesn't exist
			if (!$this->configKeyExists($key)) {
				$this->setConfigValue($key, $value);
			}
		}
	}
}
