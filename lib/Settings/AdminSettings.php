<?php

declare(strict_types=1);

namespace OCA\OpenCase\Settings;

use OCA\OpenCase\AppInfo\Application;
use OCA\OpenCase\Db\ApiClientRepository;
use OCA\OpenCase\Db\CertificateRepository;
use OCA\OpenCase\Db\ExportLogMapper;
use OCA\OpenCase\Db\KlassSyncLogRepository;
use OCA\OpenCase\Db\OrgSyncLogRepository;
use OCA\OpenCase\Enum\CertificateType;
use OCA\OpenCase\Service\ApiClientPresenterService;
use OCA\OpenCase\Service\Certificate;
use OCA\OpenCase\Service\Configuration;
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
		private ExportLogMapper $exportLogMapper,
		private IInitialState $initialState,
		private IAppManager $appManager,
		private ApiClientPresenterService $apiClientPresenterService,
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

		$this->initialState->provideInitialState('config', $this->configuration->getAllConfigValues());
		$this->initialState->provideInitialState('appVersion', $this->appManager->getAppVersion(Application::APP_ID));

		$certificate = $this->certificateRepository->find('Primary');
		$this->initialState->provideInitialState('certificate', [
			'filepath' => $certificate['filepath'] ?? '',
			'password' => $certificate['password'] ?? '',
		]);

		$datafordelerCertificate = $this->certificateRepository->find(CertificateType::Datafordeler->value);
		$this->initialState->provideInitialState('certificateDatafordeler', $datafordelerCertificate !== null
			? Certificate::parseCertificateFile($datafordelerCertificate['filepath'], $datafordelerCertificate['password'])
			: ['valid' => false, 'error' => 'not_found']);

		$this->initialState->provideInitialState('syncLog', $this->orgSyncLogRepository->findLatest(5));
		$this->initialState->provideInitialState('syncLogKlassifikation', $this->klassSyncLogRepository->findLatest(5));
		$this->initialState->provideInitialState('apiClients', $this->apiClientPresenterService->enrich($this->apiClientRepository->findAll()));

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
