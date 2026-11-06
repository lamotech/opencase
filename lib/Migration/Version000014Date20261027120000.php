<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * opencase_template_scope — restricts a template (opencase_templates) to
 * organisation(s), like opencase_phrases_scope. A template without scope
 * rows is available for every case; with organisation scopes only for
 * cases in those organisations (and their descendants), and only phrases
 * matching one of the organisations can be attached to it.
 */
class Version000014Date20261027120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('opencase_template_scope')) {
            $table = $schema->createTable('opencase_template_scope');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('template_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('scope_type', Types::STRING, ['notnull' => true, 'length' => 20,
                'comment' => 'organisation']);
            $table->addColumn('scope_value', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['template_id'], 'oc_tplscope_template');
        }

        return $schema;
    }
}
