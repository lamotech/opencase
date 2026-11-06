<?php

declare(strict_types=1);

namespace OCA\OpenCase\Event;

use OCP\EventDispatcher\Event;

/**
 * Dispatched after a case got another access profile (CaseService::update),
 * e.g. when it is moved to another access group.
 */
class CaseAccessProfileChangedEvent extends Event {

    public function __construct(
        private int $caseId,
        private int $accessProfileId,
    ) {
        parent::__construct();
    }

    public function getCaseId(): int {
        return $this->caseId;
    }

    public function getAccessProfileId(): int {
        return $this->accessProfileId;
    }
}
