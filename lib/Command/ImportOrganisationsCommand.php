<?php

declare(strict_types=1);

namespace OCA\OpenCase\Command;

use OCA\OpenCase\Service\OrganisationCsvImportService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OCC command: opencase:import-organisations
 *
 * Imports the organisation structure from a CSV file — the same import as
 * the "Upload organisationsstruktur" button on the Adgangsprofiler tab (see
 * OrganisationCsvImportService for the file format). Organisations not in
 * the file are deactivated.
 *
 * Usage:
 *   occ opencase:import-organisations /path/to/organisations.csv --dry-run
 *   occ opencase:import-organisations /path/to/organisations.csv
 */
class ImportOrganisationsCommand extends Command {

    /** Names listed per action in a dry run without -v. */
    private const PREVIEW_LIMIT = 10;

    public function __construct(
        private OrganisationCsvImportService $importService,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('opencase:import-organisations')
            ->setDescription('Import the organisation structure from a CSV file (uuid;name;parent;group;email;phone;location). Organisations not in the file are deactivated.')
            ->addArgument('file', InputArgument::REQUIRED, 'The CSV file')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Check the file and show what would change, without changing anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $file = (string)$input->getArgument('file');
        if (!is_file($file) || !is_readable($file)) {
            $output->writeln("<error>File not found or not readable: {$file}</error>");
            return self::FAILURE;
        }
        $content = file_get_contents($file);
        if ($content === false) {
            $output->writeln("<error>Could not read {$file}</error>");
            return self::FAILURE;
        }

        try {
            if ($input->getOption('dry-run')) {
                $preview = $this->importService->preview($content);
                $output->writeln("Dry run — nothing is changed. {$preview['fetched']} organisations in the file.");
                $this->listNames($output, 'Would be created', $preview['created']);
                $this->listNames($output, 'Would be updated', $preview['updated']);
                $this->listNames($output, 'Would be deactivated', $preview['deactivated']);
                return self::SUCCESS;
            }

            $result = $this->importService->import($content);
        } catch (\InvalidArgumentException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return self::FAILURE;
        }

        $output->writeln("Imported {$result['fetched']} organisations: {$result['created']} created, {$result['updated']} updated, {$result['deactivated']} deactivated.");
        return self::SUCCESS;
    }

    /**
     * @param string[] $names
     */
    private function listNames(OutputInterface $output, string $label, array $names): void {
        $output->writeln(sprintf('%s: %d', $label, count($names)));
        $shown = $output->isVerbose() ? $names : array_slice($names, 0, self::PREVIEW_LIMIT);
        foreach ($shown as $name) {
            $output->writeln("  - {$name}");
        }
        if (count($shown) < count($names)) {
            $output->writeln(sprintf('  … and %d more (use -v to list all)', count($names) - count($shown)));
        }
    }
}
