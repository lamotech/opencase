<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Process module, automatic steps (BPMN service tasks run by OpenCase as a
 * Flowable external worker):
 * - opencase_process_job — one row per Flowable job OpenCase has run, so a
 *   job the engine hands out again (e.g. after a lost "complete" call) is
 *   not run twice, and the case shows what was done automatically.
 *   status: running | done | failed.
 * - opencase_case_process.error_message / error_job_id — a step that failed
 *   for good (Flowable dead-letter job); the process waits until a user
 *   retries it.
 */
class Version000014Date20261015140000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $caseProcess = $schema->getTable('opencase_case_process');
        if (!$caseProcess->hasColumn('error_message')) {
            $caseProcess->addColumn('error_message', Types::TEXT, ['notnull' => false]);
        }
        if (!$caseProcess->hasColumn('error_job_id')) {
            $caseProcess->addColumn('error_job_id', Types::STRING, ['notnull' => false, 'length' => 64]);
        }

        if (!$schema->hasTable('opencase_process_job')) {
            $table = $schema->createTable('opencase_process_job');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('flowable_job_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('case_process_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('case_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            // BPMN element id and name of the service task
            $table->addColumn('element_id', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('element_name', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('topic', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16,
                'comment' => 'running | done | failed']);
            // The user the step acted as (case responsible, else who started the process)
            $table->addColumn('acted_as', Types::STRING, ['notnull' => false, 'length' => 64]);
            // JSON, e.g. {"journal_note_id": 12}
            $table->addColumn('result', Types::TEXT, ['notnull' => false]);
            $table->addColumn('error', Types::TEXT, ['notnull' => false]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('finished_at', Types::DATETIME, ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['flowable_job_id'], 'oc_procjob_flowable');
            $table->addIndex(['case_process_id'], 'oc_procjob_caseproc');
        }

        return $schema;
    }
}
