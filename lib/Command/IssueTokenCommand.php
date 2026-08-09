<?php
declare(strict_types=1);

namespace OCA\OpenCase\Command;

use OCA\OpenCase\Service\Serviceplatformen\TokenIssuerREST;
use OCA\OpenCase\Db\CertificateRepository;
use OCA\OpenCase\Service\Certificate;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\TraceLogger;
use OCA\OpenCase\Enum\CertificateType;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IssueTokenCommand extends Command {
	public function __construct(
		private CertificateRepository $certificateRepository,
		private Configuration $configuration,
		private TraceLogger $traceLogger,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('opencase:issue-token')
			->setDescription('Issue a SAML2 token from STS using WS-Trust 1.3')
            ->addArgument('entity', \Symfony\Component\Console\Input\InputArgument::OPTIONAL, 'The entity for which to issue the token (e.g. "organisation", "classification", "postforespoerg" or "kombipostafsend")')
            ->addOption('cvr', null, \Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL, 'Override the CVR number used for token issuance');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$entity = (string)$input->getArgument('entity');
		$cvrOverride = $input->getOption('cvr') ? (string)$input->getOption('cvr') : null;
		$output->writeln('<info>Issuing SAML token…</info>');

		try {
			$certificate = new Certificate(CertificateType::FKOrganisation, $this->certificateRepository);

			switch ($entity) {
				case 'organisation':
					$entityId = $this->configuration->getConfigValue('entity_id_organisation', 'http://stoettesystemerne.dk/service/organisation/3');
					break;
				case 'classification':
					$entityId = $this->configuration->getConfigValue('entity_id_classification', 'http://entityid.kombit.dk/service/klassifikation/7');
					break;
				case 'postforespoerg':
					$entityId = $this->configuration->getConfigValue('entity_id_postforespoerg', 'http://entityid.kombit.dk/service/postforespoerg/1');
					break;
				case 'kombipostafsend':
					$entityId = $this->configuration->getConfigValue('entity_id_kombipostafsend', 'http://entityid.kombit.dk/service/kombipostafsend/1');
					break;
				case 'opencase':
					$entityId = $this->configuration->getConfigValue('entity_id_opencase', 'http://opencase.dk/service/api/1');
					break;
				default:
					$entityId = $this->configuration->getConfigValue('entity_id_organisation', 'http://stoettesystemerne.dk/service/organisation/3');
			}

			$samlToken = TokenIssuerREST::issueToken(
				$entityId,
				$certificate,
				$this->configuration,
				$this->traceLogger,
				$cvrOverride,
			);

			$output->writeln('<info>Token issued successfully:</info>');
			$output->writeln(json_encode($samlToken->getMetadata(), JSON_PRETTY_PRINT));

			$output->writeln('<info>Token assertion:</info>');
			$output->writeln($samlToken->getAssertion());

			// Get access token
			$accessToken = (new \OCA\OpenCase\Service\Serviceplatformen\AccessTokenClient($this->configuration, $this->traceLogger))
				->exchangeToAccessToken($samlToken, $certificate);
			$output->writeln('<info>Access Token:</info>');
			$output->writeln($accessToken->getValue());

			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$output->writeln('<error>Token issuance failed:</error>');
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return Command::FAILURE;
		}
	}
}

