<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Custom fields schema.
 *
 * Tables:
 *   opencase_customfield_def   — admin-defined field (label, type, options)
 *   opencase_customfield_scope — restricts a field to specific casetype(s),
 *   organisation(s) or KLE pattern(s). A field with no scope rows applies to
 *   every case.
 *   opencase_customfield_value — the per-case value of a field.
 */
class Version000004Date20260907000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── opencase_customfield_def ────────────────────────────────────────
        if (!$schema->hasTable('opencase_customfield_def')) {
            $table = $schema->createTable('opencase_customfield_def');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('field_key', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('field_type', Types::STRING, ['notnull' => true, 'length' => 20,
                'comment' => 'text | dropdown | checkbox | date']);
            // JSON array of option strings, used when field_type = dropdown.
            $table->addColumn('options', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('required', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('active', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['field_key'], 'oc_cfdef_key');
            $table->addIndex(['active'], 'oc_cfdef_active');
        }

        // ── opencase_customfield_scope ──────────────────────────────────────
        if (!$schema->hasTable('opencase_customfield_scope')) {
            $table = $schema->createTable('opencase_customfield_scope');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('customfield_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('scope_type', Types::STRING, ['notnull' => true, 'length' => 20,
                'comment' => 'casetype | organisation | kle']);
            // casetype id (string), org_uuid, or a KLE LIKE pattern such as "27.%".
            $table->addColumn('scope_value', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->setPrimaryKey(['id']);
            // CustomFieldScopeMapper::findByFieldIds
            $table->addIndex(['customfield_id'], 'oc_cfscope_field');
            // CustomFieldService::matchesScopes
            $table->addIndex(['scope_type', 'scope_value'], 'oc_cfscope_type_val');
        }

        // ── opencase_customfield_value ──────────────────────────────────────
        if (!$schema->hasTable('opencase_customfield_value')) {
            $table = $schema->createTable('opencase_customfield_value');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('case_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('customfield_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('value_text', Types::STRING, ['notnull' => false, 'length' => 500, 'default' => null]);
            $table->addColumn('value_bool', Types::BOOLEAN, ['notnull' => false, 'default' => null]);
            $table->addColumn('value_date', Types::DATE, ['notnull' => false, 'default' => null]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->setPrimaryKey(['id']);
            // CustomFieldValueMapper::findByCaseId / one row per field per case
            $table->addUniqueIndex(['case_id', 'customfield_id'], 'oc_cfval_case_field');
            // CustomFieldService search-by-field-value
            $table->addIndex(['customfield_id', 'value_text'], 'oc_cfval_field_text');
        }

        return $schema;
    }
}
