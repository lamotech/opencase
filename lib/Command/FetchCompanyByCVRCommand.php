<?php
declare(strict_types=1);

namespace OCA\OpenCase\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\Datafordeler\CompanyClient;

class FetchCompanyByCVRCommand extends Command {
	public function __construct(
		private Configuration $configuration,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('opencase:fetch-company-by-cvr')
			->setDescription('Fetch company data from Datafordeler by CVR number')
			->addArgument('cvr', InputArgument::REQUIRED, 'The CVR number to look up');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($this->configuration->getConfigValue('enterprise_version', '0') !== '1' || !class_exists(CompanyClient::class)) {
			$output->writeln('<error>Datafordeler CVR (company search) is an Enterprise feature and is not enabled/installed on this instance.</error>');
			return Command::FAILURE;
		}

		$cvr = (string) $input->getArgument('cvr');

		try {
			$companyClient = \OC::$server->get(CompanyClient::class);
			$company = $companyClient->fetchByCVR($cvr);

			$output->writeln('CVR:         ' . $company->cvr);
			$output->writeln('Name:        ' . $company->name);
			$output->writeln('Street:      ' . $company->streetname . ' ' . $company->housenumber);
			$output->writeln('Floor/Door:  ' . $company->floor . ' ' . $company->door);
			$output->writeln('Zip:         ' . $company->zipcode . ' ' . $company->zipdistrict);
			$output->writeln('Phone:       ' . $company->phone);
			$output->writeln('Email:       ' . $company->email);

			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$output->writeln('<error>Failed to fetch company:</error>');
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return Command::FAILURE;
		}
	}
}
