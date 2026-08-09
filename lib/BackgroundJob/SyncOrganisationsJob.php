<?php

namespace OCA\OpenCase\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\OrganisationSyncService;
use Psr\Log\LoggerInterface;

class SyncOrganisationsJob extends TimedJob {
	private const TARGET_HOUR = 0; // Run at midnight (00:00-00:59)

	public function __construct(
		ITimeFactory $time,
		private OrganisationSyncService $organisationSyncService,
		private Configuration $configuration,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(60 * 60); // Check every hour during the target hour to ensure it runs even if a single run fails or is delayed.
	}

	protected function run($argument): void {
		$this->logger->info('SyncOrganisationsJob - running', ['app' => 'opencase']);
		$now = $this->time->getDateTime();
		$currentHour = (int)$now->format('G');
		$today = $now->format('Y-m-d');

		// Only run during the target hour (midnight)
		if ($currentHour !== self::TARGET_HOUR && $currentHour !== self::TARGET_HOUR+1) {
			$this->logger->info('SyncOrganisationsJob - not in target hour', ['app' => 'opencase']);
			return;
		}

		// Check if we already ran today
		$lastRunDate = $this->configuration->getConfigValue('organisation_last_sync_date', '');
		if ($lastRunDate === $today) {
			$this->logger->info('SyncOrganisationsJob - already ran today', ['app' => 'opencase']);
			return;
		}

		if ($this->configuration->getConfigValue('organisation_enable', '0') !== '1') {
			$this->logger->debug('Org sync skipped: organisation not enabled', ['app' => 'opencase']);
			return;
		}

		try {
			$res = $this->organisationSyncService->sync();
			$this->configuration->setConfigValue('organisation_last_sync_date', $today);
			$this->logger->info('Org sync finished', ['app' => 'opencase'] + $res);
		} catch (\Throwable $e) {
			$this->logger->error('Org sync failed: ' . $e->getMessage(), [
				'app' => 'opencase',
				'exception' => $e,
			]);
		}
	}
}
