<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add document_date, received_date and registered_date to opencase_documents.
 */
class Version000003Date20260406000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $table  = $schema->getTable('opencase_documents');

        if (!$table->hasColumn('document_date')) {
            $table->addColumn('document_date', Types::STRING, [
                'notnull' => false,
                'length'  => 10,
                'default' => null,
            ]);
        }

        if (!$table->hasColumn('received_date')) {
            $table->addColumn('received_date', Types::STRING, [
                'notnull' => false,
                'length'  => 10,
                'default' => null,
            ]);
        }

        if (!$table->hasColumn('registered_date')) {
            $table->addColumn('registered_date', Types::STRING, [
                'notnull' => false,
                'length'  => 10,
                'default' => null,
            ]);
        }

        return $schema;
    }
}
