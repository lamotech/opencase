<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Document relations schema.
 *
 * Tables:
 *   opencase_documentrelationtype — relation type reference (multilingual),
 *   seeded by SeedReferenceData.
 *   opencase_documentrelation     — a directed relation between two documents.
 *   `documentrelationtype_id` references opencase_documentrelationtype.id;
 *   no DB-level foreign key since that table's primary key is (id, language).
 */
class Version000007Date20261001000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── opencase_documentrelationtype ────────────────────────────────────
        if (!$schema->hasTable('opencase_documentrelationtype')) {
            $table = $schema->createTable('opencase_documentrelationtype');
            $table->addColumn('id', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('language', Types::STRING, ['notnull' => true, 'length' => 10]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('expired', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->setPrimaryKey(['id', 'language']);
        }

        // ── opencase_documentrelation ────────────────────────────────────────
        if (!$schema->hasTable('opencase_documentrelation')) {
            $table = $schema->createTable('opencase_documentrelation');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('document_from_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('document_to_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('documentrelationtype_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['document_from_id'], 'oc_docrel_from');
            $table->addIndex(['document_to_id'], 'oc_docrel_to');
            $table->addIndex(['documentrelationtype_id'], 'oc_docrel_type');
        }

        return $schema;
    }
}
