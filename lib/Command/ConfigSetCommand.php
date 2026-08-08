<?php
declare(strict_types=1);

namespace OCA\OpenCase\Command;

use OCA\OpenCase\Service\Configuration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Set a single value in the opencase_config table. The key is created if it
 * does not exist yet.
 */
class ConfigSetCommand extends Command {

	public function __construct(
		private Configuration $configuration,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('opencase:config:set')
			->setDescription('Set a single value in the opencase_config table')
			->addArgument('key', InputArgument::REQUIRED, 'Config key (opencase_config.config_key)')
			->addArgument('value', InputArgument::REQUIRED, 'Value to store');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$key = $input->getArgument('key');
		$value = $input->getArgument('value');

		try {
			$previous = $this->configuration->getConfigValue($key);
			$this->configuration->setConfigValue($key, $value);
		} catch (\Throwable $e) {
			$output->writeln('<error>Failed to set ' . $key . ':</error>');
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		if ($previous === null) {
			$output->writeln('<info>' . $key . ' = ' . $value . '</info>');
		} else {
			$output->writeln('<info>' . $key . ': ' . $previous . ' -> ' . $value . '</info>');
		}

		return Command::SUCCESS;
	}
}
