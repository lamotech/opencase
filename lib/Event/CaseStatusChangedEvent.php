<?php

declare(strict_types=1);

namespace OCA\OpenCase\Event;

use OCP\EventDispatcher\Event;

/**
 * Dispatched after a case's status has been changed (CaseService::changeStatus).
 *
 * The process module listens for it to cancel running processes when a
 * case is closed.
 */
class CaseStatusChangedEvent extends Event {

    public function __construct(
        private int $caseId,
        private int $fromStatusId,
        private int $toStatusId,
        private string $userId,
    ) {
        parent::__construct();
    }

    public function getCaseId(): int {
        return $this->caseId;
    }

    public function getFromStatusId(): int {
        return $this->fromStatusId;
    }

    public function getToStatusId(): int {
        return $this->toStatusId;
    }

    public function getUserId(): string {
        return $this->userId;
    }
}
