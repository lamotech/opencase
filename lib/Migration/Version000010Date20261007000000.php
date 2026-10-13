<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * opencase_documents.is_deleted / deleted_date — soft-delete of documents.
 *
 * When opencase_config.use_softdelete = 1, deleting a document only flags the
 * row; the document and its files are kept so it can be restored later.
 */
class Version000010Date20261007000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('opencase_documents');
        if (!$table->hasColumn('is_deleted')) {
            $table->addColumn('is_deleted', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
        }
        if (!$table->hasColumn('deleted_date')) {
            $table->addColumn('deleted_date', Types::DATETIME, ['notnull' => false]);
        }

        return $schema;
    }
}
