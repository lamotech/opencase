<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000025Date20260530000000 extends SimpleMigrationStep {
    public function __construct(private IDBConnection $db) {}

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('opencase_file_shares')) {
            $table = $schema->getTable('opencase_file_shares');

            if (!$table->hasColumn('document_id')) {
                $table->addColumn('document_id', Types::BIGINT, [
                    'notnull'  => false,
                    'default'  => null,
                    'unsigned' => true,
                ]);
                $table->addIndex(['document_id'], 'fshare_doc_idx');
            }
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $this->seedConfigEntry(
            'search_max_result_count',
            '500',
            'Maksimalt antal søgeresultater',
            'Det maksimale antal rækker der returneres på tværs af søgninger (Fritekst, Sager, Dokumenter og Alle sager). Brugeren kan bladre i resultater op til denne grænse.',
            $output,
        );

        $this->seedConfigEntry(
            'search_page_size',
            '50',
            'Sidevisning – søgeresultater',
            'Antal rækker pr. side i søgeresultater og sagslister.',
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
            // Update name/description even if value already exists (e.g. set by Configuration::ensureDefaultsExist)
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
