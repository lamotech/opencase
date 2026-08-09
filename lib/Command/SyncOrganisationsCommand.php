<?php
declare(strict_types=1);

namespace OCA\OpenCase\Command;

use OCA\OpenCase\Service\OrganisationSyncService;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\TraceLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SyncOrganisationsCommand extends Command {

	public function __construct(
		private OrganisationSyncService $syncService,
		private Configuration $configuration,
		private TraceLogger $traceLogger,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('opencase:sync-org')
			->setDescription('Synchronize municipal organisations');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output->writeln('<info>Starting organisation synchronization…</info>');
		$this->traceLogger->trace('org_sync_job_started', ['pid' => getmypid()]);
		$this->configuration->setConfigValue('org_sync_status', 'running');
		$this->configuration->setConfigValue('org_sync_error', null);

		try {
			$result = $this->syncService->sync();

			$this->configuration->setConfigValue('org_sync_result', json_encode($result));
			$this->configuration->setConfigValue('org_sync_status', 'idle');
			$this->traceLogger->trace('org_sync_job_finished', $result);

			$output->writeln('<info>Synchronization finished successfully.</info>');
			foreach ($result as $key => $value) {
				$output->writeln(sprintf('  %s: %s', $key, (string)$value));
			}

			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$this->configuration->setConfigValue('org_sync_error', $e->getMessage());
			$this->configuration->setConfigValue('org_sync_status', 'idle');
			$this->traceLogger->error('org_sync_job_failed', $e);

			$output->writeln('<error>Synchronization failed:</error>');
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return Command::FAILURE;
		}
	}
}
