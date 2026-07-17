<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000018Date20260516000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('opencase_dp_receivements')) {
            $table = $schema->createTable('opencase_dp_receivements');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);
            $table->addColumn('transaktionstid', Types::STRING, [
                'length'  => 100,
                'notnull' => true,
                'default' => '',
            ]);
            $table->addColumn('transaktionsid', Types::STRING, [
                'length'  => 100,
                'notnull' => true,
                'default' => '',
            ]);
            $table->addColumn('storage_filename', Types::STRING, [
                'length'  => 255,
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('message_uuid', Types::STRING, [
                'length'  => 36,
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('sender_idtype', Types::STRING, [
                'length'  => 32,
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('sender_id', Types::STRING, [
                'length'  => 100,
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('status', Types::STRING, [
                'length'  => 32,
                'notnull' => true,
                'default' => 'Pending',
            ]);
            $table->addColumn('received_at', Types::DATETIME, [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['transaktionsid'], 'oc_opencase_dprec_tid');
            $table->addIndex(['message_uuid'],   'oc_opencase_dprec_uuid');
        }

        return $schema;
    }
}
