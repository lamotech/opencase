<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000008Date20260408000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('opencase_userorgs')) {
            $table = $schema->createTable('opencase_userorgs');
            $table->addColumn('user_uuid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('org_uuid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('role', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            $table->setPrimaryKey(['user_uuid', 'org_uuid']);
            $table->addIndex(['user_uuid'], 'oc_userorgs_user_uuid');
        }

        return $schema;
    }
}
