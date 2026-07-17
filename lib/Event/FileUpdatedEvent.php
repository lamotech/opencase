<?php

declare(strict_types=1);

namespace OCA\OpenCase\Event;

use OCP\EventDispatcher\Event;

/**
 * Dispatched when a file is modified through the virtual storage
 * (e.g., after Collabora/ONLYOFFICE saves a document).
 *
 * The indexing service listens for this event and queues an
 * Elasticsearch re-index job.
 */
class FileUpdatedEvent extends Event {

    public function __construct(
        private int $fileId,
    ) {
        parent::__construct();
    }

    public function getFileId(): int {
        return $this->fileId;
    }
}
