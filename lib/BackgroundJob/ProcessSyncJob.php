<?php

declare(strict_types=1);

namespace OCA\OpenCase\BackgroundJob;

use OCA\OpenCase\Service\Process\CaseProcessService;
use OCA\OpenCase\Service\Process\FlowableClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Keeps OpenCase's copy of running processes in step with the process
 * engine. Actions taken in OpenCase sync immediately; this catches what the
 * engine does by itself, such as timers creating or ending tasks.
 *
 * Runs every 5 minutes and syncs the processes that have waited longest,
 * up to BATCH_SIZE per run.
 */
class ProcessSyncJob extends TimedJob {

    private const BATCH_SIZE = 200;

    public function __construct(
        ITimeFactory $time,
        private CaseProcessService $processService,
        private FlowableClient $flowable,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(300);
    }

    protected function run(mixed $argument): void {
        if (!$this->flowable->isConfigured()) {
            return;
        }
        try {
            $synced = $this->processService->syncStale(new \DateTime('-4 minutes'), self::BATCH_SIZE);
            if ($synced > 0) {
                $this->logger->debug("ProcessSyncJob: synced {$synced} process(es)");
            }
        } catch (\Throwable $e) {
            $this->logger->warning('ProcessSyncJob failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
