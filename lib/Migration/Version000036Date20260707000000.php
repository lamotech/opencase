<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates opencase_cprevents — the reference list of Danish CPR
 * (Det Centrale Personregister) event codes, used to control per-event-type
 * logging and notification behaviour.
 * Adds opencase_casetype (multilingual case type reference data) and links
 * opencase_cases to it via a new casetype_id column.
 */
class Version000036Date20260707000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('opencase_cprevents')) {
            $table = $schema->createTable('opencase_cprevents');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('code', Types::STRING, ['notnull' => true, 'length' => 100]);
            $table->addColumn('description', Types::STRING, ['notnull' => true, 'length' => 512]);
            $table->addColumn('enabled', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
            $table->addColumn('log_on_case', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
            $table->addColumn('log_on_document', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
            $table->addColumn('send_notification', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['code'], 'opencase_cprevents_code');
        }
        
        // ── opencase_casetype ─────────────────────────────────────────────
        if (!$schema->hasTable('opencase_casetype')) {
            $table = $schema->createTable('opencase_casetype');
            $table->addColumn('id', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('language', Types::STRING, ['notnull' => true, 'length' => 10]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('expired', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addColumn('primary_participant', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => 'None']);
            $table->setPrimaryKey(['id', 'language']);
        }

        // ── opencase_cases.casetype_id ──────────────────────────────────────
        if ($schema->hasTable('opencase_cases')) {
            $table = $schema->getTable('opencase_cases');
            if (!$table->hasColumn('casetype_id')) {
                $table->addColumn('casetype_id', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => 1]);
                $table->addIndex(['casetype_id'], 'oc_cases_casetype_id');
            }
        }

        return $schema;
    }
}
