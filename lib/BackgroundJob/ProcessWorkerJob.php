<?php

declare(strict_types=1);

namespace OCA\OpenCase\BackgroundJob;

use OCA\OpenCase\Service\Process\CaseProcessService;
use OCA\OpenCase\Service\Process\FlowableClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Runs the automatic steps (BPMN service tasks) of processes on cases that
 * weren't run right away: steps reached by a timer, steps being retried by
 * the engine, and steps reached while the engine couldn't be reached.
 *
 * Runs on every cron run (at most once a minute).
 */
class ProcessWorkerJob extends TimedJob {

    private const STEPS_PER_TOPIC = 50;

    public function __construct(
        ITimeFactory $time,
        private CaseProcessService $processService,
        private FlowableClient $flowable,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(60);
    }

    protected function run(mixed $argument): void {
        if (!$this->flowable->isConfigured()) {
            return;
        }
        try {
            $this->processService->runAutomaticSteps(self::STEPS_PER_TOPIC);
        } catch (\Throwable $e) {
            $this->logger->warning('ProcessWorkerJob failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
