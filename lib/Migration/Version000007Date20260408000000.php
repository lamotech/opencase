<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000007Date20260408000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('opencase_userinfo')) {
            $table = $schema->createTable('opencase_userinfo');
            $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('username', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            $table->addColumn('personname', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            $table->addColumn('email', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            $table->addColumn('phone', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $table->addColumn('location', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            $table->setPrimaryKey(['uuid']);
        }
        $table = $schema->getTable('opencase_cases');
        
        if (!$table->hasColumn('classification_facet_uuid')) {
            $table->addColumn('classification_facet_uuid', Types::STRING, [
                'notnull' => false,
                'length'  => 64,
                'default' => null,
            ]);
        }

        return $schema;
    }
}
