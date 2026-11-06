<?php

declare(strict_types=1);

namespace OCA\OpenCase\BackgroundJob;

use OCA\OpenCase\Service\Process\ProcessMigrationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Moves the running processes of a process to its published version, for
 * migrations too large to run during the request that started them.
 * Queued by ProcessMigrationService::start() with ['migration_id' => id].
 */
class ProcessMigrationJob extends QueuedJob {

    public function __construct(
        ITimeFactory $time,
        private ProcessMigrationService $migrationService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
    }

    protected function run(mixed $argument): void {
        $migrationId = (int)($argument['migration_id'] ?? 0);
        if ($migrationId <= 0) {
            return;
        }
        try {
            $this->migrationService->run($migrationId);
        } catch (\Throwable $e) {
            $this->logger->error("ProcessMigrationJob {$migrationId} failed: " . $e->getMessage(), ['exception' => $e]);
        }
    }
}
