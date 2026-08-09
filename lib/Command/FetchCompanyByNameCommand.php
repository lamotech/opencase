<?php
declare(strict_types=1);

namespace OCA\OpenCase\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\Datafordeler\CompanyClient;

class FetchCompanyByNameCommand extends Command {
	public function __construct(
		private Configuration $configuration,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('opencase:fetch-company-by-name')
			->setDescription('Search for companies in Datafordeler by name')
			->addArgument('name', InputArgument::REQUIRED, 'The exact company name to search for');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($this->configuration->getConfigValue('enterprise_version', '0') !== '1' || !class_exists(CompanyClient::class)) {
			$output->writeln('<error>Datafordeler CVR (company search) is an Enterprise feature and is not enabled/installed on this instance.</error>');
			return Command::FAILURE;
		}

		$name = (string) $input->getArgument('name');

		try {
			$companyClient = \OC::$server->get(CompanyClient::class);
			$companies = $companyClient->fetchByName($name);

			if (count($companies) === 0) {
				$output->writeln('No companies found.');
				return Command::SUCCESS;
			}

			foreach ($companies as $company) {
				$output->writeln('---');
				$output->writeln('CVR:         ' . $company->cvr);
				$output->writeln('Name:        ' . $company->name);
				$output->writeln('Street:      ' . $company->streetname . ' ' . $company->housenumber);
				$output->writeln('Floor/Door:  ' . $company->floor . ' ' . $company->door);
				$output->writeln('Zip:         ' . $company->zipcode . ' ' . $company->zipdistrict);
				$output->writeln('Phone:       ' . $company->phone);
				$output->writeln('Email:       ' . $company->email);
			}
			$output->writeln('---');
			$output->writeln(count($companies) . ' company/companies found.');

			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$output->writeln('<error>Failed to fetch companies:</error>');
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return Command::FAILURE;
		}
	}
}
