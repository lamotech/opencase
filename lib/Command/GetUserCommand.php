<?php
declare(strict_types=1);

namespace OCA\OpenCase\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use OCA\OpenCase\Service\Serviceplatformen\UserWrapper;
use OCA\OpenCase\Service\Serviceplatformen\AddressWrapper;
use OCA\OpenCase\Service\Serviceplatformen\OrganisationFunctionWrapper;
use OCA\OpenCase\Service\Serviceplatformen\PersonWrapper;
use OCA\OpenCase\Service\Serviceplatformen\OrganisationConfiguration;
use OCA\OpenCase\Service\Certificate;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Enum\CertificateType;
use OCA\OpenCase\Service\Serviceplatformen\TokenIssuerREST;
use OCA\OpenCase\Service\Serviceplatformen\SAMLToken;
use OCA\OpenCase\Db\CertificateRepository;
use OCA\OpenCase\Service\TraceLogger;
use OCA\OpenCase\Service\Serviceplatformen\UserClient;

class GetUserCommand extends Command {
	public function __construct(
        private Configuration $configuration,
        private CertificateRepository $certificateRepository,
        private TraceLogger $traceLogger,
        private UserClient $userClient,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('opencase:get-user')
			->setDescription('Fetch user information from the user service')
			->addArgument('uuid', InputArgument::REQUIRED, 'User UUID identifier');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output->writeln('<info>Fetching user information…</info>');

		try {
            $UserUUIDIdentifikator = $input->getArgument('uuid');
            $user = $this->userClient->fetchUser($UserUUIDIdentifikator);
            $output->writeln('<info>User Information:</info>');
            $output->writeln('UUID:     ' . $user->uuid);
            $output->writeln('Username: ' . $user->username);
            $output->writeln('Person:   ' . $user->person->uuid . ' (' . $user->person->name . ')');
            $output->writeln('Addresses:');
            foreach ($user->addresses as $address) {
                $output->writeln('  - UUID: ' . $address->uuid . '  Role: ' . $address->roleUuid . '  Label: ' . $address->label . '  Text: ' . $address->text);
            }
            $output->writeln('Organisations:');
            foreach ($user->organisations as $org) {
                $output->writeln('  - UUID: ' . $org->orgUuid . '  Role: ' . $org->role);
            }

			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$output->writeln('<error>Organisation function fetch failed:</error>');
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return Command::FAILURE;
		}
	}
}
