<?php

declare(strict_types=1);

namespace OCA\OpenCase\BackgroundJob;

use OCA\OpenCase\Service\CaseExportService;
use OCA\OpenCase\Service\Configuration;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Every hour, exports closed cases (case metadata, participants,
 * journal notes, caseworkers, documents/files, contacts, notes,
 * workflow history, and audit logs) to disk and stamps exported_at.
 *
 * Only runs while the export_enabled config value is '1' (Export tab,
 * AdminSettings.vue) — a no-op otherwise, mirroring SyncOrganisationsJob's
 * organisation_enable gate.
 *
 * @see CaseExportService
 */
class ExportClosedCasesJob extends TimedJob {

    public function __construct(
        ITimeFactory $time,
        private CaseExportService $caseExportService,
        private Configuration $configuration,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(60 * 60);
    }

    protected function run($argument): void {
        if ($this->configuration->getConfigValue('export_enabled', '0') !== '1') {
            $this->logger->debug('ExportClosedCasesJob skipped: export not enabled', ['app' => 'opencase']);
            return;
        }

        try {
            $result = $this->caseExportService->exportPendingCases();
            $this->logger->info('ExportClosedCasesJob finished', ['app' => 'opencase'] + $result);
        } catch (\Throwable $e) {
            $this->logger->error('ExportClosedCasesJob failed: ' . $e->getMessage(), [
                'app'       => 'opencase',
                'exception' => $e,
            ]);
        }
    }
}
