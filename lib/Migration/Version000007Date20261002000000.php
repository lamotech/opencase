<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Disposal (kassation) and deletion deadline (slettefrist) schema.
 *
 * Tables:
 *   opencase_disposalcode — retention codes (B = bevaring, K = kassation,
 *   K5/K10 = kassation after N years). `disposal_years` is null for codes
 *   that are never disposed of. Seeded by SeedReferenceData.
 *   opencase_deletion_deadline — deletion deadlines expressed as ISO 8601
 *   durations (P100D, P365D, ...) with the number of days in
 *   `deletion_days`. Seeded by SeedReferenceData.
 *
 * Columns:
 *   opencase_class_subject / opencase_class_facet .disposalcode_id / .deletion_deadline_id
 *   — default disposal code and deletion deadline for cases classified under
 *   that subject/facet.
 *   opencase_cases.closed_date / disposalcode_id / disposal_date / deleted_date
 *   — when the case was closed, which code applies, when it is due for
 *   disposal, and when it was actually disposed of.
 *   opencase_cases.deletion_deadline_id / deletion_date
 *   — which deadline applies to the case and when it is due for deletion.
 *
 * disposalcode_id and deletion_deadline_id reference opencase_disposalcode.id
 * and opencase_deletion_deadline.id; like the other lookup references
 * (status_id, casetype_id, ...) they are not enforced by a foreign key.
 */
class Version000007Date20261002000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── opencase_disposalcode ────────────────────────────────────────────
        if (!$schema->hasTable('opencase_disposalcode')) {
            $table = $schema->createTable('opencase_disposalcode');
            $table->addColumn('id', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('code', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('description', Types::STRING, ['notnull' => false, 'length' => 2000, 'default' => null]);
            $table->addColumn('disposal_years', Types::INTEGER, ['notnull' => false, 'default' => null,
                'comment' => 'Years after closing before disposal; null = never disposed of (bevaring)']);
            $table->addColumn('expired', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['uuid'], 'oc_dispcode_uuid');
        }

        // ── opencase_deletion_deadline ───────────────────────────────────────
        if (!$schema->hasTable('opencase_deletion_deadline')) {
            $table = $schema->createTable('opencase_deletion_deadline');
            $table->addColumn('id', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('code', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('description', Types::STRING, ['notnull' => false, 'length' => 2000, 'default' => null]);
            $table->addColumn('deletion_days', Types::INTEGER, ['notnull' => false, 'default' => null]);
            $table->addColumn('expired', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['uuid'], 'oc_deldeadline_uuid');
        }

        // ── opencase_class_subject / opencase_class_facet ────────────────────
        foreach (['opencase_class_subject', 'opencase_class_facet'] as $tableName) {
            if ($schema->hasTable($tableName)) {
                $table = $schema->getTable($tableName);
                if (!$table->hasColumn('disposalcode_id')) {
                    $table->addColumn('disposalcode_id', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => null]);
                }
                if (!$table->hasColumn('deletion_deadline_id')) {
                    $table->addColumn('deletion_deadline_id', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => null]);
                }
            }
        }

        // ── opencase_cases ───────────────────────────────────────────────────
        if ($schema->hasTable('opencase_cases')) {
            $table = $schema->getTable('opencase_cases');
            if (!$table->hasColumn('closed_date')) {
                $table->addColumn('closed_date', Types::DATETIME, ['notnull' => false, 'default' => null]);
            }
            if (!$table->hasColumn('disposalcode_id')) {
                $table->addColumn('disposalcode_id', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            }
            if (!$table->hasColumn('disposal_date')) {
                $table->addColumn('disposal_date', Types::DATETIME, ['notnull' => false, 'default' => null]);
            }
            if (!$table->hasColumn('deleted_date')) {
                $table->addColumn('deleted_date', Types::DATETIME, ['notnull' => false, 'default' => null]);
            }
            if (!$table->hasColumn('deletion_deadline_id')) {
                $table->addColumn('deletion_deadline_id', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            }
            if (!$table->hasColumn('deletion_date')) {
                $table->addColumn('deletion_date', Types::DATETIME, ['notnull' => false, 'default' => null]);
            }
        }

        return $schema;
    }
}
