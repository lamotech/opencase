<?php

declare(strict_types=1);

namespace OCA\OpenCase\Command;

use OCA\OpenCase\Service\TemplateService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ImportTemplatesCommand extends Command {

    private const DOCX_MIME_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function __construct(
        private TemplateService $templateService,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('opencase:import-templates')
            ->setDescription('Import one or more .docx files as document templates')
            ->addArgument('path', InputArgument::REQUIRED, 'A .docx file, or a folder containing .docx files')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Nextcloud user ID to record as the uploader', 'admin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $path = $input->getArgument('path');
        $uploadedBy = $input->getOption('user');

        if (!file_exists($path)) {
            $output->writeln("<error>Path not found: {$path}</error>");
            return Command::FAILURE;
        }

        if (is_dir($path)) {
            $files = glob(rtrim($path, '/') . '/*.docx');
            sort($files);
        } elseif (is_file($path)) {
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'docx') {
                $output->writeln("<error>Not a .docx file: {$path}</error>");
                return Command::FAILURE;
            }
            $files = [$path];
        } else {
            $output->writeln("<error>Not a file or folder: {$path}</error>");
            return Command::FAILURE;
        }

        if (empty($files)) {
            $output->writeln("<comment>No .docx files found in {$path}</comment>");
            return Command::SUCCESS;
        }

        $imported = 0;
        foreach ($files as $file) {
            $originalFilename = basename($file);
            $name = pathinfo($file, PATHINFO_FILENAME);

            $stream = fopen($file, 'rb');
            if ($stream === false) {
                $output->writeln("<error>Failed to open {$file}</error>");
                continue;
            }

            try {
                $this->templateService->upload($name, $originalFilename, self::DOCX_MIME_TYPE, $stream, $uploadedBy);
                $output->writeln("<info>Imported template: {$name}</info>");
                $imported++;
            } catch (\Throwable $e) {
                $output->writeln("<error>Failed to import {$file}: {$e->getMessage()}</error>");
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        $output->writeln("<info>Imported {$imported} of " . count($files) . " template(s)</info>");
        return $imported > 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
