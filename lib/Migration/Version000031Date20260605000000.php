<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000031Date20260605000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('opencase_doc_workflows')) {
            $table = $schema->createTable('opencase_doc_workflows');
            $table->addColumn('id',          Types::BIGINT,  ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('document_id', Types::BIGINT,  ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('type',        Types::STRING,  ['notnull' => true, 'length' => 16]);   // 'review' | 'approval'
            $table->addColumn('status',      Types::STRING,  ['notnull' => true, 'length' => 16]);   // 'active' | 'completed' | 'rejected' | 'cancelled'
            $table->addColumn('deadline',    Types::DATETIME, ['notnull' => false]);
            $table->addColumn('created_by',  Types::STRING,  ['notnull' => true, 'length' => 64]);
            $table->addColumn('created_at',  Types::DATETIME, ['notnull' => true]);
            $table->addColumn('updated_at',  Types::DATETIME, ['notnull' => true]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['document_id'], 'oc_docwf_document_id');
            $table->addIndex(['status'],      'oc_docwf_status');
        }

        if (!$schema->hasTable('opencase_doc_workflow_steps')) {
            $table = $schema->createTable('opencase_doc_workflow_steps');
            $table->addColumn('id',          Types::BIGINT,  ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('workflow_id', Types::BIGINT,  ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id',     Types::STRING,  ['notnull' => true, 'length' => 64]);
            $table->addColumn('sort_order',  Types::INTEGER, ['notnull' => true]);
            $table->addColumn('status',      Types::STRING,  ['notnull' => true, 'length' => 16]);   // 'pending' | 'reviewed' | 'approved' | 'rejected'
            $table->addColumn('comment',     Types::TEXT,    ['notnull' => false]);
            $table->addColumn('deadline',    Types::DATETIME, ['notnull' => false]);
            $table->addColumn('acted_at',    Types::DATETIME, ['notnull' => false]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['workflow_id'], 'oc_docwfstep_workflow_id');
            $table->addIndex(['user_id'],     'oc_docwfstep_user_id');
        }

        return $schema;
    }
}
