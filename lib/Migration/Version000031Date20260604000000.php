<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000031Date20260604000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('opencase_userinfo');
        if (!$table->hasColumn('user_id')) {
            $table->addColumn('user_id', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addIndex(['user_id'], 'oc_userinfo_user_id');
        }

        return $schema;
    }
}
