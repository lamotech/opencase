<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Process module, phase 4:
 * - opencase_process_task.form_fields / form_values — the fields of a task's
 *   form (Flowable form properties other than the outcome "udfald") and the
 *   values entered when it was completed. JSON.
 * - opencase_process_migration — moving running processes to a newer
 *   version, run by ProcessMigrationJob. status: queued | running | done.
 */
class Version000015Date20261016100000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $task = $schema->getTable('opencase_process_task');
        if (!$task->hasColumn('form_fields')) {
            // [{id, name, type: string|long|date|boolean|enum, required, options?}]
            $task->addColumn('form_fields', Types::TEXT, ['notnull' => false]);
        }
        if (!$task->hasColumn('form_values')) {
            // {field id: value as entered}
            $task->addColumn('form_values', Types::TEXT, ['notnull' => false]);
        }

        if (!$schema->hasTable('opencase_process_migration')) {
            $table = $schema->createTable('opencase_process_migration');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('process_def_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('to_version_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            // {from element id: to element id} for steps that don't exist in the new version
            $table->addColumn('mappings', Types::TEXT, ['notnull' => false]);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16,
                'comment' => 'queued | running | done']);
            $table->addColumn('total', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('migrated', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('failed', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            // [{case_process_id, case_id, case_number, error}]
            $table->addColumn('errors', Types::TEXT, ['notnull' => false]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->addColumn('finished_at', Types::DATETIME, ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['process_def_id'], 'oc_procmig_def');
        }

        return $schema;
    }
}
