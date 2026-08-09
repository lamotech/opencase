<?php
declare(strict_types=1);

namespace OCA\OpenCase\Command;

use OCA\OpenCase\BackgroundJob\DispatchDigitalPostJob;
use OCA\OpenCase\BackgroundJob\ProcessDigitalPostReceivementsJob;
use OCA\OpenCase\BackgroundJob\ProcessDistributionReceivementsJob;
use OCA\OpenCase\BackgroundJob\ProcessMessageReceivementsJob;
use OCA\OpenCase\BackgroundJob\SyncClassificationsJob;
use OCA\OpenCase\Controller\AiController;
use OCA\OpenCase\Controller\AiPromptController;
use OCA\OpenCase\Controller\PublicApi\PublicDigitalPostReceiverController;
use OCA\OpenCase\Controller\PublicApi\PublicDistributionReceiverController;
use OCA\OpenCase\Controller\PublicApi\PublicMessageReceiverController;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\AiActionService;
use OCA\OpenCase\Service\ClassificationSyncService;
use OCA\OpenCase\Service\Datafordeler\CitizenClient;
use OCA\OpenCase\Service\Datafordeler\CompanyClient;
use OCA\OpenCase\Service\Serviceplatformen\ClassificationClient;
use OCA\OpenCase\Service\Serviceplatformen\ClassificationWrapper;
use OCA\OpenCase\Service\Serviceplatformen\DigitalPostSendClient;
use OCA\OpenCase\Service\Serviceplatformen\DistributionWrapper;
use OCA\OpenCase\Service\Serviceplatformen\PostForespoergClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Flips enterprise_version to '1', but only once the Enterprise components
 * (Distribution: PublicDistributionReceiverController,
 * ProcessDistributionReceivementsJob, DistributionWrapper; Messages:
 * PublicMessageReceiverController, ProcessMessageReceivementsJob; Digital
 * Post: PublicDigitalPostReceiverController, DispatchDigitalPostJob,
 * ProcessDigitalPostReceivementsJob, PostForespoergClient,
 * DigitalPostSendClient; Datafordeler CPR: CitizenClient; Datafordeler CVR:
 * CompanyClient; AI: AiController, AiActionService, AiPromptController;
 * Classification sync: SyncClassificationsJob, ClassificationSyncService,
 * ClassificationClient, ClassificationWrapper) are actually present on
 * disk — these classes only exist after the Enterprise install script has
 * copied them into apps/opencase/lib/. Referencing them here via `use` and
 * class_exists() is safe even when the files are absent: a `use` import
 * never triggers autoloading by itself.
 */
class EnterpriseEnableCommand extends Command {

	private const REQUIRED_CLASSES = [
		PublicDistributionReceiverController::class,
		ProcessDistributionReceivementsJob::class,
		DistributionWrapper::class,
		PublicMessageReceiverController::class,
		ProcessMessageReceivementsJob::class,
		PublicDigitalPostReceiverController::class,
		DispatchDigitalPostJob::class,
		ProcessDigitalPostReceivementsJob::class,
		PostForespoergClient::class,
		DigitalPostSendClient::class,
		CitizenClient::class,
		CompanyClient::class,
		AiController::class,
		AiActionService::class,
		AiPromptController::class,
		SyncClassificationsJob::class,
		ClassificationSyncService::class,
		ClassificationClient::class,
		ClassificationWrapper::class,
	];

	public function __construct(
		private Configuration $configuration,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('opencase:enterprise:enable')
			->setDescription('Enable enterprise_version once the Enterprise Distribution components are installed');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$missing = array_filter(self::REQUIRED_CLASSES, fn (string $class) => !class_exists($class));

		if (!empty($missing)) {
			$output->writeln('<error>Cannot enable enterprise_version — the following Enterprise component(s) are missing:</error>');
			foreach ($missing as $class) {
				$output->writeln("  - {$class}");
			}
			$output->writeln('Run the Enterprise install script first, then retry this command.');
			return Command::FAILURE;
		}

		try {
			$this->configuration->setConfigValue('enterprise_version', '1');
		} catch (\Throwable $e) {
			$output->writeln('<error>Failed to set enterprise_version:</error>');
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		$output->writeln('<info>enterprise_version set to 1. Distribution, Message, Digital Post, Datafordeler CPR, Datafordeler CVR, AI, and Classification sync components are now active.</info>');
		return Command::SUCCESS;
	}
}
