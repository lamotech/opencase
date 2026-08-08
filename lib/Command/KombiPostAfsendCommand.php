<?php
declare(strict_types=1);

namespace OCA\OpenCase\Command;

use OCA\OpenCase\Db\CertificateRepository;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\TraceLogger;
use OCA\OpenCase\Service\Serviceplatformen\DigitalPostSendClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class KombiPostAfsendCommand extends Command {
	public function __construct(
		private CertificateRepository $certificateRepository,
		private Configuration $configuration,
		private TraceLogger $traceLogger,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('opencase:kombipost-afsend')
			->setDescription('Send a Digital Post message via KombiPostAfsend (SF1601)')
			->addArgument('receiver', InputArgument::REQUIRED, 'The CPR number of the recipient');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($this->configuration->getConfigValue('enterprise_version', '0') !== '1' || !class_exists(DigitalPostSendClient::class)) {
			$output->writeln('<error>KombiPostAfsend is an Enterprise feature and is not enabled/installed on this instance.</error>');
			return Command::FAILURE;
		}

		$receiver = (string)$input->getArgument('receiver');

		try {
			$client = new DigitalPostSendClient($this->certificateRepository, $this->configuration, $this->traceLogger);
			$response = $client->kombipostafsendTest($receiver);

			$output->writeln('<info>Message sent successfully:</info>');
			$output->writeln('Result:         ' . ($response->result ? 'true' : 'false'));
			$output->writeln('TransmissionID: ' . $response->transmissionId);
			$output->writeln('AdvisId:        ' . $response->advisId);
			$output->writeln('AdvisTekst:     ' . $response->advisTekst);

			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$output->writeln('<error>Send failed:</error>');
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return Command::FAILURE;
		}
	}
}
