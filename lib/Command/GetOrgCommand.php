<?php
declare(strict_types=1);

namespace OCA\OpenCase\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use OCA\OpenCase\Service\Serviceplatformen\OrganisationClient;

class GetOrgCommand extends Command {
	public function __construct(
		private OrganisationClient $organisationClient,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('opencase:get-org')
			->setDescription('Fetch organisation unit information from the organisation service')
			->addArgument('uuid', InputArgument::REQUIRED, 'Organisation unit UUID identifier');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output->writeln('<info>Fetching organisation unit information…</info>');

		try {
			$uuid = $input->getArgument('uuid');
			$org = $this->organisationClient->fetchOrganisation($uuid);
			$output->writeln('<info>Organisation Unit Information:</info>');
			$output->writeln('UUID:        ' . $org->uuid);
			$output->writeln('Name:        ' . $org->name);
			$output->writeln('Parent UUID: ' . $org->parentUuid);
			$output->writeln('Addresses:');
			foreach ($org->addresses as $address) {
				$output->writeln('  - UUID: ' . $address->uuid . '  Role: ' . $address->roleUuid . '  Label: ' . $address->label . '  Text: ' . $address->text);
			}

			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$output->writeln('<error>Organisation fetch failed:</error>');
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return Command::FAILURE;
		}
	}
}
