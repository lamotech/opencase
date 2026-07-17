<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Privilege-based access control tables.
 *
 * New tables:
 *   opencase_user_priv_groups  — raw PrivilegeGroup rows parsed from SAML token
 *   opencase_user_priv_match   — pre-expanded (user, sensitivity, class_subject, org) tuples
 *                                used for efficient access-profile matching
 *
 * Altered tables:
 *   opencase_user_access       — adds grant_source ('privilege' | 'manual') so that
 *                                automatic privilege grants can be rebuilt without
 *                                touching manually assigned rows
 */
class Version000009Date20260412000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── opencase_user_priv_groups ─────────────────────────────────────
        // Stores the raw PrivilegeGroup elements from the SAML token.
        // One row per privilege group per user (a user can have multiple
        // opencaseuser groups with different constraint combinations).
        // Non-opencaseuser rows (admin, templateadmin, sysadmin) have NULL constraints.
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

        // ── opencase_user_priv_match ──────────────────────────────────────
        // Stores KLE patterns as-is (one row per raw pattern, NOT expanded to
        // individual class_subject UUIDs).  This keeps the table small:
        // ~160 rows/user instead of ~5 000, so 5 000 users → ~800 K rows.
        //
        // kle_pattern is stored in SQL LIKE syntax:
        //   token "00.*"    → stored as "00.%"
        //   token "27.25.00" → stored as "27.25.00"  (exact; % not needed)
        //   token "*"        → stored as "%"
        //
        // org_uuid = '' means "no org constraint" (matches any org).
        //
        // ── Matching a new access_profile (org=X, class_subject=Y, sens=Z): ──
        // 1. Look up code for Y:  SELECT code FROM opencase_class_subject WHERE uuid = Y
        // 2. Generate candidate patterns for that code (e.g. "27.25.00" yields
        //    ["27.25.00", "27.25.%", "27.%", "%"])
        // 3. SELECT DISTINCT user_id FROM opencase_user_priv_match
        //      WHERE sensitivity_uuid = Z
        //        AND kle_pattern IN (:candidates)
        //        AND (org_uuid = '' OR org_uuid = X)
        //    → fully index-supported via (kle_pattern, sensitivity_uuid, org_uuid).
        //
        // ── Rebuilding a user's access grants after privilege change: ──────
        //   SELECT DISTINCT ap.id
        //   FROM opencase_access_profiles ap
        //   JOIN opencase_class_subject cs ON cs.uuid = ap.class_subject_uuid
        //   JOIN opencase_user_priv_match upm
        //        ON upm.user_id = :userId
        //       AND upm.sensitivity_uuid = ap.sensitivity_uuid
        //       AND (upm.org_uuid = '' OR upm.org_uuid = ap.org_uuid)
        //       AND cs.code LIKE upm.kle_pattern
        if (!$schema->hasTable('opencase_user_priv_match')) {
            $table = $schema->createTable('opencase_user_priv_match');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('sensitivity_uuid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('kle_pattern', Types::STRING, ['notnull' => true, 'length' => 255,
                'comment' => "SQL LIKE pattern, e.g. '27.%', '27.25.00', '%'"]);
            $table->addColumn('org_uuid', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '',
                'comment' => "Specific org UUID, or '' for any org"]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(
                ['user_id', 'sensitivity_uuid', 'kle_pattern', 'org_uuid'],
                'oc_upm_unique'
            );
            // Used when matching a new access_profile → which users qualify?
            $table->addIndex(
                ['kle_pattern', 'sensitivity_uuid', 'org_uuid'],
                'oc_upm_profile_match'
            );
            // Used when rebuilding a single user's matches after privilege change
            $table->addIndex(['user_id'], 'oc_upm_user_id');
        }

        // ── opencase_user_access — add grant_source column ────────────────
        // 'privilege' = automatically granted via SAML privilege sync
        // 'manual'    = explicitly granted by an administrator
        // Existing rows are treated as 'manual' (safest default).
        if ($schema->hasTable('opencase_user_access')) {
            $table = $schema->getTable('opencase_user_access');
            if (!$table->hasColumn('grant_source')) {
                $table->addColumn('grant_source', Types::STRING, [
                    'notnull' => true,
                    'length'  => 16,
                    'default' => 'manual',
                    'comment' => 'privilege = auto-synced from SAML token; manual = admin-assigned',
                ]);
                $table->addIndex(['grant_source'], 'oc_ua_grant_source');
            }
        }

        return $schema;
    }
}
