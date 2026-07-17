<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds updated_at / updated_by tracking columns to the editable code-list
 * tables, so the new Configuration > Code lists UI can record who last
 * changed an entry.
 */
class Version000034Date20260626000000 extends SimpleMigrationStep {

    private const TABLES = [
        'opencase_casestatus',
        'opencase_contactroles',
        'opencase_documentcategory',
        'opencase_documentstatus',
        'opencase_insightlevel',
        'opencase_participantroles',
    ];

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('opencase_org');

        if (!$table->hasColumn('nc_group_id')) {
            $table->addColumn('nc_group_id', Types::STRING, ['notnull' => false, 'length' => 128, 'default' => null]);
        }
        if (!$table->hasColumn('groupfolder_id')) {
            $table->addColumn('groupfolder_id', Types::INTEGER, ['notnull' => false, 'default' => null]);
        }

        foreach (self::TABLES as $tableName) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }
            $table = $schema->getTable($tableName);
            if (!$table->hasColumn('updated_at')) {
                $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            }
            if (!$table->hasColumn('updated_by')) {
                $table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            }
        }
        
        if (!$schema->hasTable('opencase_aiactions')) {
            $table = $schema->createTable('opencase_aiactions');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('prompt_id', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('sequence_index', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('action_type', Types::STRING, ['notnull' => true, 'length' => 100]);
            $table->addColumn('action_params', Types::TEXT, ['notnull' => true]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['prompt_id'], 'oc_opencase_aiactions_prompt');
        }

        return $schema;
    }
}
