<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds an `expired` column to the code-list tables that don't already have
 * one (opencase_casestatus and opencase_documentstatus already had it).
 *
 * Lets the Configuration > Code lists UI mark values as expired so they can
 * be excluded from selection dropdowns while still displaying correctly on
 * records that already reference them.
 */
class Version000035Date20260626010000 extends SimpleMigrationStep {

    private const TABLES = [
        'opencase_casestatus',
        'opencase_contactroles',
        'opencase_documentcategory',
        'opencase_documentstatus',
        'opencase_insightlevel',
        'opencase_participantroles',
    ];

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        foreach (self::TABLES as $tableName) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }
            $table = $schema->getTable($tableName);
            if (!$table->hasColumn('expired')) {
                $table->addColumn('expired', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            }
        }

        return $schema;
    }
}
