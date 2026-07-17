<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000028Date20260601000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('opencase_transaction_log')) {
            $table = $schema->createTable('opencase_transaction_log');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('transaction_time', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('transaction_type', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('details', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'oc_txlog_user_id');
            $table->addIndex(['transaction_time'], 'oc_txlog_time');
            $table->addIndex(['transaction_type'], 'oc_txlog_type');
        }

        return $schema;
    }
}
