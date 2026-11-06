<?php

declare(strict_types=1);

namespace OCA\OpenCase\Listener;

use OCA\OpenCase\Event\CaseResponsibleChangedEvent;
use OCA\OpenCase\Service\Process\CaseProcessService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Sets the process variable caseResponsible of the case's running
 * processes when the case gets a new responsible caseworker, so tasks
 * created afterwards for "Sagens ansvarlige sagsbehandler" go to them.
 * Open tasks keep their assignee.
 *
 * Best effort: changing the responsible succeeds also when the process
 * engine can't be reached.
 *
 * The listener is registered in Application.php.
 *
 * @implements IEventListener<CaseResponsibleChangedEvent>
 */
class CaseResponsibleProcessListener implements IEventListener {

    public function __construct(
        private CaseProcessService $processService,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof CaseResponsibleChangedEvent) || !$this->processService->isEnabled()) {
            return;
        }
        try {
            $this->processService->updateCaseResponsible($event->getCaseId(), $event->getToUserId());
        } catch (\Throwable $e) {
            $this->logger->warning('Could not update caseResponsible of the processes on case ' . $event->getCaseId() . ': ' . $e->getMessage());
        }
    }
}
