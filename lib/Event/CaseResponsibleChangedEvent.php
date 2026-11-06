<?php

declare(strict_types=1);

namespace OCA\OpenCase\Event;

use OCP\EventDispatcher\Event;

/**
 * Dispatched after a case got a new responsible caseworker (CaseService::update).
 *
 * The process module listens for it to keep the process variable
 * caseResponsible of running processes up to date.
 */
class CaseResponsibleChangedEvent extends Event {

    public function __construct(
        private int $caseId,
        private ?string $fromUserId,
        private string $toUserId,
    ) {
        parent::__construct();
    }

    public function getCaseId(): int {
        return $this->caseId;
    }

    public function getFromUserId(): ?string {
        return $this->fromUserId;
    }

    public function getToUserId(): string {
        return $this->toUserId;
    }
}
