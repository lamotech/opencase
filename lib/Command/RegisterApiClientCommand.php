<?php

declare(strict_types=1);

namespace OCA\OpenCase\Command;

use OCA\OpenCase\Db\ApiClientRepository;
use OCA\OpenCase\Service\Certificate;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * Registers a client certificate in the opencase_api_client table so the
 * owning system can authenticate against the public mTLS API.
 *
 * Usage:
 *   occ opencase:register-api-client <name> <path-to-cert.cer>
 *
 * The .cer file may be PEM or DER encoded. The SHA-256 fingerprint is
 * extracted automatically and stored in the registry.
 *
 * IMPORTANT — nginx must forward the fingerprint via the header mapped from
 * $ssl_client_fingerprint:
 *
 *   proxy_set_header X-SSL-Client-Fingerprint $ssl_client_fingerprint;
 */
class RegisterApiClientCommand extends Command {

    private const VALID_FOR_CHOICES = ['API', 'Digital Post', 'Adgangsstyring', 'Serviceplatformen callback'];

    public function __construct(
        private ApiClientRepository $apiClientRepository,
        private IUserManager $userManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('opencase:register-api-client')
            ->setDescription('Register a client certificate for the mTLS public API')
            ->addArgument('name', InputArgument::OPTIONAL, 'Human-readable name for the API client (e.g. "Fagsystem ESDH")')
            ->addArgument('certificate', InputArgument::OPTIONAL, 'Path to the client certificate file (.cer, PEM or DER)')
            ->addOption('expires', null, InputOption::VALUE_REQUIRED, 'Optional expiry date (Y-m-d), stored in the registry as a belt-and-suspenders check')
            ->addOption('valid-for', null, InputOption::VALUE_REQUIRED, 'What the client is registered for: ' . implode(', ', self::VALID_FOR_CHOICES))
            ->addOption('mapped-to-user', null, InputOption::VALUE_REQUIRED, 'Nextcloud user id the client acts as; mandatory when --valid-for=API')
            ->addOption('deactivate', null, InputOption::VALUE_NONE, 'Register the client in inactive state (can be activated later)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');

        $name = $input->getArgument('name');
        if ($name === null) {
            $nameQuestion = new Question('Enter a human-readable name for the API client: ');
            $name = $helper->ask($input, $output, $nameQuestion);
        }

        if (empty($name)) {
            $output->writeln('<error>Name cannot be empty</error>');
            return Command::FAILURE;
        }
        $name = (string)$name;

        $path = $input->getArgument('certificate');
        if ($path === null) {
            $pathQuestion = new Question('Enter the path to the client certificate file (.cer): ');
            $path = $helper->ask($input, $output, $pathQuestion);
        }

        if (empty($path)) {
            $output->writeln('<error>Certificate path cannot be empty</error>');
            return Command::FAILURE;
        }
        $path = (string)$path;

        $expires = $input->getOption('expires');
        if ($expires === null) {
            $expiresQuestion = new Question('Enter an optional expiry date (Y-m-d), or leave empty: ');
            $expires = $helper->ask($input, $output, $expiresQuestion);
        }
        if (empty($expires)) {
            $expires = null;
        }

        $validFor = $input->getOption('valid-for');
        if ($validFor === null) {
            $validForQuestion = new ChoiceQuestion('Select what the client is registered for:', self::VALID_FOR_CHOICES);
            $validFor = $helper->ask($input, $output, $validForQuestion);
        }
        if (!in_array($validFor, self::VALID_FOR_CHOICES, true)) {
            $output->writeln('<error>--valid-for must be one of: ' . implode(', ', self::VALID_FOR_CHOICES) . '</error>');
            return Command::FAILURE;
        }
        $validFor = (string)$validFor;

        $mappedToUser = $input->getOption('mapped-to-user');
        if ($mappedToUser === null && $validFor === 'API') {
            $mappedToUserQuestion = new Question('Enter the Nextcloud user id this client acts as (mandatory for valid_for=API): ');
            $mappedToUser = $helper->ask($input, $output, $mappedToUserQuestion);
        }
        if (empty($mappedToUser)) {
            $mappedToUser = null;
        } else {
            $mappedToUser = (string)$mappedToUser;
        }

        if ($validFor === 'API' && empty($mappedToUser)) {
            $output->writeln('<error>--mapped-to-user is mandatory when --valid-for=API</error>');
            return Command::FAILURE;
        }

        if ($mappedToUser !== null && !$this->userManager->userExists($mappedToUser)) {
            $output->writeln('<error>No Nextcloud user found with id "' . $mappedToUser . '"</error>');
            return Command::FAILURE;
        }

        if (!file_exists($path)) {
            $output->writeln('<error>Certificate file not found: ' . $path . '</error>');
            return Command::FAILURE;
        }

        $certContent = file_get_contents($path);
        if ($certContent === false) {
            $output->writeln('<error>Failed to read certificate file</error>');
            return Command::FAILURE;
        }

        // Support PEM, raw DER, and "bare" base64 (a PEM body exported
        // without its -----BEGIN/END CERTIFICATE----- armor).
        $cert = openssl_x509_read($certContent);
        if ($cert === false) {
            $stripped = preg_replace('/\s+/', '', $certContent);
            if (!empty($stripped) && preg_match('/^[A-Za-z0-9+\/]+=*$/', $stripped)) {
                // Looks like base64 text missing its PEM header/footer
                $cert = openssl_x509_read(Certificate::formatAsPem($stripped, 'CERTIFICATE'));
            }
        }

        if ($cert === false) {
            // Try treating it as raw binary DER and wrapping in PEM headers
            $pem = "-----BEGIN CERTIFICATE-----\n"
                . chunk_split(base64_encode($certContent), 64, "\n")
                . "-----END CERTIFICATE-----\n";
            $cert = openssl_x509_read($pem);
        }

        if ($cert === false) {
            $output->writeln('<error>Failed to parse certificate: ' . openssl_error_string() . '</error>');
            $output->writeln('<comment>The file must be a valid X.509 certificate in PEM or DER format.</comment>');
            return Command::FAILURE;
        }

        $certInfo = openssl_x509_parse($cert);
        if ($certInfo === false) {
            $output->writeln('<error>Failed to read certificate details: ' . openssl_error_string() . '</error>');
            return Command::FAILURE;
        }

        // SHA-1 fingerprint — matches nginx $ssl_client_fingerprint
        $fingerprint = openssl_x509_fingerprint($cert, 'sha1');
        if ($fingerprint === false) {
            $output->writeln('<error>Failed to compute certificate fingerprint</error>');
            return Command::FAILURE;
        }
        // openssl_x509_fingerprint returns lowercase hex; strip colons just in case
        $fingerprint = strtolower(str_replace(':', '', $fingerprint));

        // Canonical PEM body — stored so the public key can be used as a
        // trust anchor to verify signed tokens (e.g. valid_for=Adgangsstyring)
        if (!openssl_x509_export($cert, $certificatePem)) {
            $output->writeln('<error>Failed to export certificate as PEM</error>');
            return Command::FAILURE;
        }

        $subjectDn = $certInfo['name'] ?? null;

        $expiresAt = null;
        if ($expires !== null) {
            $expiresAt = \DateTimeImmutable::createFromFormat('Y-m-d', $expires);
            if ($expiresAt === false) {
                $output->writeln('<error>Invalid --expires date format, expected Y-m-d (e.g. 2027-01-01)</error>');
                return Command::FAILURE;
            }
        }

        $active = !$input->getOption('deactivate');

        // Check for an existing entry with the same fingerprint
        $existing = $this->apiClientRepository->findActiveByFingerprint($fingerprint);
        if ($existing !== null) {
            $output->writeln('<comment>A client with this fingerprint is already registered (id=' . $existing['id'] . ', name="' . $existing['name'] . '").</comment>');
            $output->writeln('<comment>No changes made.</comment>');
            return Command::SUCCESS;
        }

        $this->apiClientRepository->insert($name, $fingerprint, $subjectDn, $expiresAt, $validFor, $mappedToUser, $certificatePem);

        $notAfter  = (new \DateTimeImmutable())->setTimestamp($certInfo['validTo_time_t']);
        $notBefore = (new \DateTimeImmutable())->setTimestamp($certInfo['validFrom_time_t']);

        $output->writeln('');
        $output->writeln('<info>API client registered successfully.</info>');
        $output->writeln('');
        $output->writeln('  Name:        ' . $name);
        $output->writeln('  Subject:     ' . ($subjectDn ?? 'n/a'));
        $output->writeln('  Fingerprint: ' . $fingerprint);
        $output->writeln('  Valid for:   ' . $validFor);
        $output->writeln('  Mapped user: ' . ($mappedToUser ?? 'n/a'));
        $output->writeln('  Valid from:  ' . $notBefore->format('Y-m-d'));
        $output->writeln('  Valid to:    ' . $notAfter->format('Y-m-d'));
        $output->writeln('  Active:      ' . ($active ? 'yes' : 'no (use --deactivate to enable later)'));
        $output->writeln('');
        $output->writeln('<comment>Make sure nginx forwards the fingerprint header:</comment>');
        $output->writeln('  proxy_set_header X-SSL-Client-Fingerprint $ssl_client_fingerprint;');
        $output->writeln('');

        return Command::SUCCESS;
    }
}
