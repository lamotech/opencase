<?php

declare(strict_types=1);

namespace OCA\OpenCase\Settings;

use OCA\OpenCase\AppInfo\Application;
use OCA\OpenCase\Db\ApiClientRepository;
use OCA\OpenCase\Db\CertificateRepository;
use OCA\OpenCase\Db\ExportLogMapper;
use OCA\OpenCase\Db\ImportLocationRepository;
use OCA\OpenCase\Db\KlassSyncLogRepository;
use OCA\OpenCase\Db\OrgSyncLogRepository;
use OCA\OpenCase\Enum\CertificateType;
use OCA\OpenCase\Service\ApiClientPresenterService;
use OCA\OpenCase\Service\Certificate;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\CustomerTypeService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {

	public function __construct(
		private Configuration $configuration,
		private CertificateRepository $certificateRepository,
		private OrgSyncLogRepository $orgSyncLogRepository,
		private KlassSyncLogRepository $klassSyncLogRepository,
		private ApiClientRepository $apiClientRepository,
		private ImportLocationRepository $importLocationRepository,
		private ExportLogMapper $exportLogMapper,
		private IInitialState $initialState,
		private IAppManager $appManager,
		private ApiClientPresenterService $apiClientPresenterService,
		private CustomerTypeService $customerTypeService,
	) {
	}

	public function getForm(): TemplateResponse {
		// Ensure default configuration values exist before loading the settings page
		$this->configuration->ensureDefaultsExist();

		$this->initialState->provideInitialState('prerequisites', [
			'groupfolders' => $this->appManager->isEnabledForUser('groupfolders'),
			'fulltextsearch' => $this->appManager->isEnabledForUser('fulltextsearch'),
			'files_fulltextsearch' => $this->appManager->isEnabledForUser('files_fulltextsearch'),
			'fulltextsearch_elasticsearch' => $this->appManager->isEnabledForUser('fulltextsearch_elasticsearch'),
			'assistant' => $this->appManager->isEnabledForUser('assistant'),
			'integration_openai' => $this->appManager->isEnabledForUser('integration_openai'),
		]);

		// Secret values are blank in 'config'; 'configSecrets' only says whether each is set.
		$this->initialState->provideInitialState('config', $this->configuration->getAllConfigValues());
		$this->initialState->provideInitialState('configSecrets', $this->configuration->getSecretConfigStatus());
		$this->initialState->provideInitialState('customerType', [
			'type'   => $this->customerTypeService->activeType(),
			'chosen' => $this->customerTypeService->isChosen(),
		]);
		$this->initialState->provideInitialState('appVersion', $this->appManager->getAppVersion(Application::APP_ID));

		// Certificates are described server-side. Never send the certificate
		// password or file location to the browser.
		$this->initialState->provideInitialState('certificate',
			Certificate::describeRegistered($this->certificateRepository, CertificateType::Primary));
		$this->initialState->provideInitialState('certificateDatafordeler',
			Certificate::describeRegistered($this->certificateRepository, CertificateType::Datafordeler));

		$this->initialState->provideInitialState('syncLog', $this->orgSyncLogRepository->findLatest(5));
		$this->initialState->provideInitialState('syncLogKlassifikation', $this->klassSyncLogRepository->findLatest(5));
		$this->initialState->provideInitialState('apiClients', $this->apiClientPresenterService->enrich($this->apiClientRepository->findAll()));

		$importLocations = array_merge(
			$this->importLocationRepository->findAllByType('folder'),
			$this->importLocationRepository->findAllByType('mailbox'),
		);
		// Never send the mailbox password to the browser.
		$importLocations = array_map(static function (array $row): array {
			unset($row['mailbox_password']);
			return $row;
		}, $importLocations);
		$this->initialState->provideInitialState('importLocations', $importLocations);

		$exportLog = array_map(static fn ($e) => [
			'sync_time' => $e->getSyncTime()?->format('Y-m-d H:i:s') ?? '',
			'count'     => $e->getCount(),
			'exported'  => $e->getExported(),
			'failed'    => $e->getFailed(),
		], $this->exportLogMapper->findRecent(5));
		$this->initialState->provideInitialState('exportLog', $exportLog);

		\OCP\Util::addTranslations(Application::APP_ID);
		\OCP\Util::addScript(Application::APP_ID, 'opencase-admin-settings');
		\OCP\Util::addStyle(Application::APP_ID, 'opencase-admin-settings');

		return new TemplateResponse(Application::APP_ID, 'settings-admin', [], '');
	}

	public function getSection(): string {
		return 'opencase';
	}

	public function getPriority(): int {
		return 10;
	}
}
