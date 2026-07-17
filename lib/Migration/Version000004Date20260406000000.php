<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add opencase_documentcategory lookup table and document_category_id to opencase_documents.
 */
class Version000004Date20260406000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── opencase_documentcategory ────────────────────────────────────
        if (!$schema->hasTable('opencase_documentcategory')) {
            $table = $schema->createTable('opencase_documentcategory');
            $table->addColumn('id', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('language', Types::STRING, ['notnull' => true, 'length' => 10]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->setPrimaryKey(['id', 'language']);
        }

        // ── Add document_category_id to opencase_documents ───────────────
        $docsTable = $schema->getTable('opencase_documents');
        if (!$docsTable->hasColumn('document_category_id')) {
            $docsTable->addColumn('document_category_id', Types::INTEGER, [
                'notnull' => false,
                'unsigned' => true,
                'default' => null,
            ]);
        }

        return $schema;
    }
}
