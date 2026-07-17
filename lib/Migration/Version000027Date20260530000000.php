<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000027Date20260530000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('opencase_userorgs');

        $table->getColumn('user_id')->setNotnull(true);
        $table->dropPrimaryKey();
        $table->setPrimaryKey(['user_id', 'org_uuid']);

        return $schema;
    }
}
