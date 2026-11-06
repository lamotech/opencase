<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Process module, running processes:
 * - opencase_case_process — a process started on a case, i.e. one Flowable
 *   process instance. status: running | completed | cancelled.
 * - opencase_process_task — OpenCase's copy of the user tasks of those
 *   instances, kept in step with Flowable by CaseProcessService::sync(), so
 *   task lists and access checks don't need a call to the engine.
 *   status: open | completed | cancelled.
 */
class Version000013Date20261015120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('opencase_case_process')) {
            $table = $schema->createTable('opencase_case_process');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('case_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('process_def_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('process_version_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('flowable_instance_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16,
                'comment' => 'running | completed | cancelled']);
            $table->addColumn('started_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('started_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->addColumn('ended_at', Types::DATETIME, ['notnull' => false]);
            // Who cancelled the process, and why
            $table->addColumn('ended_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->addColumn('end_reason', Types::TEXT, ['notnull' => false]);
            // JSON arrays of BPMN element ids, for highlighting the diagram
            $table->addColumn('active_activities', Types::TEXT, ['notnull' => false]);
            $table->addColumn('completed_activities', Types::TEXT, ['notnull' => false]);
            $table->addColumn('last_synced_at', Types::DATETIME, ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['case_id'], 'oc_caseproc_case');
            $table->addIndex(['status', 'last_synced_at'], 'oc_caseproc_sync');
            $table->addUniqueIndex(['flowable_instance_id'], 'oc_caseproc_inst');
        }

        if (!$schema->hasTable('opencase_process_task')) {
            $table = $schema->createTable('opencase_process_task');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('case_process_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('case_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('flowable_task_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            // BPMN element id of the user task
            $table->addColumn('activity_id', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('name', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16,
                'comment' => 'open | completed | cancelled']);
            $table->addColumn('assignee', Types::STRING, ['notnull' => false, 'length' => 64]);
            // Comma-separated Flowable candidate groups, e.g. "org:<uuid>,org:<uuid>"
            $table->addColumn('candidate_groups', Types::TEXT, ['notnull' => false]);
            $table->addColumn('due_date', Types::DATETIME, ['notnull' => false]);
            // JSON [{id, name}] — the choices of the task's "udfald" form property
            $table->addColumn('outcomes', Types::TEXT, ['notnull' => false]);
            $table->addColumn('outcome', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->addColumn('comment', Types::TEXT, ['notnull' => false]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('completed_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('completed_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['flowable_task_id'], 'oc_proctask_flowable');
            $table->addIndex(['case_process_id'], 'oc_proctask_caseproc');
            $table->addIndex(['status', 'assignee'], 'oc_proctask_status_user');
        }

        return $schema;
    }
}
