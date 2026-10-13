<?php
declare(strict_types=1);

namespace OCA\OpenCase\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\Serviceplatformen\CaseDocumentIndexClient;

class CreateDocumentDocumentIndexCommand extends Command {
	public function __construct(
        private Configuration $configuration,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('opencase:create-document-document-index')
			->setDescription('Create a document in the SagDokumentIndeks service')
			->addArgument('documentId', InputArgument::REQUIRED, 'The ID of the OpenCase document to create in the index');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($this->configuration->getConfigValue('enterprise_version', '0') !== '1' || !class_exists(CaseDocumentIndexClient::class)) {
			$output->writeln('<error>CaseDocumentIndex create is an Enterprise feature and is not enabled/installed on this instance.</error>');
			return Command::FAILURE;
		}

		$documentId = (int)$input->getArgument('documentId');
		$output->writeln('<info>Creating document ' . $documentId . '…</info>');

		try {
			$caseDocumentIndexClient = \OC::$server->get(CaseDocumentIndexClient::class);
			$response = $caseDocumentIndexClient->createDocument($documentId);

			$output->writeln('<info>Response:</info>');
			$output->writeln($response);

			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$output->writeln('<error>CaseDocumentIndex create failed:</error>');
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return Command::FAILURE;
		}
	}
}
