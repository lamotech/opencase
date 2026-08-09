<?php
declare(strict_types=1);

namespace OCA\OpenCase\Command;

use OCA\OpenCase\Service\ClassificationSyncService;
use OCA\OpenCase\Service\Configuration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SyncClassificationsCommand extends Command {

	public function __construct(
		private Configuration $configuration,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('opencase:sync-classifications')
			->setDescription('Synchronize KLE classifications (Emneplan)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($this->configuration->getConfigValue('enterprise_version', '0') !== '1' || !class_exists(ClassificationSyncService::class)) {
			$output->writeln('<error>Classification synchronization is an Enterprise feature and is not enabled/installed on this instance.</error>');
			return Command::FAILURE;
		}

		$output->writeln('<info>Starting classification synchronization…</info>');

		try {
			$syncService = \OC::$server->get(ClassificationSyncService::class);
			$result = $syncService->sync();

			$output->writeln('<info>Synchronization finished successfully.</info>');
			foreach ($result as $key => $value) {
				$output->writeln(sprintf('  %s: %s', $key, (string)$value));
			}

			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$output->writeln('<error>Synchronization failed:</error>');
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return Command::FAILURE;
		}
	}
}
