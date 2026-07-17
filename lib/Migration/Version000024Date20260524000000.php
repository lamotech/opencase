<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000024Date20260524000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('opencase_dist_receivements')) {
            $table = $schema->createTable('opencase_dist_receivements');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);
            $table->addColumn('transaktions_id', Types::STRING, [
                'notnull' => true,
                'length'  => 100,
            ]);
            $table->addColumn('storage_filename', Types::STRING, [
                'notnull' => true,
                'length'  => 255,
            ]);
            $table->addColumn('objekttype', Types::STRING, [
                'notnull' => false,
                'length'  => 32,
                'default' => null,
            ]);
            $table->addColumn('afsendende_myndighed', Types::STRING, [
                'notnull' => false,
                'length'  => 32,
                'default' => null,
            ]);
            $table->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length'  => 32,
                'default' => 'Pending',
            ]);

            $table->setPrimaryKey(['id']);
        }

        return $schema;
    }
}
