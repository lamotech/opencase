<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * - opencase_cases/opencase_documents/opencase_journalnotes.updated_by — the
 *   user who last updated the row. Existing rows are backfilled with created_by.
 * - opencase_documents.summary — document summary as rich-text HTML.
 * - opencase_sensitivity.enumeration — the SagDokumentIndeks Foelsomhed
 *   enumeration value (e.g. IKKE_FORTROLIGE_DATA) for each sensitivity level.
 *   Values are filled in by SeedReferenceData::seedSensitivities().
 * - opencase_cases.updated_in_sapa / opencase_documents.updated_in_sapa — when
 *   the case/document was last sent to SagDokumentIndeks (SAPA).
 * - opencase_casedocindex_def / opencase_casedocindex_scope — rules for which
 *   cases are transferred to SagDokumentIndeks (SAPA), scoped like custom
 *   fields (casetype | organisation | kle).
 * - opencase_casedocindex_log — one row per SagDokumentIndeks
 *   create/update/delete attempt; document_uuid is set for document calls.
 */
class Version000011Date20261010000000 extends SimpleMigrationStep {

    public function __construct(private IDBConnection $db) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $cases = $schema->getTable('opencase_cases');
        if (!$cases->hasColumn('updated_by')) {
            $cases->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        }
        if (!$cases->hasColumn('updated_in_sapa')) {
            $cases->addColumn('updated_in_sapa', Types::DATETIME, ['notnull' => false]);
        }

        $documents = $schema->getTable('opencase_documents');
        if (!$documents->hasColumn('updated_by')) {
            $documents->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        }
        if (!$documents->hasColumn('updated_in_sapa')) {
            $documents->addColumn('updated_in_sapa', Types::DATETIME, ['notnull' => false]);
        }
        if (!$documents->hasColumn('summary')) {
            $documents->addColumn('summary', Types::TEXT, ['notnull' => false]);
        }

        $journalNotes = $schema->getTable('opencase_journalnotes');
        if (!$journalNotes->hasColumn('updated_by')) {
            $journalNotes->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        }

        $sensitivity = $schema->getTable('opencase_sensitivity');
        if (!$sensitivity->hasColumn('enumeration')) {
            $sensitivity->addColumn('enumeration', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        }

        if (!$schema->hasTable('opencase_casedocindex_log')) {
            $table = $schema->createTable('opencase_casedocindex_log');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('case_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
            $table->addColumn('document_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('err_message', Types::TEXT, ['notnull' => false]);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['case_uuid'], 'oc_casedocidx_log_case');
        } else {
            $table = $schema->getTable('opencase_casedocindex_log');
            if (!$table->hasColumn('document_uuid')) {
                $table->addColumn('document_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
            }
        }

        if (!$schema->hasTable('opencase_casedocindex_def')) {
            $table = $schema->createTable('opencase_casedocindex_def');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->setPrimaryKey(['id']);
        }

        if (!$schema->hasTable('opencase_casedocindex_scope')) {
            $table = $schema->createTable('opencase_casedocindex_scope');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('casedocindex_def_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('scope_type', Types::STRING, ['notnull' => true, 'length' => 20,
                'comment' => 'casetype | organisation | kle']);
            // casetype id (string), org_uuid, or a KLE pattern such as "27.%".
            $table->addColumn('scope_value', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['casedocindex_def_id'], 'oc_cdiscope_def');
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        foreach (['opencase_cases', 'opencase_documents', 'opencase_journalnotes'] as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->update($table)
                ->set('updated_by', 'created_by')
                ->where($qb->expr()->orX(
                    $qb->expr()->isNull('updated_by'),
                    $qb->expr()->eq('updated_by', $qb->createNamedParameter(''))
                ))
                ->executeStatement();
        }
    }
}
