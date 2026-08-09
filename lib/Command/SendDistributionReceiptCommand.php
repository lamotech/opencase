<?php
declare(strict_types=1);

namespace OCA\OpenCase\Command;

use OCA\OpenCase\Db\CertificateRepository;
use OCA\OpenCase\Enum\CertificateType;
use OCA\OpenCase\Service\Certificate;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\TraceLogger;
use OCA\OpenCase\Service\Serviceplatformen\DistributionConfiguration;
use OCA\OpenCase\Service\Serviceplatformen\DistributionWrapper;
use OCA\OpenCase\Service\Serviceplatformen\TokenIssuerREST;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SendDistributionReceiptCommand extends Command {
	public function __construct(
		private CertificateRepository $certificateRepository,
		private Configuration $configuration,
		private TraceLogger $traceLogger,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('opencase:send-distribution-receipt')
			->setDescription('Send a Fordelingskvittering (distribution receipt) via the Distribution service')
			->addArgument('forretningsValideringsKode', InputArgument::REQUIRED, 'ForretningsValideringsKode (MODTAGET, AFLEVERET, ACCEPTERET, AFVIST or FEJLET)')
			->addArgument('kvitteringstype', InputArgument::REQUIRED, 'Kvitteringstype (Teknisk, Forretning or Digital post)')
			->addArgument('anvenderTransaktionsID', InputArgument::REQUIRED, 'AnvenderTransaktionsID of the distribution being acknowledged')
			->addArgument('afsendendeMyndighed', InputArgument::REQUIRED, 'AfsendendeMyndighed (CVR of the sending authority)')
			->addArgument('routingMyndighed', InputArgument::REQUIRED, 'RoutingMyndighed (CVR of the routing authority)')
			->addArgument('routingModtagerAktoer', InputArgument::REQUIRED, 'RoutingModtagerAktoer (UUID of the receiving actor)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($this->configuration->getConfigValue('enterprise_version', '0') !== '1' || !class_exists(DistributionWrapper::class)) {
			$output->writeln('<error>Distribution is an Enterprise feature and is not enabled/installed on this instance.</error>');
			return Command::FAILURE;
		}

		$forretningsValideringsKode = (string)$input->getArgument('forretningsValideringsKode');
		$kvitteringstype = (string)$input->getArgument('kvitteringstype');
		$anvenderTransaktionsID = (string)$input->getArgument('anvenderTransaktionsID');
		$afsendendeMyndighed = (string)$input->getArgument('afsendendeMyndighed');
		$routingMyndighed = (string)$input->getArgument('routingMyndighed');
		$routingModtagerAktoer = (string)$input->getArgument('routingModtagerAktoer');

		try {
			$certificate = new Certificate(CertificateType::FKDistribution, $this->certificateRepository);
			$entityId = $this->configuration->getConfigValue('entity_id_fordeling', 'http://sp.serviceplatformen.dk/service/fordeling/3');
			$samlToken = TokenIssuerREST::issueToken(
				$entityId,
				$certificate,
				$this->configuration,
				$this->traceLogger,
			);
			$output->writeln('<info>Token issued successfully:</info>');
			$output->writeln(json_encode($samlToken->getMetadata(), JSON_PRETTY_PRINT));

			$endpoint = $this->configuration->getConfigValue('endpoint_fordeling', 'https://exttest.serviceplatformen.dk/service/SP/Distribution/3');
			$output->writeln('<info>Endpoint:</info>');
			$output->writeln($endpoint);

			$distributionConfiguration = new DistributionConfiguration();
			$distributionConfiguration->setEndpoint($endpoint);
			$distributionConfiguration->setClientCertificate($certificate);

			$distributionWrapper = new DistributionWrapper($distributionConfiguration, $samlToken, $this->traceLogger);
			$distributionWrapper->sendReceipt(
				$forretningsValideringsKode,
				$kvitteringstype,
				$anvenderTransaktionsID,
				$afsendendeMyndighed,
				$routingMyndighed,
				$routingModtagerAktoer,
			);

			$output->writeln('<info>Distribution receipt sent successfully.</info>');

			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$output->writeln('<error>Sending distribution receipt failed:</error>');
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return Command::FAILURE;
		}
	}
}
