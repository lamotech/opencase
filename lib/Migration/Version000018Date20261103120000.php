<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * KLE number and sensitivity on the case, for Grupper access profiles with
 * the KLE classification plan. With FK Adgang both are part of the access
 * profile; a group profile only holds the group, so they are stored here.
 *
 * - opencase_cases.class_subject_uuid — KLE classification subject
 *   (opencase_class_subject.uuid)
 * - opencase_cases.sensitivity_uuid — sensitivity (opencase_sensitivity.uuid)
 *
 * NULL for FK Adgang cases. Read them through CaseEntity::effectiveClassSubjectUuid()
 * / effectiveSensitivityUuid(), which fall back to the access profile.
 */
class Version000018Date20261103120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $cases = $schema->getTable('opencase_cases');
        if (!$cases->hasColumn('class_subject_uuid')) {
            $cases->addColumn('class_subject_uuid', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        }
        if (!$cases->hasColumn('sensitivity_uuid')) {
            $cases->addColumn('sensitivity_uuid', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        }
        if (!$cases->hasIndex('oc_case_class_subject')) {
            // KLE search filter and scopes on group cases
            $cases->addIndex(['class_subject_uuid'], 'oc_case_class_subject');
        }

        return $schema;
    }
}
