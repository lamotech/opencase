<?php

declare(strict_types=1);

namespace OCA\OpenCase\Listener;

use OCA\OpenCase\BackgroundJob\BulkReindexJob;
use OCA\OpenCase\Event\CaseAccessProfileChangedEvent;
use OCA\OpenCase\Service\ElasticsearchService;
use OCA\OpenCase\Service\FileService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Brings what is derived from a case's access profile up to date when it
 * changes: the access_profile_id of its files in the search index, so the new
 * group finds them, and the permission ceilings in the filecache.
 *
 * Changing the access succeeds also when Elasticsearch can't be reached: the
 * case's files are then queued for reindexing. Until that has run,
 * PermissionService::filterReadableHits() keeps them from the old group.
 *
 * The listener is registered in Application.php.
 *
 * @implements IEventListener<CaseAccessProfileChangedEvent>
 */
class CaseAccessProfileListener implements IEventListener {

    public function __construct(
        private ElasticsearchService $esService,
        private FileService $fileService,
        private IJobList $jobList,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof CaseAccessProfileChangedEvent)) {
            return;
        }
        $this->fileService->updateCeilingPermissionsForProfile($event->getAccessProfileId(), $event->getCaseId());
        try {
            $this->esService->updateAccessProfile($event->getCaseId(), $event->getAccessProfileId());
        } catch (\Throwable $e) {
            $this->logger->warning('Could not update the access profile of case ' . $event->getCaseId() . ' in the search index, reindexing it: ' . $e->getMessage());
            $this->jobList->add(BulkReindexJob::class, ['case_id' => $event->getCaseId()]);
        }
    }
}
