<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Complete OpenCase database schema — merged from all previous migrations.
 *
 * Creates all tables in their final form and seeds all reference data.
 *
 * Tables:
 *   opencase_org                — organisations (UUID-keyed)
 *   opencase_class_subject      — KLE classification subjects
 *   opencase_sensitivity        — sensitivity levels
 *   opencase_access_profiles    — (org, classification, sensitivity) combos
 *   opencase_user_access        — user ↔ access profile mapping
 *   opencase_casestatus         — case status reference (multilingual)
 *   opencase_cases              — cases
 *   opencase_documentstatus     — document status reference (multilingual)
 *   opencase_documents          — documents (belong to a case)
 *   opencase_files              — files (belong to a document)
 *   opencase_doc_user_access    — per-user document permission overrides
 *   opencase_fileversions       — file version snapshots
 *   opencase_audit_log          — case/document/file activity log
 *   opencase_roles              — role definitions
 *   opencase_user_roles         — user ↔ role mapping
 *   opencase_config             — key-value configuration
 *   opencase_sequences          — per-year case number sequence counters
 *   opencase_doc_sequences      — per-case document number sequence counters
 *   opencase_case_users         — per-case user access grants
 *   opencase_file_shares        — per-file shares with external users
 *   opencase_templates          — document template metadata
 */
