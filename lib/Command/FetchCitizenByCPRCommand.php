<?php
declare(strict_types=1);

namespace OCA\OpenCase\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\Datafordeler\CitizenClient;

class FetchCitizenByCPRCommand extends Command {
	public function __construct(
		private Configuration $configuration,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('opencase:fetch-citizen-by-cpr')
			->setDescription('Fetch citizen data by CPR number (searches test data)')
			->addArgument('cpr', InputArgument::REQUIRED, 'The CPR number to look up');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($this->configuration->getConfigValue('enterprise_version', '0') !== '1' || !class_exists(CitizenClient::class)) {
			$output->writeln('<error>Datafordeler CPR (citizen search) is an Enterprise feature and is not enabled/installed on this instance.</error>');
			return Command::FAILURE;
		}

		$cpr = (string) $input->getArgument('cpr');

		try {
			$citizenClient = \OC::$server->get(CitizenClient::class);
			$citizen = $citizenClient->fetchByCPR($cpr);

			if ($citizen === null) {
				$output->writeln('No citizen found for CPR: ' . $cpr);
				return Command::SUCCESS;
			}

			$output->writeln('CPR:         ' . $citizen->cpr);
			$output->writeln('Name:        ' . $citizen->name);
			$output->writeln('Street:      ' . $citizen->streetname . ' ' . $citizen->housenumber);
			$output->writeln('Floor/Door:  ' . $citizen->floor . ' ' . $citizen->door);
			$output->writeln('Zip:         ' . $citizen->zipcode . ' ' . $citizen->zipdistrict);

			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$output->writeln('<error>Failed to fetch citizen:</error>');
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return Command::FAILURE;
		}
	}
}
