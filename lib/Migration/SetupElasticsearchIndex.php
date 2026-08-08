<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use OCA\OpenCase\Service\ElasticsearchService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Creates the Elasticsearch index and ingest pipeline used for file search.
 *
 * Registered as both an install and post-migration repair step in
 * appinfo/info.xml, so an administrator installing OpenCase from the App Store
 * gets a working search index without having to run occ opencase:reindex.
 *
 * Idempotent — setupIndex() skips the index if it already exists and the
 * pipeline PUT is a plain upsert.
 *
 * Deliberately non-fatal: on a fresh install Elasticsearch is frequently not
 * reachable yet (es_host still points at the default localhost:9200, or the
 * cluster is provisioned after the app). Aborting the installation over that
 * would be worse than starting without an index, so a failure here is reported
 * and swallowed. Two other paths close the gap afterwards:
 *   - ElasticsearchService::withIndexSetup() retries setup on the first write
 *   - occ opencase:reindex --setup, and the admin settings page
 */
class SetupElasticsearchIndex implements IRepairStep {

    public function __construct(
        private ElasticsearchService $esService,
        private LoggerInterface $logger,
    ) {
    }

    public function getName(): string {
        return 'Set up OpenCase Elasticsearch index and ingest pipeline';
    }

    public function run(IOutput $output): void {
        if (!$this->esService->isAvailable()) {
            $output->warning(
                'Elasticsearch is not reachable — skipping index setup. '
                . 'Configure it under Administration settings > OpenCase, then run: '
                . 'occ opencase:reindex --setup'
            );
            return;
        }

        try {
            $this->esService->setupIndex();
            $output->info('Elasticsearch index and ingest pipeline are ready');
        } catch (\Throwable $e) {
            $this->logger->error('OpenCase: Elasticsearch index setup failed during repair step: {error}', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
            $output->warning(
                'Elasticsearch index setup failed: ' . $e->getMessage()
                . ' — file search will be unavailable until you run: occ opencase:reindex --setup'
            );
        }
    }
}
