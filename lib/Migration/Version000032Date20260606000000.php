<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000032Date20260606000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('opencase_reminders')) {
            $table = $schema->createTable('opencase_reminders');
            $table->addColumn('id',                  Types::BIGINT,   ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('entity',              Types::STRING,   ['notnull' => true, 'length' => 32]);
            $table->addColumn('entity_key',          Types::BIGINT,   ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('title',               Types::STRING,   ['notnull' => true, 'length' => 512]);
            $table->addColumn('deadline',            Types::DATETIME, ['notnull' => false]);
            $table->addColumn('status',              Types::STRING,   ['notnull' => true, 'length' => 32]);
            $table->addColumn('responsible_user_id', Types::STRING,   ['notnull' => false, 'length' => 64]);
            $table->addColumn('created_at',          Types::DATETIME, ['notnull' => true]);
            $table->addColumn('created_by',          Types::STRING,   ['notnull' => true, 'length' => 64]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['entity', 'entity_key'], 'oc_reminders_entity');
            $table->addIndex(['responsible_user_id'],  'oc_reminders_responsible');
            $table->addIndex(['status'],               'oc_reminders_status');
        }

        return $schema;
    }
}
