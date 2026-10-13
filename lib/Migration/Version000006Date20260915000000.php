<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Bulk-update schema.
 *
 * Tables:
 *   opencase_bulkupdate       — a saved bulk-update job: search criteria plus
 *   the field updates to apply to every matching case or document.
 *   `criterias` and `updates` are JSON blobs — the set of searchable fields
 *   and updatable fields differs per entity type, so a fixed relational
 *   shape would need a column per field.
 *   opencase_bulkupdate_items — one row per case/document targeted by a job,
 *   tracked individually so the processing step can report and retry per
 *   item instead of all-or-nothing. `original_values` is filled in by that
 *   processing step, right before it overwrites a field — it stays null
 *   until then.
 */
class Version000006Date20260915000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── opencase_bulkupdate ──────────────────────────────────────────────
        if (!$schema->hasTable('opencase_bulkupdate')) {
            $table = $schema->createTable('opencase_bulkupdate');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('entity', Types::STRING, ['notnull' => true, 'length' => 100,
                'comment' => 'case | document']);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('total_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => 'created',
                'comment' => 'created | pending | processing | processed | cancelled']);
            $table->addColumn('criterias', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('updates', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->setPrimaryKey(['id']);
            // BulkUpdateMapper::findByUserAndEntity
            $table->addIndex(['created_by', 'entity', 'created_at'], 'oc_bulkupd_user_entity');
        }

        // ── opencase_bulkupdate_items ────────────────────────────────────────
        if (!$schema->hasTable('opencase_bulkupdate_items')) {
            $table = $schema->createTable('opencase_bulkupdate_items');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('bulkupdate_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('entity_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true,
                'comment' => 'opencase_cases.id or opencase_documents.id, depending on the parent job\'s entity']);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => 'pending',
                'comment' => 'pending | processing | successed | failed']);
            $table->addColumn('error_msg', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->addColumn('original_values', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->setPrimaryKey(['id']);
            // BulkUpdateItemMapper::findByBulkUpdateId
            $table->addIndex(['bulkupdate_id'], 'oc_bulkitem_bulkupdate');
            // Future processing step: claim the next pending item of a job
            $table->addIndex(['bulkupdate_id', 'status'], 'oc_bulkitem_bu_status');
        }

        return $schema;
    }
}
