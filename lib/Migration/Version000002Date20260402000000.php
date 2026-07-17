<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add insight level support.
 *
 * Creates:
 *   opencase_insightlevel — multilingual insight level reference table
 *
 * Alters:
 *   opencase_cases     — adds insight_level_id column
 *   opencase_documents — adds insight_level_id column
 */
class Version000002Date20260402000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── opencase_certificate ──────────────────────────────────────────
        if (!$schema->hasTable('opencase_certificate')) {
            $table = $schema->createTable('opencase_certificate');
            $table->addColumn('code', 'string', ['length' => 100, 'notnull' => true]);
            $table->addColumn('filepath', 'string', ['length' => 256, 'notnull' => true]);
            $table->addColumn('password', 'string', ['length' => 256, 'notnull' => false]);

            $table->setPrimaryKey(['code']);
        }
        // ── opencase_insightlevel ────────────────────────────────────────
        if (!$schema->hasTable('opencase_insightlevel')) {
            $table = $schema->createTable('opencase_insightlevel');
            $table->addColumn('id', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('language', Types::STRING, ['notnull' => true, 'length' => 10]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('description', Types::STRING, ['notnull' => false, 'length' => 2000]);
            $table->setPrimaryKey(['id', 'language']);
        }

        // ── Add insight_level_id to opencase_cases ───────────────────────
        $casesTable = $schema->getTable('opencase_cases');
        if (!$casesTable->hasColumn('insight_level_id')) {
            $casesTable->addColumn('insight_level_id', Types::INTEGER, [
                'notnull' => false,
                'unsigned' => true,
                'default' => null,
            ]);
        }

        // ── Add insight_level_id to opencase_documents ───────────────────
        $docsTable = $schema->getTable('opencase_documents');
        if (!$docsTable->hasColumn('insight_level_id')) {
            $docsTable->addColumn('insight_level_id', Types::INTEGER, [
                'notnull' => false,
                'unsigned' => true,
                'default' => null,
            ]);
        }

        return $schema;
    }
}
