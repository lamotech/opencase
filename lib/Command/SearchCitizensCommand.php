<?php
declare(strict_types=1);

namespace OCA\OpenCase\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\Datafordeler\CitizenClient;

class SearchCitizensCommand extends Command {
	public function __construct(
		private Configuration $configuration,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('opencase:search-citizens')
			->setDescription('Search citizens via the Datafordeler CPR service by name and/or address fields')
			->addOption('firstname', null, InputOption::VALUE_OPTIONAL, 'First name to search for', '')
			->addOption('lastname', null, InputOption::VALUE_OPTIONAL, 'Last name to search for', '')
			->addOption('streetname', null, InputOption::VALUE_OPTIONAL, 'Street name to search for', '')
			->addOption('housenumber', null, InputOption::VALUE_OPTIONAL, 'House number to search for', '')
			->addOption('zipcode', null, InputOption::VALUE_OPTIONAL, 'Zip code (exact match)', '')
			->addOption('zipdistrict', null, InputOption::VALUE_OPTIONAL, 'Zip district to search for', '');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($this->configuration->getConfigValue('enterprise_version', '0') !== '1' || !class_exists(CitizenClient::class)) {
			$output->writeln('<error>Datafordeler CPR (citizen search) is an Enterprise feature and is not enabled/installed on this instance.</error>');
			return Command::FAILURE;
		}

		try {
			$citizenClient = \OC::$server->get(CitizenClient::class);
			$citizens = $citizenClient->search(
				firstname: (string) $input->getOption('firstname'),
				lastname: (string) $input->getOption('lastname'),
				streetname: (string) $input->getOption('streetname'),
				housenumber: (string) $input->getOption('housenumber'),
				zipcode: (string) $input->getOption('zipcode'),
				zipdistrict: (string) $input->getOption('zipdistrict'),
			);

			if (count($citizens) === 0) {
				$output->writeln('No citizens found.');
				return Command::SUCCESS;
			}

			foreach ($citizens as $citizen) {
				$output->writeln('---');
				$output->writeln('CPR:         ' . $citizen->cpr);
				$output->writeln('Name:        ' . $citizen->name);
				$output->writeln('Street:      ' . $citizen->streetname . ' ' . $citizen->housenumber);
				$output->writeln('Floor/Door:  ' . $citizen->floor . ' ' . $citizen->door);
				$output->writeln('Zip:         ' . $citizen->zipcode . ' ' . $citizen->zipdistrict);
			}
			$output->writeln('---');
			$output->writeln(count($citizens) . ' citizen(s) found.');

			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$output->writeln('<error>Failed to search citizens:</error>');
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return Command::FAILURE;
		}
	}
}
