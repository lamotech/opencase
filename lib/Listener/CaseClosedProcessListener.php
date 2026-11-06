<?php

declare(strict_types=1);

namespace OCA\OpenCase\Listener;

use OCA\OpenCase\Db\CaseStatusMapper;
use OCA\OpenCase\Event\CaseStatusChangedEvent;
use OCA\OpenCase\Service\Process\CaseProcessService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Cancels the running processes of a case when it is closed.
 *
 * Best effort: if the process engine can't be reached, closing the case
 * still succeeds, and ProcessSyncJob cancels the processes later
 * (CaseProcessService::sync() cancels processes on closed cases).
 *
 * The listener is registered in Application.php.
 *
 * @implements IEventListener<CaseStatusChangedEvent>
 */
class CaseClosedProcessListener implements IEventListener {

    public function __construct(
        private CaseProcessService $processService,
        private CaseStatusMapper $caseStatusMapper,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof CaseStatusChangedEvent) || !$this->processService->isEnabled()) {
            return;
        }
        if ($this->caseStatusMapper->isClosed($event->getFromStatusId())
            || !$this->caseStatusMapper->isClosed($event->getToStatusId())) {
            return;
        }

        try {
            $this->processService->cancelRunningForClosedCase($event->getCaseId(), $event->getUserId());
        } catch (\Throwable $e) {
            $this->logger->warning('Could not cancel the processes of closed case ' . $event->getCaseId()
                . ' now; ProcessSyncJob retries: ' . $e->getMessage());
        }
    }
}
