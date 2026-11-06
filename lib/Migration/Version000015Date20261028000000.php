<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Log\LoggerInterface;

/**
 * Template and phrase files now live only in {storage_base_dir}/_templates
 * and /_phrases and are edited there in place (BrevdesignerMountProvider).
 *
 * Earlier, editing a template or phrase in Nextcloud Office made a copy in
 * the editor's Files (OpenCase/Skabeloner, OpenCase/Fraser), linked through
 * nc_file_id / nc_owner. This step writes the latest content of each linked
 * copy back to the stored file and clears the link. The copies themselves
 * are left in the users' Files; they are no longer used.
 */
class Version000015Date20261028000000 extends SimpleMigrationStep {

    public function __construct(
        private IDBConnection $db,
        private IRootFolder $rootFolder,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        return null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $defaultDir = rtrim($this->config->getSystemValueString('datadirectory', ''), '/') . '/opencase_storage';
        $baseDir = rtrim($this->config->getAppValue('opencase', 'storage_base_dir', $defaultDir), '/');

        foreach (['opencase_templates' => '_templates', 'opencase_phrases' => '_phrases'] as $table => $folder) {
            $qb = $this->db->getQueryBuilder();
            $rows = $qb->select('id', 'storage_filename', 'nc_file_id', 'nc_owner')
                ->from($table)
                ->where($qb->expr()->isNotNull('nc_file_id'))
                ->executeQuery()
                ->fetchAll();

            foreach ($rows as $row) {
                $this->syncBack($table, $baseDir . '/' . $folder, $row, $output);
            }
        }
    }

    /**
     * @param array{id: int|string, storage_filename: ?string, nc_file_id: int|string, nc_owner: ?string} $row
     */
    private function syncBack(string $table, string $dir, array $row, IOutput $output): void {
        $id = (int)$row['id'];
        $target = $dir . '/' . $row['storage_filename'];
        $size = null;

        try {
            if ($row['nc_owner'] !== null && $row['storage_filename'] !== null && $row['storage_filename'] !== '') {
                $nodes = $this->rootFolder->getUserFolder($row['nc_owner'])->getById((int)$row['nc_file_id']);
                if (!empty($nodes) && $nodes[0] instanceof \OCP\Files\File) {
                    $content = $nodes[0]->getContent();
                    if (!is_dir($dir)) {
                        mkdir($dir, 0750, true);
                    }
                    if (file_put_contents($target, $content) !== false) {
                        $size = strlen($content);
                        $output->info("OpenCase: {$table} {$id} — copied the edited file back to storage");
                    }
                } else {
                    $output->info("OpenCase: {$table} {$id} — linked copy no longer exists, keeping the stored file");
                }
            }
        } catch (\Throwable $e) {
            // Keep the stored file as it is; only the link is cleared.
            $this->logger->warning('OpenCase: could not copy {table} {id} back to storage: {msg}', [
                'table' => $table, 'id' => $id, 'msg' => $e->getMessage(),
            ]);
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update($table)
            ->set('nc_file_id', $qb->createNamedParameter(null))
            ->set('nc_owner', $qb->createNamedParameter(null))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
        if ($size !== null) {
            $qb->set('size', $qb->createNamedParameter($size));
        }
        $qb->executeStatement();
    }
}
