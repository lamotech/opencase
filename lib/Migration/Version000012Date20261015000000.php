<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * - opencase_access_profiles.profile_type — which kind of access profile the
 *   row is: 'fk' (org + KLE + sensitivity, FK Adgang) or 'group' (a Nextcloud
 *   group). Existing rows default to 'fk'.
 * - opencase_access_profiles.group_id — the oc_groups.gid a 'group' profile
 *   references; NULL for 'fk' profiles. Unique, so each group maps to at most
 *   one profile (NULLs do not collide, so 'fk' profiles are unaffected).
 * - opencase_separation_sheets.group_id — the access group of a 'new case'
 *   sheet when the access profile type is Grupper.
 * - opencase_user_roles.grant_source — where a role comes from: 'privilege'
 *   (SAML token / "Rettigheder", the existing rows) or 'group' (membership of
 *   a Nextcloud group mapped to the role). The unique key becomes
 *   (user_id, role_id, grant_source) so a user can hold a role from both
 *   sources, and losing one source doesn't revoke the other.
 * Process module (BPMN processes run by the Flowable process engine):
 * - opencase_process_def — a process, identified by process_key, which is
 *   also the BPMN process id and the Flowable process definition key.
 * - opencase_process_version — the BPMN XML of each version. At most one
 *   draft per process; publishing deploys the XML to Flowable and makes the
 *   version read-only. status: draft | published | superseded.
 * - opencase_process_scope — which cases a process can be started on,
 *   scoped like custom fields (casetype | organisation | kle).
 */
class Version000012Date20261015000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $profiles = $schema->getTable('opencase_access_profiles');
        if (!$profiles->hasColumn('profile_type')) {
            $profiles->addColumn('profile_type', Types::STRING, [
                'notnull' => true,
                'length'  => 16,
                'default' => 'fk',
                'comment' => 'fk = org/KLE/sensitivity (FK Adgang); group = Nextcloud group',
            ]);
        }
        if (!$profiles->hasColumn('group_id')) {
            $profiles->addColumn('group_id', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        }
        if (!$profiles->hasIndex('oc_ap_group')) {
            // AccessProfileMapper::findByGroupId.
            $profiles->addUniqueIndex(['group_id'], 'oc_ap_group');
        }

        $userRoles = $schema->getTable('opencase_user_roles');
        if (!$userRoles->hasColumn('grant_source')) {
            $userRoles->addColumn('grant_source', Types::STRING, [
                'notnull' => true,
                'length'  => 16,
                'default' => 'privilege',
                'comment' => 'privilege = SAML token / Rettigheder; group = mapped Nextcloud group',
            ]);
        }
        if ($userRoles->hasIndex('opencase_ur_user_role')) {
            $userRoles->dropIndex('opencase_ur_user_role');
        }
        if (!$userRoles->hasIndex('opencase_ur_user_role_src')) {
            $userRoles->addUniqueIndex(['user_id', 'role_id', 'grant_source'], 'opencase_ur_user_role_src');
        }

        $sheets = $schema->getTable('opencase_separation_sheets');
        if (!$sheets->hasColumn('group_id')) {
            $sheets->addColumn('group_id', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        }
        
        if (!$schema->hasTable('opencase_process_def')) {
            $table = $schema->createTable('opencase_process_def');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('process_key', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('description', Types::TEXT, ['notnull' => false]);
            $table->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['process_key'], 'oc_procdef_key');
        }

        if (!$schema->hasTable('opencase_process_version')) {
            $table = $schema->createTable('opencase_process_version');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('process_def_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('version_no', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16,
                'comment' => 'draft | published | superseded']);
            $table->addColumn('bpmn_xml', Types::TEXT, ['notnull' => true]);
            // sha256 of bpmn_xml, used to detect concurrent edits of a draft
            $table->addColumn('checksum', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('change_note', Types::TEXT, ['notnull' => false]);
            $table->addColumn('flowable_deployment_id', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->addColumn('flowable_proc_def_id', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->addColumn('published_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('published_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['process_def_id', 'version_no'], 'oc_procver_def_no');
        }

        if (!$schema->hasTable('opencase_process_scope')) {
            $table = $schema->createTable('opencase_process_scope');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('process_def_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('scope_type', Types::STRING, ['notnull' => true, 'length' => 20,
                'comment' => 'casetype | organisation | kle']);
            // casetype id (string), org_uuid, or a KLE pattern such as "27.%".
            $table->addColumn('scope_value', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['process_def_id'], 'oc_procscope_def');
        }

        return $schema;
    }
}
