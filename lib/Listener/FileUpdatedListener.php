<?php

declare(strict_types=1);

namespace OCA\OpenCase\Listener;

use OCA\OpenCase\BackgroundJob\IndexFileJob;
use OCA\OpenCase\Event\FileUpdatedEvent;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Listens for FileUpdatedEvent and queues an Elasticsearch index job.
 *
 * The actual indexing is done asynchronously via IndexFileJob to avoid
 * blocking the file save operation (which may be triggered by Collabora
 * or ONLYOFFICE via WOPI).
 *
 * The listener is registered in Application.php.
 *
 * @implements IEventListener<FileUpdatedEvent>
 */
class FileUpdatedListener implements IEventListener {

    public function __construct(
        private IJobList $jobList,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof FileUpdatedEvent)) {
            return;
        }

        $fileId = $event->getFileId();

        $this->logger->debug('OpenCase: Queuing ES index job for file {fileId}', [
            'fileId' => $fileId,
        ]);

        // Queue the indexing job. IJobList deduplicates by (class, argument),
        // so if the file is saved multiple times rapidly (e.g. Collabora
        // autosave), only one job will be queued.
        $this->jobList->add(IndexFileJob::class, [
            'fileId' => $fileId,
            'timestamp' => time(),
        ]);
    }
}
