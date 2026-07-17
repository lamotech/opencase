<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds opencase_cases.exported_at (nullable datetime, set once a closed case
 * has been exported to disk by ExportClosedCasesJob) and seeds the
 * export_folder config entry that controls where exports are written.
 */
class Version000039Date20260715000000 extends SimpleMigrationStep {
    public function __construct(
        private IDBConnection $db,
        private IConfig $config,
    ) {}

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('opencase_cases')) {
            $table = $schema->getTable('opencase_cases');
            if (!$table->hasColumn('exported_at')) {
                $table->addColumn('exported_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
                $table->addIndex(['status_id', 'exported_at'], 'oc_cases_status_exported');
            }
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $defaultExportFolder = rtrim($this->config->getSystemValueString('datadirectory', ''), '/')
            . '/opencase_storage/_export';

        $this->seedConfigEntry(
            'export_folder',
            $defaultExportFolder,
            'Eksportmappe',
            'Sti hvor lukkede sager eksporteres til (XML-metadata, dokumentfiler og logs), én undermappe pr. sagsnummer. Skrives til hver time af ExportClosedCasesJob.',
            $output,
        );
    }

    private function seedConfigEntry(
        string $key,
        string $defaultValue,
        string $name,
        string $description,
        IOutput $output,
    ): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('config_key')
            ->from('opencase_config')
            ->where($qb->expr()->eq('config_key', $qb->createNamedParameter($key)));
        $result = $qb->executeQuery();
        $exists = $result->fetchOne();
        $result->closeCursor();

        if ($exists === false) {
            $qb->resetQueryParts();
            $qb->insert('opencase_config')->values([
                'config_key'   => $qb->createNamedParameter($key),
                'config_value' => $qb->createNamedParameter($defaultValue),
                'name'         => $qb->createNamedParameter($name),
                'description'  => $qb->createNamedParameter($description),
            ])->executeStatement();
            $output->info("Seeded config: {$key}.");
        } else {
            $qb->resetQueryParts();
            $qb->update('opencase_config')
                ->set('name', $qb->createNamedParameter($name))
                ->set('description', $qb->createNamedParameter($description))
                ->where($qb->expr()->eq('config_key', $qb->createNamedParameter($key)))
                ->executeStatement();
            $output->info("Updated metadata for config: {$key}.");
        }
    }
}
