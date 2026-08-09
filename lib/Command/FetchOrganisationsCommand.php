<?php
declare(strict_types=1);

namespace OCA\OpenCase\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use OCA\OpenCase\Service\Serviceplatformen\OrganisationClient;

class FetchOrganisationsCommand extends Command {
	public function __construct(
        private OrganisationClient $orgClient,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('opencase:fetch-orgs')
			->setDescription('Fetch organisations from the organisation service');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output->writeln('<info>Fetching organisations…</info>');

		try {
            $orgs = $this->orgClient->fetchOrganisations();

            $output->writeln('<info>Organisation Enheder:</info>');
			foreach ($orgs as $org) {
				$uuid = $org->uuid;
				$name = $org->name;
				$parentUuid = $org->parentUuid;
                
                $output->writeln($uuid . ' - ' . $name . ' - ' . $parentUuid);
			}
            $output->writeln(count($orgs) . ' organisations fetched.');
			
			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$output->writeln('<error>Organisations fetch failed:</error>');
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return Command::FAILURE;
		}
	}
}

