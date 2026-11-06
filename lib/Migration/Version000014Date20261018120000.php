<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * - opencase_separation_sheets.class_plan_uuid — the Emneplan item of a
 *   'new case' / 'inbox case' sheet when the classification_plan config is
 *   'Emneplan'; copied to the case created from the sheet.
 */
class Version000014Date20261018120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $sheets = $schema->getTable('opencase_separation_sheets');
        if (!$sheets->hasColumn('class_plan_uuid')) {
            $sheets->addColumn('class_plan_uuid', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        }

        return $schema;
    }
}
