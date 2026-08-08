<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Complete OpenCase database schema — merged from all previous migrations
 * (formerly Version000001 .. Version000053, with Version000002 and
 * Version000003 folded in afterwards). All operations are additive
 * (no table/column was ever dropped or renamed), so a fresh install ends up
 * in exactly the same state as one that ran every historical migration in
 * sequence.
 *
 * Config-key seeding and the access-exclusion-reasons lookup data live in
 * postSchemaChange(), which Nextcloud skips on a fresh install — same
 * behaviour as before the merge. General reference/lookup data (statuses,
 * sensitivities, roles, case_number_mask, contact types, CPR events, …) is
 * seeded by SeedReferenceData, an IRepairStep registered in appinfo/info.xml
 * that runs on both fresh installs and upgrades.
 *
 * Tables:
 *   opencase_org                      — organisations (UUID-keyed)
 *   opencase_class_subject            — KLE classification subjects
 *   opencase_class_facet               — KLE facets
 *   opencase_sensitivity               — sensitivity levels
 *   opencase_access_profiles           — (org, classification, sensitivity) combos
 *   opencase_user_access                — user ↔ access profile mapping
 *   opencase_user_priv_groups           — raw privilege groups parsed from SAML token
 *   opencase_user_priv_match            — pre-expanded (user, sensitivity, kle, org) tuples
 *   opencase_casestatus                — case status reference (multilingual)
 *   opencase_casetype                   — case type reference (multilingual)
 *   opencase_cases                      — cases
 *   opencase_documentstatus             — document status reference (multilingual)
 *   opencase_documentcategory           — document category reference (multilingual)
 *   opencase_documenttype               — document type reference (not multilingual; customer-maintained)
 *   opencase_documents                  — documents (belong to a case)
 *   opencase_files                      — files (belong to a document)
 *   opencase_doc_user_access            — per-user document permission overrides
 *   opencase_fileversions               — file version snapshots
 *   opencase_audit_log                  — case/document/file activity log
 *   opencase_roles                      — role definitions
 *   opencase_user_roles                 — user ↔ role mapping
 *   opencase_config                     — key-value configuration
 *   opencase_sequences                  — per-year case number sequence counters
 *   opencase_doc_sequences              — per-case document number sequence counters
 *   opencase_case_users                 — per-case user access grants
 *   opencase_file_shares                — per-file shares with external users
 *   opencase_templates                  — document template metadata
 *   opencase_certificate                — signing certificates
 *   opencase_org_log                    — org sync run log
 *   opencase_klass_log                  — KLE sync run log
 *   opencase_userinfo                   — cached user info from IdP
 *   opencase_userorgs                   — user ↔ org membership
 *   opencase_contactroles               — document contact role reference (multilingual)
 *   opencase_documentcontacts           — contact persons/organisations linked to a document
 *   opencase_participantroles           — case participant role reference (multilingual)
 *   opencase_caseparticipants           — persons/organisations linked to a case
 *   opencase_contacttype                — contact type reference (multilingual)
 *   opencase_caseworkers                — additional caseworkers linked to a case
 *   opencase_api_client                 — mTLS client certificate registry
 *   opencase_messagesreceived           — Beskedfordeler inbox
 *   opencase_journalnotes               — case journal notes
 *   opencase_documentnotes              — document notes
 *   opencase_dp_shipment                — Digital Post shipments
 *   opencase_dp_files                   — Digital Post shipment files
 *   opencase_dp_receivers                — Digital Post shipment receivers
 *   opencase_dp_receivements             — Digital Post receivements
 *   opencase_dist_receivements           — Fordelingskomponent receivements
 *   opencase_favorites                   — per-user favourited cases/documents
 *   opencase_aiprompts                   — AI prompt library
 *   opencase_aiactions                   — actions attached to an AI prompt
 *   opencase_transaction_log             — user transaction log
 *   opencase_doc_workflows               — document review/approval workflows
 *   opencase_doc_workflow_steps          — steps within a document workflow
 *   opencase_access_requests             — aktindsigt/GDPR access requests
 *   opencase_access_request_items        — items within an access request
 *   opencase_access_redactions           — redactions applied to a request item
 *   opencase_access_decisions            — decision issued for an access request
 *   opencase_access_exclusion_reasons    — reference list of exclusion reasons
 *   opencase_reminders                   — deadline reminders
 *   opencase_cprevents                   — CPR event code reference
 *   opencase_export_log                  — closed-case export run log
 *   opencase_history                     — per-user recently accessed cases/documents
 */
class Version000001Date20260729000000 extends SimpleMigrationStep {

