<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000020Date20260520000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('opencase_cases')) {
            $table = $schema->getTable('opencase_cases');
            if (!$table->hasColumn('parent_case_id')) {
                $table->addColumn('parent_case_id', Types::BIGINT, [
                    'notnull' => false,
                    'default' => null,
                    'unsigned' => true,
                ]);
            }
        }

        return $schema;
    }
}
