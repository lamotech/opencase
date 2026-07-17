<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds contact-role lookup table and document-contacts table.
 *
 * New tables:
 *   opencase_contactroles      — multilingual lookup: Afsender/Modtager (da), Sender/Receiver (en)
 *   opencase_documentcontacts  — contact persons/organisations linked to a document
 */
class Version000010Date20260415000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── opencase_contactroles ─────────────────────────────────────────
        if (!$schema->hasTable('opencase_contactroles')) {
            $table = $schema->createTable('opencase_contactroles');
            $table->addColumn('id', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('language', Types::STRING, ['notnull' => true, 'length' => 10]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->setPrimaryKey(['id', 'language']);
        }

        // ── opencase_documentcontacts ─────────────────────────────────────
        if (!$schema->hasTable('opencase_documentcontacts')) {
            $table = $schema->createTable('opencase_documentcontacts');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('document_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('contactrole_id', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
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
            $table->addIndex(['document_id'], 'oc_dc_document_id');
        }

        return $schema;
    }
}
