<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds participant-role lookup table and case-participants table.
 *
 * New tables:
 *   opencase_participantroles  — multilingual lookup: Sagspart/Ansøger/… (da), Case party/Applicant/… (en)
 *   opencase_caseparticipants  — persons/organisations linked to a case
 */
class Version000011Date20260415000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── opencase_participantroles ─────────────────────────────────────
        if (!$schema->hasTable('opencase_participantroles')) {
            $table = $schema->createTable('opencase_participantroles');
            $table->addColumn('id', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('language', Types::STRING, ['notnull' => true, 'length' => 10]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->setPrimaryKey(['id', 'language']);
        }

        // ── opencase_caseparticipants ─────────────────────────────────────
        if (!$schema->hasTable('opencase_caseparticipants')) {
            $table = $schema->createTable('opencase_caseparticipants');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('case_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('participantrole_id', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('cpr_cvr', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => null]);
            $table->addColumn('name', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->addColumn('streetname', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->addColumn('housenumber', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => null]);
            $table->addColumn('floor', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => null]);
            $table->addColumn('door', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => null]);
            $table->addColumn('zipcode', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => null]);
            $table->addColumn('zipdistrict', Types::STRING, ['notnull' => false, 'length' => 128, 'default' => null]);
            $table->addColumn('phone', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addColumn('email', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->addColumn('updated_date', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['case_id'], 'oc_cp_case_id');
        }

        return $schema;
    }
}
