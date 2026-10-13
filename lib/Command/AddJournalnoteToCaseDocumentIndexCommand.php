<?php
declare(strict_types=1);

namespace OCA\OpenCase\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\Serviceplatformen\CaseDocumentIndexClient;

class AddJournalnoteToCaseDocumentIndexCommand extends Command {
	public function __construct(
        private Configuration $configuration,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('opencase:add-journalnote-to-case-document-index')
			->setDescription('Add a journal note to its case in the SagDokumentIndeks service')
			->addArgument('journalNoteId', InputArgument::REQUIRED, 'The ID of the OpenCase journal note to add to its case');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($this->configuration->getConfigValue('enterprise_version', '0') !== '1' || !class_exists(CaseDocumentIndexClient::class)) {
			$output->writeln('<error>CaseDocumentIndex add journal note is an Enterprise feature and is not enabled/installed on this instance.</error>');
			return Command::FAILURE;
		}

		$journalNoteId = (int)$input->getArgument('journalNoteId');
		$output->writeln('<info>Adding journal note ' . $journalNoteId . ' to its case…</info>');

		try {
			$caseDocumentIndexClient = \OC::$server->get(CaseDocumentIndexClient::class);
			$response = $caseDocumentIndexClient->addJournalnoteToCase($journalNoteId);

			$output->writeln('<info>Response:</info>');
			$output->writeln($response);

			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$output->writeln('<error>CaseDocumentIndex add journal note failed:</error>');
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return Command::FAILURE;
		}
	}
}
