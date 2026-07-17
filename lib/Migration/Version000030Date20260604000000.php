<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000030Date20260604000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        foreach (['opencase_documentcontacts', 'opencase_caseparticipants', 'opencase_dp_receivers'] as $tableName) {
            $table = $schema->getTable($tableName);
            if (!$table->hasColumn('pnumber')) {
                $table->addColumn('pnumber', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => null]);
            }
        }

        return $schema;
    }
}
