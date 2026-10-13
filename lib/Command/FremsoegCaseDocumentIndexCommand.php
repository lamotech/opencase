<?php
declare(strict_types=1);

namespace OCA\OpenCase\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\Serviceplatformen\CaseDocumentIndexClient;

class FremsoegCaseDocumentIndexCommand extends Command {
	public function __construct(
        private Configuration $configuration,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('opencase:fremsoeg-case-document-index')
			->setDescription('Search for a case by title in the SagDokumentIndeks service')
			->addArgument('title', InputArgument::REQUIRED, 'The case title to search for');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($this->configuration->getConfigValue('enterprise_version', '0') !== '1' || !class_exists(CaseDocumentIndexClient::class)) {
			$output->writeln('<error>CaseDocumentIndex fremsoeg is an Enterprise feature and is not enabled/installed on this instance.</error>');
			return Command::FAILURE;
		}

		$title = (string)$input->getArgument('title');
		$output->writeln('<info>Searching for case with title "' . $title . '"…</info>');

		try {
			$caseDocumentIndexClient = \OC::$server->get(CaseDocumentIndexClient::class);
			$response = $caseDocumentIndexClient->fremsoeg($title);

			$output->writeln('<info>Response:</info>');
			$output->writeln($response);

			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$output->writeln('<error>CaseDocumentIndex fremsoeg failed:</error>');
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return Command::FAILURE;
		}
	}
}
