<?php

declare(strict_types=1);

namespace OCA\OpenCase\BackgroundJob;

use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\FileMapper;
use OCA\OpenCase\Service\ElasticsearchService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Asynchronous background job that indexes a single OpenCase file in Elasticsearch.
 *
 * Queued by FileUpdatedListener whenever a file is created or modified.
 * IJobList deduplicates by (class, argument), so rapid saves produce only one job.
 *
 * Strategy:
 *   - Files up to es_max_file_size: base64-encode content and index via the
 *     ingest-attachment pipeline (Tika extracts text from Word/PDF/etc.)
 *   - Files exceeding the limit: index metadata only (no content extraction)
 *
 * The virtual_path field stored in the ES document is the path relative to the
 * opencase storage root (e.g. "2026/03/08/2026-005/London.docx").  The search
 * provider uses this to look up the NC filecache ID so clicking a result opens
 * the file directly in the Files app.
 */
class IndexFileJob extends QueuedJob {

    private int $maxFileSizeBytes;
    private string $baseDir;

    public function __construct(
        ITimeFactory $time,
        private FileMapper $fileMapper,
        private CaseMapper $caseMapper,
        private ElasticsearchService $esService,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->maxFileSizeBytes = (int)$config->getAppValue(
            'opencase', 'es_max_file_size', '52428800' // 50 MB
        );
        $defaultDir    = rtrim($config->getSystemValueString('datadirectory', ''), '/') . '/opencase_storage';
        $this->baseDir = $config->getAppValue('opencase', 'storage_base_dir', $defaultDir);
    }

    protected function run(mixed $argument): void {
        $fileId = (int)($argument['fileId'] ?? 0);
        if ($fileId <= 0) {
            $this->logger->warning('OpenCase IndexFileJob: missing or invalid fileId in argument');
            return;
        }

        $meta = $this->fileMapper->findWithMetadataForIndexing($fileId);
        if ($meta === null) {
            $this->logger->warning('OpenCase IndexFileJob: file {id} not found — may have been deleted', [
                'id' => $fileId,
            ]);
            return;
        }

        $case = $this->caseMapper->findById((int)$meta['case_id']);
        if ($case === null) {
            $this->logger->warning('OpenCase IndexFileJob: case not found for file {id}', ['id' => $fileId]);
            return;
        }

        $year  = $case->getCreatedYear();
        $month = $case->getCreatedMonth();
        $day   = $case->getCreatedDay();
        if ($year === '') {
            $this->logger->warning('OpenCase IndexFileJob: case has no created_at for file {id}', ['id' => $fileId]);
            return;
        }

        // Physical path: {baseDir}/{year}/{month}/{case_id}/{document_id}/{storage_filename}
        // Legacy fallback: older files were stored with case_number instead of case_id.
        $physicalPath = sprintf(
            '%s/%s/%s/%d/%d/%s',
            $this->baseDir, $year, $month,
            (int)$meta['case_id'],
            (int)$meta['document_id'],
            $meta['storage_filename'],
        );

        if (!file_exists($physicalPath)) {
            // Try legacy path with case_number in place of case_id
            $legacyPath = sprintf(
                '%s/%s/%s/%s/%d/%s',
                $this->baseDir, $year, $month,
                $meta['case_number'],
                (int)$meta['document_id'],
                $meta['storage_filename'],
            );
            if (file_exists($legacyPath)) {
                $physicalPath = $legacyPath;
            } else {
                $this->logger->warning('OpenCase IndexFileJob: physical file missing for file {id}: {path}', [
                    'id'   => $fileId,
                    'path' => $physicalPath,
                ]);
                return;
            }
        }

        // Virtual path relative to the opencase storage root (used by the
        // global search provider to look up the NC filecache ID).
        $virtualPath = "{$year}/{$month}/{$day}/{$meta['case_number']}/{$meta['virtual_filename']}";

        $esDocument = [
            'file_id'          => $fileId,
            'document_id'      => (int)$meta['document_id'],
            'case_id'          => (int)$meta['case_id'],
            'case_number'      => $meta['case_number'],
            'case_title'       => $meta['case_title'],
            'document_title'   => $meta['document_title'],
            'document_type'    => $meta['document_type'],
            'filename'         => $meta['virtual_filename'],
            'original_filename'=> $meta['original_filename'],
            'mime_type'        => $meta['mime_type'],
            'size'             => (int)filesize($physicalPath),
            'organisation'     => $meta['organisation'],
            'year'             => $meta['year'],
            'casetype_id'      => (int)$meta['casetype_id'],
            'access_profile_id'=> (int)$meta['access_profile_id'],
            'created_at'       => $this->toIso8601($meta['file_created_at']),
            'updated_at'       => $this->toIso8601($meta['file_updated_at']),
            'virtual_path'     => $virtualPath,
        ];

        try {
            $fileSize = filesize($physicalPath);
            if ($fileSize > 0 && $fileSize <= $this->maxFileSizeBytes) {
                $content = file_get_contents($physicalPath);
                if ($content === false) {
                    throw new \RuntimeException("Could not read file: {$physicalPath}");
                }
                $esDocument['data'] = base64_encode($content);
                $this->esService->indexDocument($fileId, $esDocument);
            } else {
                // Too large or empty — store metadata without content extraction
                unset($esDocument['data']);
                $this->esService->storeDocument($fileId, $esDocument);
            }

            $this->logger->debug('OpenCase IndexFileJob: indexed file {id} ({filename})', [
                'id'       => $fileId,
                'filename' => $meta['virtual_filename'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('OpenCase IndexFileJob: failed to index file {id}: {error}', [
                'id'    => $fileId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function toIso8601(?string $datetime): ?string {
        if ($datetime === null || $datetime === '') {
            return null;
        }
        return (new \DateTime($datetime))->format(\DateTime::ATOM);
    }
}
