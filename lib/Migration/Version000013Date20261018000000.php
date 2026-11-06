<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * - opencase_class_plan — the customer's own classification plan (Emneplan),
 *   for customers who don't use KLE numbers. Same columns as
 *   opencase_class_subject; maintained on the Konfiguration page (CSV import
 *   or manually).
 * - opencase_cases.class_plan_uuid — the case's Emneplan item when the
 *   classification_plan config is 'Emneplan'. Stored on the case, not on the
 *   access profile, so it works with both FK Adgang and Grupper.
 */
class Version000013Date20261018000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── opencase_class_plan ──────────────────────────────────────────
        if (!$schema->hasTable('opencase_class_plan')) {
            $table = $schema->createTable('opencase_class_plan');
            $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('code', Types::STRING, ['notnull' => true, 'length' => 255, 'comment' => 'Emneplan code, e.g. 01.02']);
            $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('description', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
            $table->addColumn('disposalcode_id', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            $table->addColumn('deletion_deadline_id', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            $table->setPrimaryKey(['uuid']);
            $table->addUniqueIndex(['code'], 'oc_cp_code');
            $table->addIndex(['active'], 'oc_cp_active');
        }

        // ── opencase_cases ───────────────────────────────────────────────
        $cases = $schema->getTable('opencase_cases');
        if (!$cases->hasColumn('class_plan_uuid')) {
            $cases->addColumn('class_plan_uuid', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        }
        if (!$cases->hasIndex('oc_cases_class_plan')) {
            // ClassificationPlanRepository::countCases (delete guard).
            $cases->addIndex(['class_plan_uuid'], 'oc_cases_class_plan');
        }

        return $schema;
    }
}
