<?php
declare(strict_types=1);

namespace OCA\OpenCase\Command;

use OCA\OpenCase\Service\Configuration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Print a single value from the opencase_config table, for use by shell
 * scripts (e.g. msoffice/nginx/deploy.sh) that need a deployment setting
 * without embedding database credentials.
 */
class ConfigGetCommand extends Command {

	public function __construct(
		private Configuration $configuration,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('opencase:config:get')
			->setDescription('Print a single value from the opencase_config table')
			->addArgument('key', InputArgument::REQUIRED, 'Config key (opencase_config.config_key)')
			->addArgument('default', InputArgument::OPTIONAL, 'Value to print if the key is not set', '');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$value = $this->configuration->getConfigValue(
			$input->getArgument('key'),
			$input->getArgument('default')
		);
		$output->writeln($value ?? '');
		return Command::SUCCESS;
	}
}
