<?php

declare(strict_types=1);

namespace OCA\OpenCase\Command;

use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\FileMapper;
use OCA\OpenCase\Service\ElasticsearchService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

# Full reindex
# sudo -u www-data php /var/www/nextcloud/occ opencase:reindex
#
# Full reindex + recreate ES index and pipeline first
# sudo -u www-data php /var/www/nextcloud/occ opencase:reindex --setup
#
# Reindex a single case
# sudo -u www-data php /var/www/nextcloud/occ opencase:reindex --case=42
class ReindexCommand extends Command {

    private int $maxFileSizeBytes;
    private string $baseDir;

    public function __construct(
        private FileMapper $fileMapper,
        private CaseMapper $caseMapper,
        private ElasticsearchService $esService,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
        $this->maxFileSizeBytes = (int)$config->getAppValue(
            'opencase', 'es_max_file_size', '52428800'
        );
        $defaultDir    = rtrim($config->getSystemValueString('datadirectory', ''), '/') . '/opencase_storage';
        $this->baseDir = $config->getAppValue('opencase', 'storage_base_dir', $defaultDir);
    }

    protected function configure(): void {
        $this
            ->setName('opencase:reindex')
            ->setDescription('Full-text reindex of OpenCase files into Elasticsearch')
            ->addOption(
                'case',
                null,
                InputOption::VALUE_REQUIRED,
                'Reindex only files belonging to this case ID',
            )
            ->addOption(
                'setup',
                null,
                InputOption::VALUE_NONE,
                'Re-create the Elasticsearch index and ingest pipeline before indexing',
            )
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of files to fetch from the database at a time',
                '500',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        if ($input->getOption('setup')) {
            $output->writeln('<info>Setting up Elasticsearch index and pipeline…</info>');
            try {
                $this->esService->setupIndex();
                $output->writeln('<info>Setup complete.</info>');
            } catch (\Throwable $e) {
                $output->writeln('<error>Setup failed: ' . $e->getMessage() . '</error>');
                return self::FAILURE;
            }
        }

        $caseId    = $input->getOption('case') !== null ? (int)$input->getOption('case') : null;
        $batchSize = max(1, (int)$input->getOption('batch-size'));

        $fileIds = $caseId !== null
            ? $this->fileMapper->getFileIdsByCase($caseId)
            : $this->getAllFileIds($batchSize);

        $total = count($fileIds);
        if ($total === 0) {
            $output->writeln('<comment>No files found to index.</comment>');
            return self::SUCCESS;
        }

        $label = $caseId !== null ? "case {$caseId}" : 'all cases';
        $output->writeln("<info>Indexing {$total} file(s) for {$label}…</info>");

        $progress = new ProgressBar($output, $total);
        $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s% %memory:6s%');
        $progress->start();

        $indexed = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($fileIds as $fileId) {
            $result = $this->indexFile((int)$fileId);
            match ($result) {
                'ok'      => $indexed++,
                'skipped' => $skipped++,
                default   => $failed++,
            };
            $progress->advance();
        }

        $progress->finish();
        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Done — indexed: %d, skipped: %d, failed: %d</info>',
            $indexed, $skipped, $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function getAllFileIds(int $batchSize): array {
        $all    = [];
        $offset = 0;
        do {
            $batch = $this->fileMapper->getAllFileIds($batchSize, $offset);
            $all   = array_merge($all, $batch);
            $offset += $batchSize;
        } while (count($batch) === $batchSize);
        return $all;
    }

    private function indexFile(int $fileId): string {
        $meta = $this->fileMapper->findWithMetadataForIndexing($fileId);
        if ($meta === null) {
            $this->logger->warning('OpenCase ReindexCommand: file {id} not found', ['id' => $fileId]);
            return 'skipped';
        }

        $case = $this->caseMapper->findById((int)$meta['case_id']);
        if ($case === null) {
            $this->logger->warning('OpenCase ReindexCommand: case not found for file {id}', ['id' => $fileId]);
            return 'skipped';
        }

        $year  = $case->getCreatedYear();
        $month = $case->getCreatedMonth();
        $day   = $case->getCreatedDay();
        if ($year === '') {
            return 'skipped';
        }

        $physicalPath = sprintf(
            '%s/%s/%s/%d/%d/%s',
            $this->baseDir, $year, $month,
            (int)$meta['case_id'],
            (int)$meta['document_id'],
            $meta['storage_filename'],
        );

        if (!file_exists($physicalPath)) {
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
                $this->logger->warning('OpenCase ReindexCommand: physical file missing for {id}: {path}', [
                    'id'   => $fileId,
                    'path' => $physicalPath,
                ]);
                return 'skipped';
            }
        }

        $virtualPath = "{$year}/{$month}/{$day}/{$meta['case_number']}/{$meta['virtual_filename']}";

        $esDocument = [
            'file_id'           => $fileId,
            'document_id'       => (int)$meta['document_id'],
            'case_id'           => (int)$meta['case_id'],
            'case_number'       => $meta['case_number'],
            'case_title'        => $meta['case_title'],
            'document_title'    => $meta['document_title'],
            'document_type'     => $meta['document_type'],
            'filename'          => $meta['virtual_filename'],
            'original_filename' => $meta['original_filename'],
            'mime_type'         => $meta['mime_type'],
            'size'              => (int)filesize($physicalPath),
            'organisation'      => $meta['organisation'],
            'year'              => $meta['year'],
            'access_profile_id' => (int)$meta['access_profile_id'],
            'created_at'        => $this->toIso8601($meta['file_created_at']),
            'updated_at'        => $this->toIso8601($meta['file_updated_at']),
            'virtual_path'      => $virtualPath,
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
                unset($esDocument['data']);
                $this->esService->storeDocument($fileId, $esDocument);
            }
            return 'ok';
        } catch (\Throwable $e) {
            $this->logger->error('OpenCase ReindexCommand: failed to index file {id}: {error}', [
                'id'    => $fileId,
                'error' => $e->getMessage(),
            ]);
            return 'failed';
        }
    }

    private function toIso8601(?string $datetime): ?string {
        if ($datetime === null || $datetime === '') {
            return null;
        }
        return (new \DateTime($datetime))->format(\DateTime::ATOM);
    }
}
