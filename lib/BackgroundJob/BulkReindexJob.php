<?php

declare(strict_types=1);

namespace OCA\OpenCase\BackgroundJob;

use OCA\OpenCase\Db\FileMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Bulk re-indexing job for Elasticsearch.
 *
 * Queued by AdminController::reindexAll() and reindexCase().
 *
 * Two modes:
 *   - Full re-index: iterates all files in batches, queuing one IndexFileJob
 *     per file. Self-chains to process the next batch until all files are done.
 *   - Case re-index: queues IndexFileJob for every file in the given case.
 */
class BulkReindexJob extends QueuedJob {

    public function __construct(
        ITimeFactory $time,
        private FileMapper $fileMapper,
        private IJobList $jobList,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
    }

    protected function run(mixed $argument): void {
        if (!empty($argument['case_id'])) {
            $this->reindexCase((int)$argument['case_id']);
            return;
        }

        if (!empty($argument['full'])) {
            $offset    = (int)($argument['offset'] ?? 0);
            $batchSize = (int)($argument['batch_size'] ?? 500);
            $this->reindexBatch($offset, $batchSize);
        }
    }

    private function reindexCase(int $caseId): void {
        $fileIds = $this->fileMapper->getFileIdsByCase($caseId);
        foreach ($fileIds as $fileId) {
            $this->jobList->add(IndexFileJob::class, ['fileId' => $fileId]);
        }
        $this->logger->info('OpenCase BulkReindexJob: queued {count} files for case {case}', [
            'count' => count($fileIds),
            'case'  => $caseId,
        ]);
    }

    private function reindexBatch(int $offset, int $batchSize): void {
        $fileIds = $this->fileMapper->getAllFileIds($batchSize, $offset);
        foreach ($fileIds as $fileId) {
            $this->jobList->add(IndexFileJob::class, ['fileId' => $fileId]);
        }
        $this->logger->info('OpenCase BulkReindexJob: queued {count} files (offset {offset})', [
            'count'  => count($fileIds),
            'offset' => $offset,
        ]);

        // Self-chain if there may be more files
        if (count($fileIds) === $batchSize) {
            $this->jobList->add(self::class, [
                'full'       => true,
                'offset'     => $offset + $batchSize,
                'batch_size' => $batchSize,
            ]);
        }
    }
}
