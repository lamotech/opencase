<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Automatic masking of access-request files.
 *
 * Tables:
 *   opencase_access_mask_suggestions — areas in a file that the redaction
 *   service (or the caseworker) proposes to mask. One row per distinct text on
 *   a page; `rects` holds every occurrence as a JSON list of [x0, y0, x1, y1]
 *   in PDF points. `status` is suggested | accepted | rejected; only accepted
 *   rows are burned into the masked file. `source` is rule (found by a masking
 *   rule), manual (a term the caseworker searched for) or ai. `label` and
 *   `legal_reference` are the rule's name and legal basis at the time of the
 *   analysis, shown to the caseworker.
 *
 *   opencase_access_mask_rules — the rules the redaction service runs when it
 *   analyzes a file, edited under Konfiguration → Maskeringsregler. `kind` is
 *   regex (`pattern` is a Python regular expression) or terms (`pattern` is a
 *   newline-separated word list). `validator` adds a built-in check to a regex
 *   match: cpr_date | not_date | null. Rules run in `sort_order`; a match that
 *   overlaps an earlier rule's match is skipped. The default rules (CPR,
 *   e-mail, account and phone numbers) are seeded by SeedReferenceData.
 */
class Version000008Date20261007000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── opencase_access_mask_suggestions ────────────────────────────────
        if (!$schema->hasTable('opencase_access_mask_suggestions')) {
            $table = $schema->createTable('opencase_access_mask_suggestions');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('request_item_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('page', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('category', Types::STRING, ['notnull' => true, 'length' => 32]);
            $table->addColumn('label', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->addColumn('legal_reference', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->addColumn('text', Types::TEXT, ['notnull' => true]);
            $table->addColumn('context', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('rects', Types::TEXT, ['notnull' => true]);
            $table->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'rule']);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'suggested']);
            $table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['request_item_id', 'file_id'], 'oc_amask_item_file');
        }

        // ── opencase_access_mask_rules ──────────────────────────────────────
        if (!$schema->hasTable('opencase_access_mask_rules')) {
            $table = $schema->createTable('opencase_access_mask_rules');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('category', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'custom']);
            $table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'regex']);
            $table->addColumn('pattern', Types::TEXT, ['notnull' => true]);
            $table->addColumn('validator', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => null]);
            $table->addColumn('legal_reference', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->addColumn('enabled', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
            $table->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->setPrimaryKey(['id']);
        }

        return $schema;
    }
}
