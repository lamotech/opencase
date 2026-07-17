<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000019Date20260517000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('opencase_cases')) {
            $table = $schema->getTable('opencase_cases');
            if (!$table->hasColumn('is_inbox')) {
                $table->addColumn('is_inbox', Types::BOOLEAN, [
                    'notnull' => true,
                    'default' => false,
                ]);
                $table->addIndex(['org_uuid', 'is_inbox'], 'oc_opencase_cases_org_inbox');
            }
        }

        return $schema;
    }
}
