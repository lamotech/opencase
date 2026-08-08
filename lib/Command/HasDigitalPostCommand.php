<?php
declare(strict_types=1);

namespace OCA\OpenCase\Command;

use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\TraceLogger;
use OCA\OpenCase\Service\Serviceplatformen\PostForespoergClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use OCA\OpenCase\Db\CertificateRepository;

class HasDigitalPostCommand extends Command {
	public function __construct(
		private CertificateRepository $certificateRepository,
		private Configuration $configuration,
		private TraceLogger $traceLogger,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('opencase:has-digitalpost')
			->setDescription('Checks whether a CPR number has an active Digital Post subscription')
			->addArgument('receiver', InputArgument::REQUIRED, 'The CPR number to validate');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($this->configuration->getConfigValue('enterprise_version', '0') !== '1' || !class_exists(PostForespoergClient::class)) {
			$output->writeln('<error>Digital Post subscription check is an Enterprise feature and is not enabled/installed on this instance.</error>');
			return Command::FAILURE;
		}

		$receiver = (string)$input->getArgument('receiver');

		try {
			$client = new PostForespoergClient($this->certificateRepository, $this->configuration, $this->traceLogger);
			$result = $client->hasDigitalPostSubsciption($receiver);

			if ($result) {
				$output->writeln('<info>Digital Post subscription is active.</info>');
			} else {
				$output->writeln('<comment>Digital Post subscription is NOT active.</comment>');
			}

			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$output->writeln('<error>Validation failed:</error>');
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return Command::FAILURE;
		}
	}
}
