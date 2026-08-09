<?php

declare(strict_types=1);

namespace OCA\OpenCase\Command;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OCC command: opencase:filecache:sync
 *
 * Runs the opencase_sync_filecache() stored procedure on demand.
 * Useful after bulk data imports, manual DB edits, or to verify
 * the repair job has caught up without waiting up to 6 hours.
 *
 * Usage:
 *   occ opencase:filecache:sync
 *   occ opencase:filecache:sync --dry-run   (check row counts without modifying)
 */
class SyncFilecache extends Command {

    public function __construct(
        private IDBConnection $db,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('opencase:filecache:sync')
            ->setDescription('Rebuild the OpenCase virtual filesystem in oc_filecache (full repair sync).')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show current filecache row counts without running the sync.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        if ($input->getOption('dry-run')) {
            return $this->showStats($output);
        }

        $output->writeln('Starting OpenCase filecache sync…');
        $start = microtime(true);

        try {
            $this->db->executeStatement('CALL opencase_sync_filecache()');
        } catch (\Throwable $e) {
            $output->writeln('<error>Sync failed: ' . $e->getMessage() . '</error>');
            $this->logger->error('OpenCase occ filecache sync failed: {error}', [
                'error' => $e->getMessage(),
            ]);
            return self::FAILURE;
        }

        $elapsed = round(microtime(true) - $start, 2);
        $output->writeln("Done in {$elapsed}s.");
        $this->showStats($output);

        return self::SUCCESS;
    }

    private function showStats(OutputInterface $output): int {
        try {
            $qb      = $this->db->getQueryBuilder();
            $storage = $qb->select('numeric_id')->from('storages')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter('opencase')))
                ->executeQuery()->fetchOne();

            if ($storage === false) {
                $output->writeln('<comment>No opencase storage row found — sync has never run.</comment>');
                return self::SUCCESS;
            }

            $storageId = (int) $storage;

            // Count dirs (mimetype = httpd/unix-directory) and files separately
            $qb      = $this->db->getQueryBuilder();
            $dirMime = $qb->select('id')->from('mimetypes')
                ->where($qb->expr()->eq('mimetype', $qb->createNamedParameter('httpd/unix-directory')))
                ->executeQuery()->fetchOne();

            $qb    = $this->db->getQueryBuilder();
            $total = (int) $qb->select($qb->createFunction('COUNT(*)'))
                ->from('filecache')
                ->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeQuery()->fetchOne();

            $dirs = 0;
            if ($dirMime !== false) {
                $qb   = $this->db->getQueryBuilder();
                $dirs = (int) $qb->select($qb->createFunction('COUNT(*)'))
                    ->from('filecache')
                    ->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->andWhere($qb->expr()->eq('mimetype', $qb->createNamedParameter((int) $dirMime, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->executeQuery()->fetchOne();
            }

            $files = $total - $dirs;
            $output->writeln("oc_filecache rows for opencase storage: {$total} total ({$dirs} dirs, {$files} files)");
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed to read stats: ' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
