<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Import locations and separation sheets schema.
 *
 * Tables:
 *   opencase_importlocations   — configured folder/mailbox sources to scan for import
 *   opencase_importitems       — items found and processed from an import location
 *   opencase_separation_sheets — printable, QR-identified sheets used to mark
 *   where a scanned/imported document should be filed (existing case, new
 *   case, the inbox case, or a plain attachment/"Bilag" separator).
 */
class Version000003Date20260828000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── opencase_importlocations ──────────────────────────────────────
        if (!$schema->hasTable('opencase_importlocations')) {
            $table = $schema->createTable('opencase_importlocations');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 32,
                'comment' => 'folder | mailbox']);
            $table->addColumn('expired', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('folderpath', Types::STRING, ['notnull' => false, 'length' => 1000, 'default' => null]);
            $table->addColumn('mailbox_server', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->addColumn('mailbox_port', Types::STRING, ['notnull' => false, 'length' => 10, 'default' => null]);
            $table->addColumn('mailbox_user', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->addColumn('mailbox_password', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->addColumn('mailbox_use_ssl', Types::BOOLEAN, ['notnull' => false, 'default' => null]);
            $table->addColumn('file_extension_filter', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['type'], 'oc_imploc_type');
        }

        // ── opencase_importitems ──────────────────────────────────────────
        if (!$schema->hasTable('opencase_importitems')) {
            $table = $schema->createTable('opencase_importitems');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('importlocations_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('identification', Types::STRING, ['notnull' => false, 'length' => 1000, 'default' => null]);
            $table->addColumn('status', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => null]);
            $table->addColumn('document_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            // XML document recording what ProcessImportsJob found/created for
            // the item: which separation sheet(s) were recognized, the
            // resulting case/document/file ids, and page counts.
            $table->addColumn('file_stats', Types::TEXT, ['notnull' => false, 'default' => null]);
            // The time ProcessImportsJob finished importing the item (status set to "Processed").
            $table->addColumn('imported_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['importlocations_id'], 'oc_impitem_loc_id');
        }

        // ── opencase_separation_sheets ─────────────────────────────────────
        if (!$schema->hasTable('opencase_separation_sheets')) {
            $table = $schema->createTable('opencase_separation_sheets');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 32,
                'comment' => 'existing case | new case | inbox case | attachment']);
            $table->addColumn('name', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addColumn('case_number', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addColumn('title', Types::STRING, ['notnull' => false, 'length' => 512, 'default' => null]);
            $table->addColumn('org_uuid', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addColumn('class_subject_uuid', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addColumn('sensitivity_uuid', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addColumn('classification_facet_uuid', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addColumn('insight_level_id', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            $table->addColumn('responsible_user_id', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['type'], 'oc_sepsheet_type');
            $table->addUniqueIndex(['name'], 'oc_sepsheet_name');
        }

        return $schema;
    }
}
