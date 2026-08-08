<?php

declare(strict_types=1);

namespace OCA\OpenCase\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Background job that repairs the Nextcloud file cache for the OpenCase
 * shared storage.
 *
 * Runs every 6 hours as a safety-net catch-all. Routine filecache updates
 * (file upload, delete, case creation, access-profile changes) are handled
 * incrementally by FileService and CaseService at event time, so this job
 * only needs to fix edge cases such as:
 *   - Entries missed during a crash or failed transaction
 *   - Stale entries after a case or file was removed outside the normal API
 *   - Permission ceiling drift after bulk DB migrations
 *
 * At 4 M cases / 10 M files the full rebuild is expensive; keeping this
 * interval long means it runs at most ~4 times per day (off-peak is fine).
 */
class SyncFilecache extends TimedJob {

    public function __construct(
        ITimeFactory $time,
        private IDBConnection $db,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(6 * 3600); // 6 hours — repair job, not primary sync
    }

    protected function run(mixed $argument): void {
        $this->logger->debug('OpenCase SyncFilecache: starting');

        try {
            $this->db->executeStatement('CALL opencase_sync_filecache()');
            $this->logger->debug('OpenCase SyncFilecache: completed');
        } catch (\Throwable $e) {
            $this->logger->error('OpenCase SyncFilecache: failed — {error}', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
