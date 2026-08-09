<?php
declare(strict_types=1);

namespace OCA\OpenCase\Command;

use OCA\OpenCase\Db\ConfigMapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * List the values in the opencase_config table, optionally filtered by a
 * substring of the config key.
 */
class ConfigListCommand extends Command {

	public function __construct(
		private ConfigMapper $configMapper,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('opencase:config:list')
			->setDescription('List the values in the opencase_config table')
			->addArgument('filter', InputArgument::OPTIONAL, 'Only show keys containing this string')
			->addOption('plain', null, InputOption::VALUE_NONE, 'Print "key=value" lines instead of a table, for use by shell scripts');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$filter = $input->getArgument('filter');
		$entries = $this->configMapper->getAllWithMeta();

		if ($filter !== null && $filter !== '') {
			$entries = array_values(array_filter(
				$entries,
				static fn (array $entry): bool => stripos($entry['key'], $filter) !== false
			));
		}

		if ($input->getOption('plain')) {
			foreach ($entries as $entry) {
				$output->writeln($entry['key'] . '=' . $entry['value']);
			}
			return Command::SUCCESS;
		}

		if ($entries === []) {
			$output->writeln('<comment>No config values found.</comment>');
			return Command::SUCCESS;
		}

		$table = new Table($output);
		$table->setHeaders(['Key', 'Value']);
		foreach ($entries as $entry) {
			$table->addRow([
				$entry['key'],
				$entry['value'],
			]);
		}
		$table->render();

		return Command::SUCCESS;
	}
}
