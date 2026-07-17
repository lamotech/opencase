<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000029Date20260603000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('opencase_org');

        if (!$table->hasColumn('email')) {
            $table->addColumn('email', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        }
        if (!$table->hasColumn('phone')) {
            $table->addColumn('phone', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        }
        if (!$table->hasColumn('location')) {
            $table->addColumn('location', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        }

        return $schema;
    }
}
