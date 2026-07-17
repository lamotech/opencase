<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000024Date20260527000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('opencase_caseparticipants')) {
            $table = $schema->getTable('opencase_caseparticipants');
            if (!$table->hasColumn('has_address_protection')) {
                $table->addColumn('has_address_protection', Types::BOOLEAN, [
                    'notnull' => true,
                    'default' => false,
                ]);
            }
        }

        if ($schema->hasTable('opencase_documentcontacts')) {
            $table = $schema->getTable('opencase_documentcontacts');
            if (!$table->hasColumn('has_address_protection')) {
                $table->addColumn('has_address_protection', Types::BOOLEAN, [
                    'notnull' => true,
                    'default' => false,
                ]);
            }
        }

        return $schema;
    }
}