    public function __construct(
        private IDBConnection $db,
        private IConfig $config,
    ) {}

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
            $table->addColumn('email', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->addColumn('phone', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addColumn('location', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->addColumn('nc_group_id', Types::STRING, ['notnull' => false, 'length' => 128, 'default' => null]);
            $table->addColumn('groupfolder_id', Types::INTEGER, ['notnull' => false, 'default' => null]);
            $table->setPrimaryKey(['org_uuid']);
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

        // ── opencase_class_facet ──────────────────────────────────────────
        if (!$schema->hasTable('opencase_class_facet')) {
            $table = $schema->createTable('opencase_class_facet');
            $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('code', Types::STRING, ['notnull' => true, 'length' => 255, 'comment' => 'KLE facet code, e.g. G01']);
            $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('description', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
            $table->setPrimaryKey(['uuid']);
            $table->addUniqueIndex(['code'], 'oc_cf_code');
            $table->addIndex(['active'], 'oc_cf_active');
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
            $table->addUniqueIndex(['org_uuid', 'class_subject_uuid', 'sensitivity_uuid'], 'oc_ap_org_uuid_cs_su');
            // opencase_recalc_privileges() joins profiles on sensitivity_uuid; also
            // serves AccessProfileMapper::getProfileIdsBySensitivityUuid.
            $table->addIndex(['sensitivity_uuid', 'org_uuid', 'class_subject_uuid'], 'oc_ap_sens');
            // AccessProfileMapper::getProfileIdsByClassSubjectUuid.
            $table->addIndex(['class_subject_uuid'], 'oc_ap_cs');
        }

        // ── opencase_user_access ────────────────────────────────────────��─
        if (!$schema->hasTable('opencase_user_access')) {
            $table = $schema->createTable('opencase_user_access');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('access_profile_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('access_level', Types::STRING, ['notnull' => true, 'length' => 16, 'comment' => 'read or write']);
            $table->addColumn('granted_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('granted_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->addColumn('grant_source', Types::STRING, [
                'notnull' => true,
                'length'  => 16,
                'default' => 'manual',
                'comment' => 'privilege = auto-synced from SAML token; manual = admin-assigned',
            ]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_id', 'access_profile_id'], 'oc_ua_user_profile');
            $table->addIndex(['access_profile_id'], 'oc_ua_profile');
            $table->addIndex(['grant_source'], 'oc_ua_grant_source');
            // Covering index for UserAccessMapper::getWriteProfileIdsForUser.
            $table->addIndex(['user_id', 'access_level', 'access_profile_id'], 'oc_ua_user_lvl');
        }

        // ── opencase_user_priv_groups ───────────────────────────────────────
        // Raw PrivilegeGroup elements from the SAML token. One row per
        // privilege group per user (a user can have multiple opencaseuser
        // groups with different constraint combinations). Non-opencaseuser
        // rows (admin, templateadmin, sysadmin) have NULL constraints.
        if (!$schema->hasTable('opencase_user_priv_groups')) {
            $table = $schema->createTable('opencase_user_priv_groups');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64,
                'comment' => 'NC user ID (opencase_<uuid>)']);
            $table->addColumn('privilege_type', Types::STRING, ['notnull' => true, 'length' => 64,
                'comment' => 'systemadministrator | opencaseadministrator | opencasetemplateadministrator | opencaseuser']);
            $table->addColumn('foelsomhed_raw', Types::TEXT, ['notnull' => false, 'default' => null,
                'comment' => 'Comma-separated sensitivity UUIDs from foelsomhed constraint']);
            $table->addColumn('kle_raw', Types::TEXT, ['notnull' => false, 'default' => null,
                'comment' => 'Comma-separated KLE patterns (may contain wildcards, e.g. 00.*)']);
            $table->addColumn('orgenhed_raw', Types::TEXT, ['notnull' => false, 'default' => null,
                'comment' => 'Comma-separated org UUIDs; NULL means no org constraint (all orgs)']);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'oc_upg_user_id');
        }

        // ── opencase_user_priv_match ─────────────────────────────────────────
        // Pre-expanded (user, sensitivity, kle_pattern, org) tuples used for
        // efficient access-profile matching. kle_pattern is stored in SQL
        // LIKE syntax; org_uuid = '' means "no org constraint" (matches any
        // org). See individual patch comments in git history for the
        // matching/rebuild queries this table supports.
        if (!$schema->hasTable('opencase_user_priv_match')) {
            $table = $schema->createTable('opencase_user_priv_match');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('sensitivity_uuid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('kle_pattern', Types::STRING, ['notnull' => true, 'length' => 255,
                'comment' => "SQL LIKE pattern, e.g. '27.%', '27.25.00', '%'"]);
            $table->addColumn('org_uuid', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '',
                'comment' => "Specific org UUID, or '' for any org"]);
            $table->addColumn('access_level', Types::STRING, [
                'notnull' => true,
                'length'  => 8,
                'default' => 'write',
                'comment' => 'read | write — derived from privilege_type of the source group',
            ]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(
                ['user_id', 'sensitivity_uuid', 'kle_pattern', 'org_uuid'],
                'oc_upm_unique'
            );
            $table->addIndex(
                ['kle_pattern', 'sensitivity_uuid', 'org_uuid'],
                'oc_upm_profile_match'
            );
        }

        // ── opencase_casestatus ───────────────────────────────────────────
        if (!$schema->hasTable('opencase_casestatus')) {
            $table = $schema->createTable('opencase_casestatus');
            $table->addColumn('id', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('language', Types::STRING, ['notnull' => true, 'length' => 10]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('is_closed', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('expired', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->setPrimaryKey(['id', 'language']);
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
            $table->addColumn('insight_level_id', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            $table->addColumn('classification_facet_uuid', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addColumn('uuid', Types::STRING, ['length' => 36, 'notnull' => false, 'default' => null]);
            $table->addColumn('is_inbox', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('parent_case_id', Types::BIGINT, ['notnull' => false, 'default' => null, 'unsigned' => true]);
            $table->addColumn('casetype_id', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => 1]);
            $table->addColumn('exported_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            // Rich text ("Sagsresume") authored in the TipTap editor on the new-case
            // dialog and stored as HTML, so it uses TEXT (LONGTEXT on MySQL) rather
            // than a bounded VARCHAR — same as opencase_journalnotes.text.
            $table->addColumn('summary', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['case_number'], 'oc_case_number');
            $table->addIndex(['access_profile_id'], 'oc_case_ap');
            $table->addIndex(['org_uuid', 'year'], 'oc_case_org_uuid_year');
            $table->addIndex(['status_id'], 'oc_cases_status_id');
            $table->addIndex(['responsible_user_id', 'created_at'], 'oc_idx_cases_resp_date');
            $table->addIndex(['uuid'], 'oc_opencase_cases_uuid');
            $table->addIndex(['org_uuid', 'is_inbox'], 'oc_opencase_cases_org_inbox');
            $table->addIndex(['casetype_id'], 'oc_cases_casetype_id');
            $table->addIndex(['status_id', 'exported_at'], 'oc_cases_status_exported');
            // CaseMapper::findByParentId.
            $table->addIndex(['parent_case_id'], 'oc_case_parent');
            // CaseMapper::findResponsibleNotClosed — includes the sort column so the
            // dashboard query does not filesort a caseworker's whole open caseload.
            $table->addIndex(['responsible_user_id', 'status_id', 'updated_at'], 'oc_case_resp_status_upd');
            // Virtual-filesystem date tree. CaseMapper filters created_at with
            // half-open ranges (never DATE_FORMAT), so this index is usable.
            $table->addIndex(['is_inbox', 'created_at'], 'oc_case_inbox_created');
        }

        // ── opencase_documentstatus ───────────────────────────────────────
        if (!$schema->hasTable('opencase_documentstatus')) {
            $table = $schema->createTable('opencase_documentstatus');
            $table->addColumn('id', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('language', Types::STRING, ['notnull' => true, 'length' => 10]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('is_final', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('expired', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->setPrimaryKey(['id', 'language']);
        }

        // ── opencase_documentcategory ─────────────────────────────────────
        if (!$schema->hasTable('opencase_documentcategory')) {
            $table = $schema->createTable('opencase_documentcategory');
            $table->addColumn('id', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('language', Types::STRING, ['notnull' => true, 'length' => 10]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addColumn('expired', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->setPrimaryKey(['id', 'language']);
        }

        // ── opencase_documenttype ─────────────────────────────────────────
        // Unlike the other lookup tables this one is not multilingual: the
        // names are maintained by the customer, so there is a single row per
        // type plus the usual updated_at/updated_by audit columns. Rows with
        // is_system = 1 are reserved for types the system assigns itself
        // (Digital post, Fordeling, Email, Scanning) and are never offered to
        // the user in the UI; is_system = 0 rows are the user-selectable
        // types. The rows themselves are seeded by SeedReferenceData so that
        // both fresh installs and upgrades get them.
        if (!$schema->hasTable('opencase_documenttype')) {
            $table = $schema->createTable('opencase_documenttype');
            $table->addColumn('id', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addColumn('is_system', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('expired', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->setPrimaryKey(['id']);
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
            $table->addColumn('insight_level_id', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            $table->addColumn('document_date', Types::STRING, ['notnull' => false, 'length' => 10, 'default' => null]);
            $table->addColumn('received_date', Types::STRING, ['notnull' => false, 'length' => 10, 'default' => null]);
            $table->addColumn('registered_date', Types::STRING, ['notnull' => false, 'length' => 10, 'default' => null]);
            $table->addColumn('document_category_id', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            $table->addColumn('uuid', Types::STRING, ['length' => 36, 'notnull' => false, 'default' => null]);
            $table->setPrimaryKey(['id']);
            // Kept alongside oc_doc_case_upd: InnoDB appends the primary key to a
            // secondary index, so (case_id) acts as (case_id, id) and serves
            // DocumentMapper::findByCase()'s ORDER BY id DESC without a filesort.
            $table->addIndex(['case_id'], 'oc_doc_case');
            $table->addIndex(['uuid'], 'oc_opencase_docs_uuid');
            // DocumentMapper::findByDocumentNumber.
            $table->addIndex(['document_number'], 'oc_doc_number');
            // DocumentMapper::findRecentlyUpdated — case_id IN (…) ORDER BY updated_at.
            $table->addIndex(['case_id', 'updated_at'], 'oc_doc_case_upd');
            // findByFields/countByFields optional filters.
            $table->addIndex(['document_date'], 'oc_doc_date');
            $table->addIndex(['created_by'], 'oc_doc_creator');
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
            $table->addColumn('uuid', Types::STRING, ['length' => 36, 'notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['document_id'], 'oc_file_doc');
            $table->addIndex(['storage_filename'], 'oc_file_storage');
            $table->addIndex(['uuid'], 'oc_opencase_files_uuid');
            // The hottest lookup in the virtual filesystem: findByVirtualName,
            // findByVirtualNameNoAuth, virtualFilenameExists,
            // PermissionService::getFileIdByCaseAndFilename. Leading on case_id, it
            // also covers every plain case_id lookup and gives findByCaseId its
            // ORDER BY virtual_filename for free.
            $table->addIndex(['case_id', 'virtual_filename'], 'oc_file_case_vname');
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
            $table->addColumn('uuid', Types::STRING, ['length' => 36, 'notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['file_id', 'timestamp'], 'oc_fver_file_ts');
            $table->addIndex(['uuid'], 'oc_opencase_fileversions_uuid');
        }

        // ── opencase_audit_log ────────────────────────────────────────────
        if (!$schema->hasTable('opencase_audit_log')) {
            $table = $schema->createTable('opencase_audit_log');
            // BIGINT throughout: this is the highest-volume table in the schema and
            // the only autoincrement with a realistic path to exhausting INTEGER
            // UNSIGNED (4.29B). The FK columns match the BIGINT UNSIGNED keys they
            // reference, so joins never hit a signed/unsigned conversion.
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('case_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('event_type', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('details', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('document_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            $table->addColumn('file_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            // Each index carries created_at so the newest-first listings in
            // AuditLogMapper never filesort.
            $table->addIndex(['case_id', 'created_at'], 'opencase_audit_case_time');
            $table->addIndex(['document_id', 'created_at'], 'oc_audit_doc_time');
            $table->addIndex(['file_id', 'created_at'], 'oc_audit_file_time');
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
            // BIGINT UNSIGNED to match opencase_cases.id — cu.case_id = c.id sits in
            // the hot permission path, and a signed/unsigned mismatch there can push
            // MariaDB into a decimal conversion that defeats the index.
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('case_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('can_write', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('granted_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('granted_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('expires_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['case_id', 'user_id'], 'oc_uniq_case_user');
            $table->addIndex(['user_id'], 'oc_idx_case_users_uid');
            $table->addIndex(['expires_at'], 'oc_idx_case_users_exp');
            // Covering index for the direct-grant path, which runs on every
            // permission check: user_id + expiry, usually with case_id IN (…).
            $table->addIndex(['user_id', 'expires_at', 'case_id'], 'oc_cu_user_exp');
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
            $table->addColumn('document_id', Types::BIGINT, ['notnull' => false, 'default' => null, 'unsigned' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['file_id', 'shared_with'], 'oc_fshare_file_user');
            $table->addIndex(['shared_with'], 'oc_fshare_recipient');
            $table->addIndex(['document_id'], 'fshare_doc_idx');
            // Share-recipient virtual filesystem: every directory listing filters
            // shared_with + expiry, then joins to files on file_id.
            $table->addIndex(['shared_with', 'expires_at', 'file_id'], 'oc_fs_recip_exp');
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
            $table->addColumn('nc_file_id', Types::BIGINT, ['notnull' => false, 'default' => null]);
            $table->addColumn('nc_owner', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['storage_filename'], 'oc_tmpl_storage_filename');
            $table->addIndex(['uploaded_by'], 'oc_tmpl_uploaded_by');
        }

        // ── opencase_certificate ──────────────────────────────────────────
        if (!$schema->hasTable('opencase_certificate')) {
            $table = $schema->createTable('opencase_certificate');
            $table->addColumn('code', Types::STRING, ['length' => 100, 'notnull' => true]);
            $table->addColumn('filepath', Types::STRING, ['length' => 256, 'notnull' => true]);
            $table->addColumn('password', Types::STRING, ['length' => 256, 'notnull' => false]);
            $table->setPrimaryKey(['code']);
        }

        // ── opencase_org_log ──────────────────────────────────────────────
        if (!$schema->hasTable('opencase_org_log')) {
            $table = $schema->createTable('opencase_org_log');
            $table->addColumn('sync_time', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('count_received', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('created', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('updated', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('deactivated', Types::INTEGER, ['notnull' => true]);
        }

        // ── opencase_klass_log ────────────────────────────────────────────
        if (!$schema->hasTable('opencase_klass_log')) {
            $table = $schema->createTable('opencase_klass_log');
            $table->addColumn('sync_time', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('count_received', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('created', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('updated', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('deactivated', Types::INTEGER, ['notnull' => true]);
        }

        // ── opencase_insightlevel ─────────────────────────────────────────
        if (!$schema->hasTable('opencase_insightlevel')) {
            $table = $schema->createTable('opencase_insightlevel');
            $table->addColumn('id', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('language', Types::STRING, ['notnull' => true, 'length' => 10]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('description', Types::STRING, ['notnull' => false, 'length' => 2000]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addColumn('expired', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->setPrimaryKey(['id', 'language']);
        }

        // ── opencase_userinfo ─────────────────────────────────────────────
        if (!$schema->hasTable('opencase_userinfo')) {
            $table = $schema->createTable('opencase_userinfo');
            $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('username', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            $table->addColumn('personname', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            $table->addColumn('email', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            $table->addColumn('phone', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $table->addColumn('location', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            $table->addColumn('user_id', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->setPrimaryKey(['uuid']);
            $table->addIndex(['user_id'], 'oc_userinfo_user_id');
        }

        // ── opencase_userorgs ─────────────────────────────────────────────
        if (!$schema->hasTable('opencase_userorgs')) {
            $table = $schema->createTable('opencase_userorgs');
            $table->addColumn('user_uuid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('org_uuid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('role', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->setPrimaryKey(['user_id', 'org_uuid']);
            $table->addIndex(['user_uuid'], 'oc_userorgs_user_uuid');
        }

        // ── opencase_contactroles ─────────────────────────────────────────
        if (!$schema->hasTable('opencase_contactroles')) {
            $table = $schema->createTable('opencase_contactroles');
            $table->addColumn('id', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('language', Types::STRING, ['notnull' => true, 'length' => 10]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addColumn('expired', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
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
            $table->addColumn('has_address_protection', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('pnumber', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => null]);
            $table->addColumn('contact_type', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['document_id'], 'oc_dc_document_id');
            $table->addIndex(['user_id'], 'oc_dc_user_id');
            // Citizen lookups: findRawDocumentsByCpr, findActiveByCprCvr,
            // existsByDocumentRoleAndCvr, searchByFields. Without this every CPR
            // search is a full scan of a table that reaches ~20M rows.
            $table->addIndex(['cpr_cvr', 'pnumber'], 'oc_dc_cpr');
            // searchByFields uses prefix LIKE ('x%'), which an index can serve.
            $table->addIndex(['name'], 'oc_dc_name');
            $table->addIndex(['zipcode'], 'oc_dc_zip');
        }

        // ── opencase_participantroles ─────────────────────────────────────
        if (!$schema->hasTable('opencase_participantroles')) {
            $table = $schema->createTable('opencase_participantroles');
            $table->addColumn('id', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('language', Types::STRING, ['notnull' => true, 'length' => 10]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addColumn('expired', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
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
            $table->addColumn('has_address_protection', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('pnumber', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => null]);
            $table->addColumn('contact_type', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['case_id'], 'oc_cp_case_id');
            $table->addIndex(['user_id'], 'oc_cp_user_id');
            // Citizen lookups: findRawCasesByCpr, findActiveByCprCvr,
            // existsByCaseRoleAndCvr, searchByFields.
            $table->addIndex(['cpr_cvr', 'pnumber'], 'oc_cp_cpr');
            // searchByFields uses prefix LIKE ('x%'), which an index can serve.
            $table->addIndex(['name'], 'oc_cp_name');
            $table->addIndex(['zipcode'], 'oc_cp_zip');
        }

        // ── opencase_contacttype ──────────────────────────────────────────
        if (!$schema->hasTable('opencase_contacttype')) {
            $table = $schema->createTable('opencase_contacttype');
            $table->addColumn('id', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('language', Types::STRING, ['notnull' => true, 'length' => 10]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('expired', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->setPrimaryKey(['id', 'language']);
        }

        // ── opencase_caseworkers ──────────────────────────────────────────
        if (!$schema->hasTable('opencase_caseworkers')) {
            $table = $schema->createTable('opencase_caseworkers');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('case_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('prior_can_write', Types::SMALLINT, ['notnull' => false, 'default' => null, 'unsigned' => false]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['case_id', 'user_id'], 'oc_cw_case_user');
        }

        // ── opencase_api_client ───────────────────────────────────────────
        // Registry of systems authorised to call the public API via mTLS.
        // Each row maps a client certificate (identified by its SHA-256
        // fingerprint) to a named service identity and optional scope.
        if (!$schema->hasTable('opencase_api_client')) {
            $table = $schema->createTable('opencase_api_client');
            $table->addColumn('id', Types::BIGINT, ['notnull' => true, 'autoincrement' => true]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('fingerprint', Types::STRING, ['notnull' => true, 'length' => 64,
                'comment' => 'SHA-256 fingerprint of the client certificate, lowercase hex, no colons']);
            $table->addColumn('subject_dn', Types::STRING, ['notnull' => false, 'length' => 512, 'default' => null,
                'comment' => 'Certificate subject DN, stored for human reference only']);
            $table->addColumn('active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('expires_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->addColumn('valid_for', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => null,
                'comment' => 'What the client is registered for: API, Digital Post, Beskedfordeler or Fordelingskomponent']);
            $table->addColumn('mapped_to_user', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null,
                'comment' => 'References oc_users.uid; mandatory when valid_for = API']);
            $table->addColumn('certificate', Types::TEXT, ['notnull' => false, 'default' => null,
                'comment' => 'Full PEM certificate body, used as a trust anchor to verify signed bearer tokens']);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['fingerprint'], 'oc_api_client_fp');
            $table->addIndex(['active'], 'oc_api_client_active');
        }

        // ── opencase_messagesreceived ─────────────────────────────────────
        // Inbox for messages received from Beskedfordeler (and other senders
        // via the public MessageReceiver endpoint).
        if (!$schema->hasTable('opencase_messagesreceived')) {
            $table = $schema->createTable('opencase_messagesreceived');
            $table->addColumn('id', Types::BIGINT, ['notnull' => true, 'autoincrement' => true]);
            $table->addColumn('cpr', Types::STRING, ['notnull' => true, 'length' => 20]);
            $table->addColumn('personhaendelse', Types::STRING, ['notnull' => true, 'length' => 100,
                'comment' => 'Event type code extracted from the personhaendelse URN, e.g. 004']);
            $table->addColumn('received_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 50, 'default' => 'Received']);
            $table->addColumn('status_updated_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['cpr'], 'oc_msgrec_cpr');
            $table->addIndex(['status'], 'oc_msgrec_status');
        }

        // ── opencase_journalnotes ─────────────────────────────────────────
        if (!$schema->hasTable('opencase_journalnotes')) {
            $table = $schema->createTable('opencase_journalnotes');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('case_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('title', Types::STRING, ['length' => 500, 'notnull' => true, 'default' => '']);
            $table->addColumn('text', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => false, 'default' => null]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('is_locked', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['case_id'], 'oc_opencase_jn_case_id');
        }

        // ── opencase_documentnotes ────────────────────────────────────────
        if (!$schema->hasTable('opencase_documentnotes')) {
            $table = $schema->createTable('opencase_documentnotes');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('document_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('title', Types::STRING, ['length' => 500, 'notnull' => true, 'default' => '']);
            $table->addColumn('text', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => false, 'default' => null]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['document_id'], 'oc_opencase_dn_doc_id');
        }

        // ── opencase_dp_shipment ──────────────────────────────────────────
        if (!$schema->hasTable('opencase_dp_shipment')) {
            $table = $schema->createTable('opencase_dp_shipment');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('document_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('can_be_replied', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
            $table->addColumn('case_uuid', Types::STRING, ['length' => 36, 'notnull' => false, 'default' => null]);
            $table->addColumn('document_uuid', Types::STRING, ['length' => 36, 'notnull' => false, 'default' => null]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => false, 'default' => null]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('status', Types::STRING, ['length' => 32, 'notnull' => true, 'default' => 'Pending']);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['document_id'], 'oc_opencase_dps_doc_id');
        }

        // ── opencase_dp_files ─────────────────────────────────────────────
        if (!$schema->hasTable('opencase_dp_files')) {
            $table = $schema->createTable('opencase_dp_files');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('dp_shipment_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('file_name', Types::STRING, ['length' => 512, 'notnull' => true, 'default' => '']);
            $table->addColumn('storage_filename', Types::STRING, ['length' => 255, 'notnull' => false, 'default' => null]);
            $table->addColumn('is_main', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['dp_shipment_id'], 'oc_opencase_dpf_ship_id');
        }

        // ── opencase_dp_receivers ─────────────────────────────────────────
        if (!$schema->hasTable('opencase_dp_receivers')) {
            $table = $schema->createTable('opencase_dp_receivers');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('dp_shipment_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('title', Types::STRING, ['length' => 512, 'notnull' => true, 'default' => '']);
            $table->addColumn('cpr_cvr', Types::STRING, ['length' => 32, 'notnull' => false, 'default' => null]);
            $table->addColumn('receiver_name', Types::STRING, ['length' => 255, 'notnull' => false, 'default' => null]);
            $table->addColumn('dp_subscription', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('storage_filename', Types::STRING, ['length' => 255, 'notnull' => false, 'default' => null]);
            $table->addColumn('message_id', Types::STRING, ['length' => 36, 'notnull' => false, 'default' => null]);
            $table->addColumn('message_created_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('status', Types::STRING, ['length' => 32, 'notnull' => true, 'default' => 'Pending']);
            $table->addColumn('message_advis_id', Types::STRING, ['length' => 32, 'notnull' => false, 'default' => null]);
            $table->addColumn('message_advis_tekst', Types::STRING, ['length' => 32, 'notnull' => false, 'default' => null]);
            $table->addColumn('document_contact_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            $table->addColumn('pnumber', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => null]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['dp_shipment_id'], 'oc_opencase_dpr_ship_id');
            $table->addIndex(['document_contact_id'], 'oc_opencase_dpr_contact_id');
        }

        // ── opencase_dp_receivements ──────────────────────────────────────
        if (!$schema->hasTable('opencase_dp_receivements')) {
            $table = $schema->createTable('opencase_dp_receivements');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('transaktionstid', Types::STRING, ['length' => 100, 'notnull' => true, 'default' => '']);
            $table->addColumn('transaktionsid', Types::STRING, ['length' => 100, 'notnull' => true, 'default' => '']);
            $table->addColumn('storage_filename', Types::STRING, ['length' => 255, 'notnull' => false, 'default' => null]);
            $table->addColumn('message_uuid', Types::STRING, ['length' => 36, 'notnull' => false, 'default' => null]);
            $table->addColumn('sender_idtype', Types::STRING, ['length' => 32, 'notnull' => false, 'default' => null]);
            $table->addColumn('sender_id', Types::STRING, ['length' => 100, 'notnull' => false, 'default' => null]);
            $table->addColumn('status', Types::STRING, ['length' => 32, 'notnull' => true, 'default' => 'Pending']);
            $table->addColumn('received_at', Types::DATETIME, ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['transaktionsid'], 'oc_opencase_dprec_tid');
            $table->addIndex(['message_uuid'], 'oc_opencase_dprec_uuid');
        }

        // ── opencase_favorites ────────────────────────────────────────────
        if (!$schema->hasTable('opencase_favorites')) {
            $table = $schema->createTable('opencase_favorites');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('added_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('entity', Types::STRING, ['notnull' => true, 'length' => 32]);
            $table->addColumn('entity_key', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_id', 'entity', 'entity_key'], 'oc_opencase_fav_unique');
            $table->addIndex(['user_id'], 'oc_opencase_fav_user');
        }

        // ── opencase_aiprompts ────────────────────────────────────────────
        if (!$schema->hasTable('opencase_aiprompts')) {
            $table = $schema->createTable('opencase_aiprompts');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('scope', Types::STRING, ['notnull' => true, 'length' => 100, 'default' => 'all']);
            $table->addColumn('prompt', Types::TEXT, ['notnull' => true]);
            $table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('last_used_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['created_by'], 'oc_opencase_aiprompts_user');
        }

        // ── opencase_aiactions ────────────────────────────────────────────
        if (!$schema->hasTable('opencase_aiactions')) {
            $table = $schema->createTable('opencase_aiactions');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('prompt_id', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('sequence_index', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('action_type', Types::STRING, ['notnull' => true, 'length' => 100]);
            $table->addColumn('action_params', Types::TEXT, ['notnull' => true]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['prompt_id'], 'oc_opencase_aiactions_prompt');
        }

        // ── opencase_dist_receivements ────────────────────────────────────
        if (!$schema->hasTable('opencase_dist_receivements')) {
            $table = $schema->createTable('opencase_dist_receivements');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('transaktions_id', Types::STRING, ['notnull' => true, 'length' => 100]);
            $table->addColumn('storage_filename', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('objekttype', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => null]);
            $table->addColumn('afsendende_myndighed', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => null]);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'Pending']);
            $table->setPrimaryKey(['id']);
        }

        // ── opencase_transaction_log ──────────────────────────────────────
        if (!$schema->hasTable('opencase_transaction_log')) {
            $table = $schema->createTable('opencase_transaction_log');
            // BIGINT: append-only log, second only to the audit log in volume.
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('transaction_time', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('transaction_type', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('details', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'oc_txlog_user_id');
            $table->addIndex(['transaction_time'], 'oc_txlog_time');
            $table->addIndex(['transaction_type'], 'oc_txlog_type');
        }

        // ── opencase_doc_workflows ────────────────────────────────────────
        if (!$schema->hasTable('opencase_doc_workflows')) {
            $table = $schema->createTable('opencase_doc_workflows');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('document_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 16]); // 'review' | 'approval'
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16]); // 'active' | 'completed' | 'rejected' | 'cancelled'
            $table->addColumn('deadline', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['document_id'], 'oc_docwf_document_id');
            $table->addIndex(['status'], 'oc_docwf_status');
        }

        // ── opencase_doc_workflow_steps ───────────────────────────────────
        if (!$schema->hasTable('opencase_doc_workflow_steps')) {
            $table = $schema->createTable('opencase_doc_workflow_steps');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('workflow_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('sort_order', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16]); // 'pending' | 'reviewed' | 'approved' | 'rejected'
            $table->addColumn('comment', Types::TEXT, ['notnull' => false]);
            $table->addColumn('deadline', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('acted_at', Types::DATETIME, ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            // Both indexes carry the sort column: findPendingByUser orders by
            // deadline, findCurrentPendingStep by sort_order.
            $table->addIndex(['user_id', 'status', 'deadline'], 'oc_wfs_user_status');
            $table->addIndex(['workflow_id', 'status', 'sort_order'], 'oc_wfs_wf_status');
        }

        // ── opencase_access_requests ──────────────────────────────────────
        if (!$schema->hasTable('opencase_access_requests')) {
            $table = $schema->createTable('opencase_access_requests');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
            $table->addColumn('case_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 32]); // public_access | party_access | gdpr_access
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'received']);
            // received | collecting | reviewing | redacting | approval | sent | rejected | partly_rejected | closed
            $table->addColumn('requester_name', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->addColumn('requester_email', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->addColumn('requester_phone', Types::STRING, ['notnull' => false, 'length' => 50, 'default' => null]);
            $table->addColumn('requester_identifier', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->addColumn('subject', Types::TEXT, ['notnull' => true]);
            $table->addColumn('description', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('assigned_user', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addColumn('received_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('deadline_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('extended_deadline_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->addColumn('extension_reason', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('legal_basis', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['uuid'], 'oc_ar_uuid');
            $table->addIndex(['case_id'], 'oc_ar_case_id');
            $table->addIndex(['deadline_at'], 'oc_ar_deadline');
            // AccessRequestMapper::findByAssignedUser / findApproachingDeadlines,
            // both ordered by deadline_at.
            $table->addIndex(['assigned_user', 'status', 'deadline_at'], 'oc_ar_assigned_status');
            $table->addIndex(['status', 'deadline_at'], 'oc_ar_status_deadline');
        }

        // ── opencase_access_request_items ─────────────────────────────────
        if (!$schema->hasTable('opencase_access_request_items')) {
            $table = $schema->createTable('opencase_access_request_items');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('request_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('source_type', Types::STRING, ['notnull' => true, 'length' => 32]); // document | email | journal_entry | note | attachment
            $table->addColumn('source_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 500]);
            $table->addColumn('mime_type', Types::STRING, ['notnull' => false, 'length' => 150, 'default' => null]);
            $table->addColumn('document_date', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => null]);
            $table->addColumn('decision', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']); // pending | include | exclude | partly_include
            $table->addColumn('exclusion_reason', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('legal_reference', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->addColumn('contains_personal_data', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('contains_sensitive_data', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('contains_confidential_data', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['request_id'], 'oc_ari_request_id');
            $table->addIndex(['request_id', 'decision'], 'oc_ari_req_decision');
        }

        // ── opencase_access_redactions ────────────────────────────────────
        if (!$schema->hasTable('opencase_access_redactions')) {
            $table = $schema->createTable('opencase_access_redactions');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('request_item_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('redaction_type', Types::STRING, ['notnull' => true, 'length' => 32]);
            // manual | cpr | name | address | email | phone | health | confidential | other
            $table->addColumn('original_text', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('replacement_text', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '████']);
            $table->addColumn('reason', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('legal_reference', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['request_item_id'], 'oc_ared_item_id');
        }

        // ── opencase_access_decisions ─────────────────────────────────────
        if (!$schema->hasTable('opencase_access_decisions')) {
            $table = $schema->createTable('opencase_access_decisions');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('request_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('decision', Types::STRING, ['notnull' => true, 'length' => 32]); // full_access | partial_access | rejected | withdrawn
            $table->addColumn('decision_text', Types::TEXT, ['notnull' => true]);
            $table->addColumn('complaint_guidance', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('approved_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addColumn('approved_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->addColumn('sent_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addColumn('sent_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->addColumn('delivery_method', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => null]); // email | digital_post | manual
            $table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['request_id'], 'oc_adec_request_id');
        }

        // ── opencase_access_exclusion_reasons ─────────────────────────────
        if (!$schema->hasTable('opencase_access_exclusion_reasons')) {
            $table = $schema->createTable('opencase_access_exclusion_reasons');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 512]);
            $table->setPrimaryKey(['id']);
        }

        // ── opencase_reminders ────────────────────────────────────────────
        if (!$schema->hasTable('opencase_reminders')) {
            $table = $schema->createTable('opencase_reminders');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('entity', Types::STRING, ['notnull' => true, 'length' => 32]);
            $table->addColumn('entity_key', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 512]);
            $table->addColumn('deadline', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32]);
            $table->addColumn('responsible_user_id', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->setPrimaryKey(['id']);
            // Both carry deadline, which every ReminderMapper query orders by.
            $table->addIndex(['entity', 'entity_key', 'status', 'deadline'], 'oc_rem_entity_status');
            $table->addIndex(['responsible_user_id', 'status', 'deadline'], 'oc_rem_user_status');
        }

        // ── opencase_cprevents ────────────────────────────────────────────
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

        // ── opencase_export_log ───────────────────────────────────────────
        if (!$schema->hasTable('opencase_export_log')) {
            $table = $schema->createTable('opencase_export_log');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('sync_time', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('exported', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('failed', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['sync_time'], 'oc_export_log_sync_time');
        }

        // ── opencase_history ──────────────────────────────────────────────
        if (!$schema->hasTable('opencase_history')) {
            $table = $schema->createTable('opencase_history');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('accessed_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('entity', Types::STRING, ['notnull' => true, 'length' => 32]);
            $table->addColumn('entity_key', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_id', 'entity', 'entity_key'], 'oc_opencase_hist_unique');
            $table->addIndex(['user_id', 'entity', 'accessed_at'], 'oc_opencase_hist_trim');
        }

        return $schema;
    }

    /**
     * Seeds config entries that used to be seeded by individual migrations'
     * postSchemaChange(). Like before the merge, Nextcloud skips this
     * entirely on a fresh install; general reference data for fresh installs
     * is seeded by the SeedReferenceData repair step instead.
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $this->seedConfigEntry(
            'search_max_result_count',
            '500',
            'Maksimalt antal søgeresultater',
            'Det maksimale antal rækker der returneres på tværs af søgninger (Fritekst, Sager, Dokumenter og Alle sager). Brugeren kan bladre i resultater op til denne grænse.',
            $output,
        );

        $this->seedConfigEntry(
            'search_page_size',
            '50',
            'Sidevisning – søgeresultater',
            'Antal rækker pr. side i søgeresultater og sagslister.',
            $output,
        );

        $this->seedConfigEntry(
            'msoffice_addin_port',
            '1443',
            'MS Office add-in port',
            'nginx port used to serve the MS Office add-in backend (Outlook/Word/Excel/PowerPoint task panes), read by msoffice/nginx/deploy.sh.',
            $output,
        );

        $this->seedConfigEntry(
            'msoffice_graph_client_id',
            '',
            'MS Office Graph app (client) ID',
            'Application (client) ID of the Entra ID app registration used by the MS Office add-ins to call Microsoft Graph (downloading real file/email content when running in a browser). Register a Single-page application (SPA) with redirect URI https://<addin host>/shared/graph-auth.html and delegated Microsoft Graph permission Files.Read (mail add-in also needs Mail.Read), with admin consent granted.',
            $output,
        );

        $this->seedConfigEntry(
            'msoffice_graph_tenant_id',
            '',
            'MS Office Graph tenant ID',
            'Directory (tenant) ID of the Entra ID tenant that owns the app registration above.',
            $output,
        );

        $this->seedConfigEntry(
            'enterprise_version',
            '0',
            'Enterprise version enabled',
            'Set to 1 only when the Enterprise deployment package (which installs the Distribution components) has been installed. Leave at 0 for the public App Store release.',
            $output,
        );

        $defaultExportFolder = rtrim($this->config->getSystemValueString('datadirectory', ''), '/')
            . '/opencase_storage/_export';
        $this->seedConfigEntry(
            'export_folder',
            $defaultExportFolder,
            'Eksportmappe',
            'Sti hvor lukkede sager eksporteres til (XML-metadata, dokumentfiler og logs), én undermappe pr. sagsnummer. Skrives til hver time af ExportClosedCasesJob.',
            $output,
        );

        $this->seedConfigEntry(
            'export_enabled',
            '0',
            'Eksport aktiveret',
            'Om ExportClosedCasesJob skal eksportere lukkede sager. Deaktiveret som standard.',
            $output,
        );

        $this->seedConfigEntry(
            'entity_id_opencase_api',
            'http://opencase.dk/service/api/1',
            'Audience for OpenCase API',
            'Den Audience-værdi som bearer-tokens til den offentlige data-API skal matche.',
            $output,
        );

        $this->seedConfigEntry(
            'history_count',
            '20',
            'Antal elementer i seneste',
            'Angiv antal elementer i seneste sager og dokumenter.',
            $output,
        );

        $this->seedExclusionReasons($output);
    }

    private function seedConfigEntry(
        string $key,
        string $defaultValue,
        string $name,
        string $description,
        IOutput $output,
    ): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('config_key')
            ->from('opencase_config')
            ->where($qb->expr()->eq('config_key', $qb->createNamedParameter($key)));
        $result = $qb->executeQuery();
        $exists = $result->fetchOne();
        $result->closeCursor();

        if ($exists === false) {
            $qb->resetQueryParts();
            $qb->insert('opencase_config')->values([
                'config_key'   => $qb->createNamedParameter($key),
                'config_value' => $qb->createNamedParameter($defaultValue),
                'name'         => $qb->createNamedParameter($name),
                'description'  => $qb->createNamedParameter($description),
            ])->executeStatement();
            $output->info("Seeded config: {$key}.");
        } else {
            $qb->resetQueryParts();
            $qb->update('opencase_config')
                ->set('name', $qb->createNamedParameter($name))
                ->set('description', $qb->createNamedParameter($description))
                ->where($qb->expr()->eq('config_key', $qb->createNamedParameter($key)))
                ->executeStatement();
            $output->info("Updated metadata for config: {$key}.");
        }
    }

    private function seedExclusionReasons(IOutput $output): void {
        $qb = $this->db->getQueryBuilder();
        $count = (int) $qb->select($qb->createFunction('COUNT(*)'))
            ->from('opencase_access_exclusion_reasons')
            ->executeQuery()
            ->fetchOne();

        if ($count > 0) {
            return;
        }

        $labels = [
            'Interne arbejdsdokumenter (§23)',
            'Ministerbetjening (§24)',
            'Personfølsomme oplysninger',
            'Fortrolige oplysninger',
            'CPR-numre',
            'Helbredsoplysninger',
            'Straffesager',
            'Beskyttede adresser',
            'Tredjepartsoplysninger',
            'Forretningshemmeligheder',
        ];

        foreach ($labels as $label) {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('opencase_access_exclusion_reasons')
                ->values(['label' => $qb->createNamedParameter($label)])
                ->executeStatement();
        }

        $output->info('Seeded opencase_access_exclusion_reasons with ' . count($labels) . ' rows.');
    }
}