class Version000001Date20260329000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── opencase_org ──────────────────────────────────────────────────
        if (!$schema->hasTable('opencase_org')) {
            $table = $schema->createTable('opencase_org');
            $table->addColumn('org_uuid', Types::STRING, ['length' => 64, 'notnull' => true]);
            $table->addColumn('org_name', Types::STRING, ['length' => 255, 'notnull' => true]);
            $table->addColumn('org_parent_uuid', Types::STRING, ['length' => 64, 'notnull' => true, 'default' => '']);
            $table->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
            $table->setPrimaryKey(['org_uuid']);
            $table->addUniqueIndex(['org_name'], 'oc_org_name_unique');
        }

        // ── opencase_class_subject ────────────────────────────────────────
        if (!$schema->hasTable('opencase_class_subject')) {
            $table = $schema->createTable('opencase_class_subject');
            $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('code', Types::STRING, ['notnull' => true, 'length' => 255, 'comment' => 'KLE classification code, e.g. 27.69.00']);
            $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('description', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
            $table->setPrimaryKey(['uuid']);
            $table->addUniqueIndex(['code'], 'oc_cs_code');
            $table->addIndex(['active'], 'oc_cs_active');
        }

        // ── opencase_sensitivity ──────────────────────────────────────────
        if (!$schema->hasTable('opencase_sensitivity')) {
            $table = $schema->createTable('opencase_sensitivity');
            $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('key', Types::STRING, ['notnull' => true, 'length' => 255, 'comment' => 'Machine-readable sensitivity key']);
            $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('description', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
            $table->setPrimaryKey(['uuid']);
            $table->addUniqueIndex(['key'], 'oc_sens_key');
            $table->addIndex(['active'], 'oc_sens_active');
        }

        // ── opencase_access_profiles ──────────────────────────────────────
        if (!$schema->hasTable('opencase_access_profiles')) {
            $table = $schema->createTable('opencase_access_profiles');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('org_uuid', Types::STRING, ['length' => 64, 'notnull' => false, 'default' => null]);
            $table->addColumn('class_subject_uuid', Types::STRING, ['length' => 64, 'notnull' => false, 'default' => null]);
            $table->addColumn('sensitivity_uuid', Types::STRING, ['length' => 64, 'notnull' => false, 'default' => null]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['org_uuid'], 'oc_ap_org_uuid');
            $table->addUniqueIndex(['org_uuid', 'class_subject_uuid', 'sensitivity_uuid'], 'oc_ap_org_uuid_cs_su');
        }

        // ── opencase_user_access ──────────────────────────────────────────
        if (!$schema->hasTable('opencase_user_access')) {
            $table = $schema->createTable('opencase_user_access');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('access_profile_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('access_level', Types::STRING, ['notnull' => true, 'length' => 16, 'comment' => 'read or write']);
            $table->addColumn('granted_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('granted_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_id', 'access_profile_id'], 'oc_ua_user_profile');
            $table->addIndex(['user_id'], 'oc_ua_user');
            $table->addIndex(['access_profile_id'], 'oc_ua_profile');
        }

        // ── opencase_casestatus ───────────────────────────────────────────
        if (!$schema->hasTable('opencase_casestatus')) {
            $table = $schema->createTable('opencase_casestatus');
            $table->addColumn('id', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('language', Types::STRING, ['notnull' => true, 'length' => 10]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('is_closed', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('expired', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->setPrimaryKey(['id', 'language']);
        }

        // ── opencase_cases ────────────────────────────────────────────────
        if (!$schema->hasTable('opencase_cases')) {
            $table = $schema->createTable('opencase_cases');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('case_number', Types::STRING, ['notnull' => true, 'length' => 64, 'comment' => 'Human-readable case number, e.g. 2024-123456']);
            $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 512]);
            $table->addColumn('access_profile_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('org_uuid', Types::STRING, ['length' => 64, 'notnull' => false, 'default' => null]);
            $table->addColumn('year', Types::STRING, ['notnull' => true, 'length' => 4]);
            $table->addColumn('status_id', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => 1]);
            $table->addColumn('responsible_user_id', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['case_number'], 'oc_case_number');
            $table->addIndex(['access_profile_id'], 'oc_case_ap');
            $table->addIndex(['org_uuid', 'year'], 'oc_case_org_uuid_year');
            $table->addIndex(['status_id'], 'oc_cases_status_id');
            $table->addIndex(['responsible_user_id', 'created_at'], 'oc_idx_cases_resp_date');
        }

        // ── opencase_documentstatus ───────────────────────────────────────
        if (!$schema->hasTable('opencase_documentstatus')) {
            $table = $schema->createTable('opencase_documentstatus');
            $table->addColumn('id', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('language', Types::STRING, ['notnull' => true, 'length' => 10]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('is_final', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('expired', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->setPrimaryKey(['id', 'language']);
        }

        // ── opencase_documents ────────────────────────────────────────────
        if (!$schema->hasTable('opencase_documents')) {
            $table = $schema->createTable('opencase_documents');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('case_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 512]);
            $table->addColumn('document_type', Types::STRING, ['notnull' => false, 'length' => 128, 'comment' => 'e.g. afgørelse, notat, brev, bilag']);
            $table->addColumn('status', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
            $table->addColumn('document_number', Types::STRING, ['notnull' => false, 'length' => 128, 'default' => null]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['case_id'], 'oc_doc_case');
        }

        // ── opencase_files ────────────────────────────────────────────────
        if (!$schema->hasTable('opencase_files')) {
            $table = $schema->createTable('opencase_files');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('document_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('case_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'comment' => 'Denormalized for efficient queries']);
            $table->addColumn('original_filename', Types::STRING, ['notnull' => true, 'length' => 512]);
            $table->addColumn('storage_filename', Types::STRING, ['notnull' => true, 'length' => 255, 'comment' => 'UUID-based filename on physical storage']);
            $table->addColumn('virtual_filename', Types::STRING, ['notnull' => true, 'length' => 512, 'comment' => 'Filename shown in the virtual filesystem']);
            $table->addColumn('mime_type', Types::STRING, ['notnull' => true, 'length' => 128]);
            $table->addColumn('size', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
            $table->addColumn('version', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
            $table->addColumn('checksum', Types::STRING, ['notnull' => false, 'length' => 128, 'comment' => 'SHA-256 hash of file content']);
            $table->addColumn('last_modified_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null, 'comment' => 'NC user ID of whoever last wrote the current file content']);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['document_id'], 'oc_file_doc');
            $table->addIndex(['case_id'], 'oc_file_case');
            $table->addIndex(['storage_filename'], 'oc_file_storage');
        }

        // ── opencase_doc_user_access ──────────────────────────────────────
        if (!$schema->hasTable('opencase_doc_user_access')) {
            $table = $schema->createTable('opencase_doc_user_access');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('document_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('access_level', Types::STRING, ['notnull' => true, 'length' => 16, 'comment' => 'read, write, or deny']);
            $table->addColumn('granted_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('granted_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['document_id', 'user_id'], 'oc_dua_doc_user');
            $table->addIndex(['user_id'], 'oc_dua_user');
        }

        // ── opencase_fileversions ─────────────────────────────────────────
        if (!$schema->hasTable('opencase_fileversions')) {
            $table = $schema->createTable('opencase_fileversions');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'comment' => 'FK to opencase_files.id']);
            $table->addColumn('timestamp', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'comment' => 'Unix timestamp when the snapshot was taken']);
            $table->addColumn('size', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
            $table->addColumn('mime_type', Types::STRING, ['notnull' => true, 'length' => 128]);
            $table->addColumn('checksum', Types::STRING, ['notnull' => false, 'length' => 128]);
            $table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['file_id'], 'oc_fver_file_id');
            $table->addUniqueIndex(['file_id', 'timestamp'], 'oc_fver_file_ts');
        }

        // ── opencase_audit_log ────────────────────────────────────────────
        if (!$schema->hasTable('opencase_audit_log')) {
            $table = $schema->createTable('opencase_audit_log');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('case_id', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('event_type', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('details', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('document_id', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            $table->addColumn('file_id', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['case_id', 'created_at'], 'opencase_audit_case_time');
            $table->addIndex(['document_id'], 'opencase_audit_doc');
            $table->addIndex(['file_id'], 'opencase_audit_file');
        }

        // ── opencase_roles ────────────────────────────────────────────────
        if (!$schema->hasTable('opencase_roles')) {
            $table = $schema->createTable('opencase_roles');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['name'], 'opencase_roles_name');
        }

        // ── opencase_user_roles ───────────────────────────────────────────
        if (!$schema->hasTable('opencase_user_roles')) {
            $table = $schema->createTable('opencase_user_roles');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('role_id', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('assigned_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('assigned_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_id', 'role_id'], 'opencase_ur_user_role');
            $table->addIndex(['user_id'], 'opencase_ur_user');
            $table->addIndex(['role_id'], 'opencase_ur_role');
        }

        // ── opencase_config ───────────────────────────────────────────────
        if (!$schema->hasTable('opencase_config')) {
            $table = $schema->createTable('opencase_config');
            $table->addColumn('config_key', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('config_value', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('name', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->addColumn('description', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->setPrimaryKey(['config_key']);
        }

        // ── opencase_sequences ────────────────────────────────────────────
        if (!$schema->hasTable('opencase_sequences')) {
            $table = $schema->createTable('opencase_sequences');
            $table->addColumn('year', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'length' => 4]);
            $table->addColumn('next_seq', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
            $table->setPrimaryKey(['year']);
        }

        // ── opencase_doc_sequences ────────────────────────────────────────
        if (!$schema->hasTable('opencase_doc_sequences')) {
            $table = $schema->createTable('opencase_doc_sequences');
            $table->addColumn('case_id', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('next_seq', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
            $table->setPrimaryKey(['case_id']);
        }

        // ── opencase_case_users ───────────────────────────────────────────
        if (!$schema->hasTable('opencase_case_users')) {
            $table = $schema->createTable('opencase_case_users');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('case_id', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('can_write', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('granted_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('granted_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('expires_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['case_id', 'user_id'], 'oc_uniq_case_user');
            $table->addIndex(['user_id'], 'oc_idx_case_users_uid');
            $table->addIndex(['expires_at'], 'oc_idx_case_users_exp');
        }

        // ── opencase_file_shares ──────────────────────────────────────────
        if (!$schema->hasTable('opencase_file_shares')) {
            $table = $schema->createTable('opencase_file_shares');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'comment' => 'FK to opencase_files.id']);
            $table->addColumn('owner_id', Types::STRING, ['notnull' => true, 'length' => 64, 'comment' => 'NC user ID of who created the share']);
            $table->addColumn('shared_with', Types::STRING, ['notnull' => true, 'length' => 64, 'comment' => 'NC user ID of the share recipient']);
            $table->addColumn('permissions', Types::SMALLINT, ['notnull' => true, 'default' => 1, 'comment' => '1 = read, 3 = read+write (NC permission constants)']);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('expires_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->addColumn('note', Types::STRING, ['notnull' => false, 'length' => 512, 'default' => null]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['file_id', 'shared_with'], 'oc_fshare_file_user');
            $table->addIndex(['shared_with'], 'oc_fshare_recipient');
            $table->addIndex(['file_id'], 'oc_fshare_file');
        }

        // ── opencase_templates ────────────────────────────────────────────
        if (!$schema->hasTable('opencase_templates')) {
            $table = $schema->createTable('opencase_templates');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255, 'comment' => 'Display name for the template']);
            $table->addColumn('original_filename', Types::STRING, ['notnull' => true, 'length' => 255, 'comment' => 'Original uploaded filename']);
            $table->addColumn('storage_filename', Types::STRING, ['notnull' => true, 'length' => 255, 'comment' => 'UUID-based filename on disk']);
            $table->addColumn('mime_type', Types::STRING, ['notnull' => true, 'length' => 128]);
            $table->addColumn('size', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
            $table->addColumn('uploaded_by', Types::STRING, ['notnull' => true, 'length' => 64, 'comment' => 'NC user ID of the uploader']);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['storage_filename'], 'oc_tmpl_storage_filename');
            $table->addIndex(['uploaded_by'], 'oc_tmpl_uploaded_by');
        }

        return $schema;
    }

}
// Seed data is handled by SeedReferenceData (IRepairStep) registered as an
// install repair step in appinfo/info.xml — see lib/Migration/SeedReferenceData.php.
