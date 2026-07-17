<?php
declare(strict_types=1);

namespace OCA\OpenCase\Command;

use OCA\OpenCase\Service\Configuration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TraceDisableCommand extends Command {

	public function __construct(
		private Configuration $configuration,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('opencase:trace-disable')
			->setDescription('Disable trace logging');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$this->configuration->setConfigValue('trace_log', '0');
			$output->writeln('<info>Trace logging disabled.</info>');
			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$output->writeln('<error>Failed to disable trace logging:</error>');
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}
	}
}
