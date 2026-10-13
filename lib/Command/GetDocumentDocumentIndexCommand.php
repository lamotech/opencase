<?php
declare(strict_types=1);

namespace OCA\OpenCase\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\Serviceplatformen\CaseDocumentIndexClient;

class GetDocumentDocumentIndexCommand extends Command {
	public function __construct(
        private Configuration $configuration,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('opencase:get-document-document-index')
			->setDescription('Fetch a document from the SagDokumentIndeks service')
			->addArgument('documentUuid', InputArgument::REQUIRED, 'The UUID of the document to fetch');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($this->configuration->getConfigValue('enterprise_version', '0') !== '1' || !class_exists(CaseDocumentIndexClient::class)) {
			$output->writeln('<error>CaseDocumentIndex fetch is an Enterprise feature and is not enabled/installed on this instance.</error>');
			return Command::FAILURE;
		}

		$documentUuid = (string)$input->getArgument('documentUuid');
		$output->writeln('<info>Fetching document ' . $documentUuid . '…</info>');

		try {
			$caseDocumentIndexClient = \OC::$server->get(CaseDocumentIndexClient::class);
			$response = $caseDocumentIndexClient->getDocument($documentUuid);

			$output->writeln('<info>Response:</info>');
			$output->writeln($response);

			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$output->writeln('<error>CaseDocumentIndex fetch failed:</error>');
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return Command::FAILURE;
		}
	}
}
