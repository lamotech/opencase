<?php

declare(strict_types=1);

namespace OCA\OpenCase\Command;

use OCA\OpenCase\Service\CaseExportService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ExportClosedCasesCommand extends Command {

    public function __construct(
        private CaseExportService $caseExportService,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('opencase:export-closed-cases')
            ->setDescription('Export closed cases (not yet exported) to the configured export_folder')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Max number of cases to export in this run', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $batchSize = (int)$input->getOption('batch-size');

        $output->writeln('<info>Exporting closed cases…</info>');
        $result = $this->caseExportService->exportPendingCases($batchSize);
        $output->writeln(sprintf('<info>Exported: %d, failed: %d</info>', $result['exported'], $result['failed']));

        return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
