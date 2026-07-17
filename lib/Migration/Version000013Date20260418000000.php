<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds prior_can_write column to opencase_caseworkers so we can restore
 * the user's original access level when they are removed as a caseworker.
 *
 * Values:
 *   NULL  — user had no access grant before being added as caseworker
 *   0     — user had read-only access before
 *   1     — user already had write access before (access was not changed)
 */
class Version000013Date20260418000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('opencase_caseworkers')) {
            $table = $schema->getTable('opencase_caseworkers');
            if (!$table->hasColumn('prior_can_write')) {
                $table->addColumn('prior_can_write', Types::SMALLINT, [
                    'notnull' => false,
                    'default' => null,
                    'unsigned' => false,
                ]);
            }
        }

        return $schema;
    }
}
