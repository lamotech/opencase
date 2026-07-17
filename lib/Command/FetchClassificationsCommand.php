<?php
declare(strict_types=1);

namespace OCA\OpenCase\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\Serviceplatformen\ClassificationClient;

class FetchClassificationsCommand extends Command {
	public function __construct(
        private Configuration $configuration,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('opencase:fetch-classifications')
			->setDescription('Fetch classifications from the classification service')
			->addArgument('plan', \Symfony\Component\Console\Input\InputArgument::OPTIONAL, 'The plan to fetch (e.g. "Emneplan" or "Handlingsfacetter")');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($this->configuration->getConfigValue('enterprise_version', '0') !== '1' || !class_exists(ClassificationClient::class)) {
			$output->writeln('<error>Classification fetch is an Enterprise feature and is not enabled/installed on this instance.</error>');
			return Command::FAILURE;
		}

		$output->writeln('<info>Fetching classifications…</info>');
		$plan = (string)$input->getArgument('plan');

		try {
			$classificationClient = \OC::$server->get(ClassificationClient::class);
			if($plan === 'Handlingsfacetter') {
				$output->writeln('<info>Fetching Handlingsfacetter…</info>');
				$classifications = $classificationClient->fetchKLE('Handlingsfacetter');
			} else {
				$output->writeln('<info>Fetching Emneplan…</info>');
            	$classifications = $classificationClient->fetchKLE('Emneplan');
			}

            $output->writeln('<info>Classifications:</info>');
			foreach ($classifications as $classification) {
				$uuid = $classification->uuid;
				$code = $classification->code;
				$title = $classification->title;
				$description = $classification->description;

                $output->writeln($uuid . ' - ' . $code . ' - ' . $title);
			}
            $output->writeln(count($classifications) . ' classifications fetched.');

			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$output->writeln('<error>Classifications fetch failed:</error>');
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return Command::FAILURE;
		}
	}
}

