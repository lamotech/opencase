<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds indexes covering opencase_customfield_value.value_bool and
 * .value_date. Only value_text was indexed by Version000004; at scale
 * (millions of cases) a checkbox- or date-type custom field search used
 * without other narrowing filters would otherwise force a full scan of
 * that field's value rows, since neither column had an index to seek on.
 */
class Version000005Date20260910000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('opencase_customfield_value')) {
            $table = $schema->getTable('opencase_customfield_value');
            if (!$table->hasIndex('oc_cfval_field_bool')) {
                // CaseMapper::applyCustomFieldFilters — checkbox-type filter
                $table->addIndex(['customfield_id', 'value_bool'], 'oc_cfval_field_bool');
            }
            if (!$table->hasIndex('oc_cfval_field_date')) {
                // CaseMapper::applyCustomFieldFilters — date-type range filter
                $table->addIndex(['customfield_id', 'value_date'], 'oc_cfval_field_date');
            }
        }

        return $schema;
    }
}
