<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Brevdesigner phase 1.1 — phrase library:
 *
 * - opencase_phrase_categories — hierarchical categories (parent_id) for phrases.
 * - opencase_phrases           — a phrase is a formatted text fragment stored as a
 *   document file under {storage_base_dir}/_phrases/{storage_filename}, like
 *   templates under _templates/. nc_file_id/nc_owner point at the live copy in a
 *   user's Nextcloud home while it is being edited in Nextcloud Office.
 * - opencase_template_phrases  — phrases attached to a template (opencase_templates).
 * - opencase_phrases_scope     — restricts a phrase to casetype(s), organisation(s)
 *   or KLE pattern(s), like opencase_customfield_scope.
 */
class Version000012Date20261023000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── opencase_phrase_categories ──────────────────────────────────────
        if (!$schema->hasTable('opencase_phrase_categories')) {
            $table = $schema->createTable('opencase_phrase_categories');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('description', Types::TEXT, ['notnull' => false]);
            $table->addColumn('parent_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            $table->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['parent_id'], 'oc_phrcat_parent');
        }

        // ── opencase_phrases ────────────────────────────────────────────────
        if (!$schema->hasTable('opencase_phrases')) {
            $table = $schema->createTable('opencase_phrases');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('category_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('description', Types::TEXT, ['notnull' => false]);
            // File columns are empty until a file is uploaded or created blank.
            $table->addColumn('original_filename', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->addColumn('storage_filename', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null,
                'comment' => 'Random filename under _phrases/']);
            $table->addColumn('mime_type', Types::STRING, ['notnull' => false, 'length' => 128, 'default' => null]);
            $table->addColumn('size', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
            $table->addColumn('nc_file_id', Types::BIGINT, ['notnull' => false, 'default' => null]);
            $table->addColumn('nc_owner', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'draft',
                'comment' => 'draft | active | archived']);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['category_id'], 'oc_phrase_category');
            $table->addIndex(['status'], 'oc_phrase_status');
        }

        // ── opencase_template_phrases ───────────────────────────────────────
        if (!$schema->hasTable('opencase_template_phrases')) {
            $table = $schema->createTable('opencase_template_phrases');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('template_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('phrase_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('is_default', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
            $table->addColumn('is_required', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['template_id', 'phrase_id'], 'oc_tplphr_tpl_phrase');
            $table->addIndex(['phrase_id'], 'oc_tplphr_phrase');
        }

        // ── opencase_phrases_scope ──────────────────────────────────────────
        if (!$schema->hasTable('opencase_phrases_scope')) {
            $table = $schema->createTable('opencase_phrases_scope');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('phrases_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('scope_type', Types::STRING, ['notnull' => true, 'length' => 20,
                'comment' => 'casetype | organisation | kle']);
            // casetype id (string), org_uuid, or a KLE pattern such as "27.%".
            $table->addColumn('scope_value', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['phrases_id'], 'oc_phrscope_phrase');
            $table->addIndex(['scope_type', 'scope_value'], 'oc_phrscope_type_val');
        }

        return $schema;
    }
}
