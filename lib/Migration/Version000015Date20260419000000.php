<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000015Date20260419000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('opencase_documentnotes')) {
            $table = $schema->createTable('opencase_documentnotes');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);
            $table->addColumn('document_id', Types::BIGINT, [
                'notnull'  => true,
                'unsigned' => true,
            ]);
            $table->addColumn('title', Types::STRING, [
                'length'  => 500,
                'notnull' => true,
                'default' => '',
            ]);
            $table->addColumn('text', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => false,
            ]);
            $table->addColumn('created_by', Types::STRING, [
                'length'  => 64,
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('updated_at', Types::DATETIME, [
                'notnull' => false,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['document_id'], 'oc_opencase_dn_doc_id');
        }

        return $schema;
    }
}
