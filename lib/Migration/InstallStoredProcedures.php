<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Installs (or replaces) MySQL stored procedures required by OpenCase.
 *
 * Registered as both an install and post-migration repair step in
 * appinfo/info.xml so that it runs on fresh installs as well as upgrades.
 *
 * Idempotent — DROP PROCEDURE IF EXISTS ensures safe re-runs.
 */
class InstallStoredProcedures implements IRepairStep {

    public function __construct(private IDBConnection $db) {}

    public function getName(): string {
        return 'Install OpenCase stored procedures';
    }

    public function run(IOutput $output): void {
        $this->installSyncFilecache($output);
        $this->installRecalcPrivileges($output);
    }

    private function installSyncFilecache(IOutput $output): void {
        $output->info('Installing opencase_sync_filecache stored procedure');

        // DELIMITER is a MySQL CLI directive, not valid SQL.
        // When executing via PDO/DBAL the full CREATE PROCEDURE body is
        // passed as one string — no DELIMITER directive is needed.

        $this->db->executeStatement('DROP PROCEDURE IF EXISTS opencase_sync_filecache');

        $this->db->executeStatement("
CREATE PROCEDURE opencase_sync_filecache()
proc: BEGIN

    DECLARE v_storage_id    INT DEFAULT 0;
    DECLARE v_mime_dir      INT DEFAULT 0;
    DECLARE v_mime_dir_part INT DEFAULT 0;
    DECLARE v_now           INT DEFAULT UNIX_TIMESTAMP();

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        DROP TEMPORARY TABLE IF EXISTS _oc_desired;
        DROP TEMPORARY TABLE IF EXISTS _oc_write_profiles;
        RESIGNAL;
    END;

    -- ════════════════════════════════════════════════════════════════════════
    -- 0. Mimetypes
    -- ════════════════════════════════════════════════════════════════════════

    INSERT IGNORE INTO oc_mimetypes (mimetype)
    VALUES ('httpd'), ('httpd/unix-directory');

    INSERT IGNORE INTO oc_mimetypes (mimetype)
    SELECT DISTINCT mime_type
    FROM   oc_opencase_files
    WHERE  mime_type IS NOT NULL AND mime_type <> '';

    INSERT IGNORE INTO oc_mimetypes (mimetype)
    SELECT DISTINCT SUBSTRING_INDEX(mime_type, '/', 1)
    FROM   oc_opencase_files
    WHERE  mime_type LIKE '%/%';

    SELECT id INTO v_mime_dir_part FROM oc_mimetypes WHERE mimetype = 'httpd'                LIMIT 1;
    SELECT id INTO v_mime_dir      FROM oc_mimetypes WHERE mimetype = 'httpd/unix-directory' LIMIT 1;

    -- ════════════════════════════════════════════════════════════════════════
    -- 1. Ensure the ONE shared storage row exists
    -- ════════════════════════════════════════════════════════════════════════

    INSERT IGNORE INTO oc_storages (id, available, last_checked)
    VALUES ('opencase', 1, v_now);

    SELECT numeric_id INTO v_storage_id
    FROM   oc_storages
    WHERE  id = 'opencase';

    -- ════════════════════════════════════════════════════════════════════════
    -- 2. Build the complete desired filecache state for the shared storage
    -- ════════════════════════════════════════════════════════════════════════

    DROP TEMPORARY TABLE IF EXISTS _oc_desired;
    CREATE TEMPORARY TABLE _oc_desired (
        path             VARCHAR(4000) NOT NULL DEFAULT '',
        path_hash        CHAR(32)      NOT NULL,
        parent_path_hash CHAR(32)      DEFAULT NULL,
        name             VARCHAR(255)  NOT NULL DEFAULT '',
        level            TINYINT       NOT NULL,
        is_dir           TINYINT       NOT NULL DEFAULT 1,
        mime_str         VARCHAR(255)  DEFAULT NULL,
        file_size        BIGINT        NOT NULL DEFAULT 0,
        permissions      SMALLINT      NOT NULL DEFAULT 1,
        UNIQUE KEY uk_ph (path_hash)
    ) ENGINE=InnoDB;

    DROP TEMPORARY TABLE IF EXISTS _oc_write_profiles;
    CREATE TEMPORARY TABLE _oc_write_profiles (
        access_profile_id INT NOT NULL PRIMARY KEY
    ) ENGINE=InnoDB;

    INSERT INTO _oc_write_profiles (access_profile_id)
    SELECT DISTINCT access_profile_id
    FROM   oc_opencase_user_access
    WHERE  access_level = 'write';

    -- ── Level 0: root ────────────────────────────────────────────────────────
    INSERT IGNORE INTO _oc_desired
        (path, path_hash, parent_path_hash, name, level, permissions)
    VALUES ('', MD5(''), NULL, '', 0, 1);

    -- ── Level 1: year directories ────────────────────────────────────────────
    INSERT IGNORE INTO _oc_desired
        (path, path_hash, parent_path_hash, name, level, permissions)
    SELECT DISTINCT
        DATE_FORMAT(created_at, '%Y'),
        MD5(DATE_FORMAT(created_at, '%Y')),
        MD5(''),
        DATE_FORMAT(created_at, '%Y'),
        1,
        1
    FROM  oc_opencase_cases
    WHERE created_at IS NOT NULL;

    -- ── Level 2: month directories ───────────────────────────────────────────
    INSERT IGNORE INTO _oc_desired
        (path, path_hash, parent_path_hash, name, level, permissions)
    SELECT DISTINCT
        DATE_FORMAT(created_at, '%Y/%m'),
        MD5(DATE_FORMAT(created_at, '%Y/%m')),
        MD5(DATE_FORMAT(created_at, '%Y')),
        DATE_FORMAT(created_at, '%m'),
        2,
        1
    FROM  oc_opencase_cases
    WHERE created_at IS NOT NULL;

    -- ── Level 3: day directories ─────────────────────────────────────────────
    INSERT IGNORE INTO _oc_desired
        (path, path_hash, parent_path_hash, name, level, permissions)
    SELECT DISTINCT
        DATE_FORMAT(created_at, '%Y/%m/%d'),
        MD5(DATE_FORMAT(created_at, '%Y/%m/%d')),
        MD5(DATE_FORMAT(created_at, '%Y/%m')),
        DATE_FORMAT(created_at, '%d'),
        3,
        1
    FROM  oc_opencase_cases
    WHERE created_at IS NOT NULL;

    -- ── Level 4: case directories ────────────────────────────────────────────
    INSERT IGNORE INTO _oc_desired
        (path, path_hash, parent_path_hash, name, level, permissions)
    SELECT
        CONCAT(DATE_FORMAT(c.created_at, '%Y/%m/%d'), '/', c.case_number),
        MD5(CONCAT(DATE_FORMAT(c.created_at, '%Y/%m/%d'), '/', c.case_number)),
        MD5(DATE_FORMAT(c.created_at, '%Y/%m/%d')),
        c.case_number,
        4,
        CASE WHEN wp.access_profile_id IS NOT NULL THEN 7 ELSE 1 END
    FROM  oc_opencase_cases c
    LEFT JOIN _oc_write_profiles wp ON wp.access_profile_id = c.access_profile_id
    WHERE c.created_at IS NOT NULL;

    -- ── Level 5: files ───────────────────────────────────────────────────────
    INSERT IGNORE INTO _oc_desired
        (path, path_hash, parent_path_hash, name,
         level, is_dir, mime_str, file_size, permissions)
    SELECT
        CONCAT(DATE_FORMAT(c.created_at, '%Y/%m/%d'), '/', c.case_number, '/', f.virtual_filename),
        MD5(CONCAT(DATE_FORMAT(c.created_at, '%Y/%m/%d'), '/', c.case_number, '/', f.virtual_filename)),
        MD5(CONCAT(DATE_FORMAT(c.created_at, '%Y/%m/%d'), '/', c.case_number)),
        f.virtual_filename,
        5,
        0,
        f.mime_type,
        COALESCE(f.size, 0),
        CASE
            WHEN wp.access_profile_id IS NOT NULL AND COALESCE(ds.is_final, 0) = 0 THEN 3
            ELSE 1
        END
    FROM  oc_opencase_files f
    JOIN  oc_opencase_documents d  ON d.id = f.document_id
    JOIN  oc_opencase_cases c      ON c.id = f.case_id
    LEFT JOIN _oc_write_profiles wp ON wp.access_profile_id = c.access_profile_id
    LEFT JOIN oc_opencase_documentstatus ds
           ON ds.id = d.status AND ds.language = 'en'
    WHERE c.created_at IS NOT NULL;

    -- ════════════════════════════════════════════════════════════════════════
    -- 3. Insert missing oc_filecache entries level by level
    -- ════════════════════════════════════════════════════════════════════════

    START TRANSACTION;

    -- Level 0: root (parent = -1)
    INSERT IGNORE INTO oc_filecache
        (storage, path, path_hash, parent, name,
         mimetype, mimepart, size, mtime, storage_mtime,
         encrypted, unencrypted_size, etag, permissions, checksum)
    SELECT
        v_storage_id, d.path, d.path_hash, -1, d.name,
        v_mime_dir, v_mime_dir_part,
        0, v_now, v_now, 0, 0,
        LOWER(REPLACE(UUID(), '-', '')), d.permissions, ''
    FROM _oc_desired d
    WHERE d.level = 0;

    -- Level 1: year dirs
    INSERT IGNORE INTO oc_filecache
        (storage, path, path_hash, parent, name,
         mimetype, mimepart, size, mtime, storage_mtime,
         encrypted, unencrypted_size, etag, permissions, checksum)
    SELECT
        v_storage_id, d.path, d.path_hash,
        p.fileid,     d.name,
        v_mime_dir, v_mime_dir_part,
        0, v_now, v_now, 0, 0,
        LOWER(REPLACE(UUID(), '-', '')), d.permissions, ''
    FROM _oc_desired d
    JOIN oc_filecache p
      ON p.storage   = v_storage_id
     AND p.path_hash = d.parent_path_hash
    WHERE d.level = 1;

    -- Level 2: month dirs
    INSERT IGNORE INTO oc_filecache
        (storage, path, path_hash, parent, name,
         mimetype, mimepart, size, mtime, storage_mtime,
         encrypted, unencrypted_size, etag, permissions, checksum)
    SELECT
        v_storage_id, d.path, d.path_hash,
        p.fileid,     d.name,
        v_mime_dir, v_mime_dir_part,
        0, v_now, v_now, 0, 0,
        LOWER(REPLACE(UUID(), '-', '')), d.permissions, ''
    FROM _oc_desired d
    JOIN oc_filecache p
      ON p.storage   = v_storage_id
     AND p.path_hash = d.parent_path_hash
    WHERE d.level = 2;

    -- Level 3: day dirs
    INSERT IGNORE INTO oc_filecache
        (storage, path, path_hash, parent, name,
         mimetype, mimepart, size, mtime, storage_mtime,
         encrypted, unencrypted_size, etag, permissions, checksum)
    SELECT
        v_storage_id, d.path, d.path_hash,
        p.fileid,     d.name,
        v_mime_dir, v_mime_dir_part,
        0, v_now, v_now, 0, 0,
        LOWER(REPLACE(UUID(), '-', '')), d.permissions, ''
    FROM _oc_desired d
    JOIN oc_filecache p
      ON p.storage   = v_storage_id
     AND p.path_hash = d.parent_path_hash
    WHERE d.level = 3;

    -- Level 4: case dirs
    INSERT IGNORE INTO oc_filecache
        (storage, path, path_hash, parent, name,
         mimetype, mimepart, size, mtime, storage_mtime,
         encrypted, unencrypted_size, etag, permissions, checksum)
    SELECT
        v_storage_id, d.path, d.path_hash,
        p.fileid,     d.name,
        v_mime_dir, v_mime_dir_part,
        0, v_now, v_now, 0, 0,
        LOWER(REPLACE(UUID(), '-', '')), d.permissions, ''
    FROM _oc_desired d
    JOIN oc_filecache p
      ON p.storage   = v_storage_id
     AND p.path_hash = d.parent_path_hash
    WHERE d.level = 4;

    -- Level 5: files (resolve MIME types)
    INSERT IGNORE INTO oc_filecache
        (storage, path, path_hash, parent, name,
         mimetype, mimepart, size, mtime, storage_mtime,
         encrypted, unencrypted_size, etag, permissions, checksum)
    SELECT
        v_storage_id, d.path, d.path_hash,
        p.fileid,     d.name,
        COALESCE(mt.id,  v_mime_dir),
        COALESCE(mtp.id, v_mime_dir_part),
        d.file_size, v_now, v_now, 0, 0,
        LOWER(REPLACE(UUID(), '-', '')), d.permissions, ''
    FROM _oc_desired d
    JOIN oc_filecache p
      ON p.storage   = v_storage_id
     AND p.path_hash = d.parent_path_hash
    LEFT JOIN oc_mimetypes mt  ON mt.mimetype  = d.mime_str
    LEFT JOIN oc_mimetypes mtp ON mtp.mimetype = SUBSTRING_INDEX(d.mime_str, '/', 1)
    WHERE d.level = 5;

    -- ── Update ceiling permissions and file sizes where they changed ──────────
    UPDATE oc_filecache fc
    JOIN   _oc_desired d ON d.path_hash = fc.path_hash
    SET
        fc.permissions = d.permissions,
        fc.size        = CASE WHEN d.is_dir = 0 THEN d.file_size ELSE fc.size END
    WHERE
        fc.storage = v_storage_id
        AND (
            fc.permissions <> d.permissions
            OR (d.is_dir = 0 AND fc.size <> d.file_size)
        );

    -- ════════════════════════════════════════════════════════════════════════
    -- 4. Remove stale oc_filecache entries
    -- ════════════════════════════════════════════════════════════════════════

    DELETE fc
    FROM   oc_filecache fc
    LEFT JOIN _oc_desired d ON d.path_hash = fc.path_hash
    WHERE  fc.storage = v_storage_id
      AND  d.path_hash IS NULL;

    COMMIT;

    DROP TEMPORARY TABLE IF EXISTS _oc_desired;
    DROP TEMPORARY TABLE IF EXISTS _oc_write_profiles;

END
        ");

        $output->info('opencase_sync_filecache installed successfully');
    }

    private function installRecalcPrivileges(IOutput $output): void {
        $output->info('Installing opencase_recalc_privileges stored procedure');

        $this->db->executeStatement('DROP PROCEDURE IF EXISTS opencase_recalc_privileges');

        $this->db->executeStatement("
CREATE PROCEDURE opencase_recalc_privileges()
proc: BEGIN

    DECLARE done1           INT DEFAULT FALSE;
    DECLARE v_user_id       VARCHAR(64);
    DECLARE v_foelsomhed_raw TEXT;
    DECLARE v_kle_raw       TEXT;
    DECLARE v_orgenhed_raw  TEXT;
    DECLARE v_remaining     TEXT;
    DECLARE v_token         VARCHAR(255);
    DECLARE v_pos           INT;
    DECLARE v_now           DATETIME DEFAULT NOW();

    DECLARE cur_write CURSOR FOR
        SELECT user_id, foelsomhed_raw, kle_raw, orgenhed_raw
        FROM   oc_opencase_user_priv_groups
        WHERE  privilege_type = 'opencaseuser';

    DECLARE cur_read CURSOR FOR
        SELECT user_id, foelsomhed_raw, kle_raw, orgenhed_raw
        FROM   oc_opencase_user_priv_groups
        WHERE  privilege_type = 'opencasereaduser';

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done1 = TRUE;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        DROP TEMPORARY TABLE IF EXISTS _oc_split_sens;
        DROP TEMPORARY TABLE IF EXISTS _oc_split_kle;
        DROP TEMPORARY TABLE IF EXISTS _oc_split_org;
        DROP TEMPORARY TABLE IF EXISTS _oc_priv_match_new;
        RESIGNAL;
    END;

    -- ════════════════════════════════════════════════════════════════════════
    -- 0. Scratch tables
    -- ════════════════════════════════════════════════════════════════════════

    DROP TEMPORARY TABLE IF EXISTS _oc_split_sens;
    CREATE TEMPORARY TABLE _oc_split_sens (val VARCHAR(64)) ENGINE=MEMORY;

    DROP TEMPORARY TABLE IF EXISTS _oc_split_kle;
    CREATE TEMPORARY TABLE _oc_split_kle (val VARCHAR(255)) ENGINE=MEMORY;

    DROP TEMPORARY TABLE IF EXISTS _oc_split_org;
    CREATE TEMPORARY TABLE _oc_split_org (val VARCHAR(64)) ENGINE=MEMORY;

    -- Deduplicated per (user, sensitivity, kle pattern, org) — same key used by
    -- oc_opencase_user_priv_match. Write-pass rows are inserted before read-pass
    -- rows via INSERT IGNORE, so a write group always wins a tuple collision,
    -- mirroring PrivilegeService::rebuildMatchTable()'s write-first ordering.
    DROP TEMPORARY TABLE IF EXISTS _oc_priv_match_new;
    CREATE TEMPORARY TABLE _oc_priv_match_new (
        user_id          VARCHAR(64)  NOT NULL,
        sensitivity_uuid VARCHAR(64)  NOT NULL,
        kle_pattern      VARCHAR(255) NOT NULL,
        org_uuid         VARCHAR(64)  NOT NULL DEFAULT '',
        access_level     VARCHAR(8)   NOT NULL,
        PRIMARY KEY (user_id, sensitivity_uuid, kle_pattern, org_uuid)
    ) ENGINE=InnoDB;

    -- ════════════════════════════════════════════════════════════════════════
    -- 1. Pass 1 — opencaseuser groups (write)
    -- ════════════════════════════════════════════════════════════════════════

    OPEN cur_write;
    write_loop: LOOP
        FETCH cur_write INTO v_user_id, v_foelsomhed_raw, v_kle_raw, v_orgenhed_raw;
        IF done1 THEN
            LEAVE write_loop;
        END IF;

        DELETE FROM _oc_split_sens;
        DELETE FROM _oc_split_kle;
        DELETE FROM _oc_split_org;

        -- foelsomhed: comma-separated sensitivity UUIDs, verbatim
        SET v_remaining = v_foelsomhed_raw;
        WHILE v_remaining IS NOT NULL AND v_remaining <> '' DO
            SET v_pos = LOCATE(',', v_remaining);
            IF v_pos > 0 THEN
                SET v_token = TRIM(SUBSTRING(v_remaining, 1, v_pos - 1));
                SET v_remaining = SUBSTRING(v_remaining, v_pos + 1);
            ELSE
                SET v_token = TRIM(v_remaining);
                SET v_remaining = '';
            END IF;
            IF v_token <> '' THEN
                INSERT INTO _oc_split_sens (val) VALUES (v_token);
            END IF;
        END WHILE;

        -- kle: comma-separated patterns, converted to SQL LIKE syntax
        -- ('*' → '%', trailing '.*' → '.%'), matching PrivilegeService::toSqlLikePattern()
        SET v_remaining = v_kle_raw;
        WHILE v_remaining IS NOT NULL AND v_remaining <> '' DO
            SET v_pos = LOCATE(',', v_remaining);
            IF v_pos > 0 THEN
                SET v_token = TRIM(SUBSTRING(v_remaining, 1, v_pos - 1));
                SET v_remaining = SUBSTRING(v_remaining, v_pos + 1);
            ELSE
                SET v_token = TRIM(v_remaining);
                SET v_remaining = '';
            END IF;
            IF v_token = '*' THEN
                SET v_token = '%';
            ELSEIF RIGHT(v_token, 2) = '.*' THEN
                SET v_token = CONCAT(LEFT(v_token, LENGTH(v_token) - 1), '%');
            END IF;
            IF v_token <> '' THEN
                INSERT INTO _oc_split_kle (val) VALUES (v_token);
            END IF;
        END WHILE;

        -- orgenhed: comma-separated org UUIDs, empty/NULL means 'any org' ('')
        IF v_orgenhed_raw IS NULL OR v_orgenhed_raw = '' THEN
            INSERT INTO _oc_split_org (val) VALUES ('');
        ELSE
            SET v_remaining = v_orgenhed_raw;
            WHILE v_remaining IS NOT NULL AND v_remaining <> '' DO
                SET v_pos = LOCATE(',', v_remaining);
                IF v_pos > 0 THEN
                    SET v_token = TRIM(SUBSTRING(v_remaining, 1, v_pos - 1));
                    SET v_remaining = SUBSTRING(v_remaining, v_pos + 1);
                ELSE
                    SET v_token = TRIM(v_remaining);
                    SET v_remaining = '';
                END IF;
                IF v_token <> '' THEN
                    INSERT INTO _oc_split_org (val) VALUES (v_token);
                END IF;
            END WHILE;
        END IF;

        -- Empty sensitivity or kle list ⇒ no tuples for this group (matches
        -- PrivilegeService: both lists must be non-empty to produce a match row)
        INSERT IGNORE INTO _oc_priv_match_new (user_id, sensitivity_uuid, kle_pattern, org_uuid, access_level)
        SELECT v_user_id, s.val, k.val, o.val, 'write'
        FROM _oc_split_sens s
        CROSS JOIN _oc_split_kle k
        CROSS JOIN _oc_split_org o;
    END LOOP;
    CLOSE cur_write;

    SET done1 = FALSE;

    -- ════════════════════════════════════════════════════════════════════════
    -- 2. Pass 2 — opencasereaduser groups (read), same expansion as pass 1
    -- ════════════════════════════════════════════════════════════════════════

    OPEN cur_read;
    read_loop: LOOP
        FETCH cur_read INTO v_user_id, v_foelsomhed_raw, v_kle_raw, v_orgenhed_raw;
        IF done1 THEN
            LEAVE read_loop;
        END IF;

        DELETE FROM _oc_split_sens;
        DELETE FROM _oc_split_kle;
        DELETE FROM _oc_split_org;

        SET v_remaining = v_foelsomhed_raw;
        WHILE v_remaining IS NOT NULL AND v_remaining <> '' DO
            SET v_pos = LOCATE(',', v_remaining);
            IF v_pos > 0 THEN
                SET v_token = TRIM(SUBSTRING(v_remaining, 1, v_pos - 1));
                SET v_remaining = SUBSTRING(v_remaining, v_pos + 1);
            ELSE
                SET v_token = TRIM(v_remaining);
                SET v_remaining = '';
            END IF;
            IF v_token <> '' THEN
                INSERT INTO _oc_split_sens (val) VALUES (v_token);
            END IF;
        END WHILE;

        SET v_remaining = v_kle_raw;
        WHILE v_remaining IS NOT NULL AND v_remaining <> '' DO
            SET v_pos = LOCATE(',', v_remaining);
            IF v_pos > 0 THEN
                SET v_token = TRIM(SUBSTRING(v_remaining, 1, v_pos - 1));
                SET v_remaining = SUBSTRING(v_remaining, v_pos + 1);
            ELSE
                SET v_token = TRIM(v_remaining);
                SET v_remaining = '';
            END IF;
            IF v_token = '*' THEN
                SET v_token = '%';
            ELSEIF RIGHT(v_token, 2) = '.*' THEN
                SET v_token = CONCAT(LEFT(v_token, LENGTH(v_token) - 1), '%');
            END IF;
            IF v_token <> '' THEN
                INSERT INTO _oc_split_kle (val) VALUES (v_token);
            END IF;
        END WHILE;

        IF v_orgenhed_raw IS NULL OR v_orgenhed_raw = '' THEN
            INSERT INTO _oc_split_org (val) VALUES ('');
        ELSE
            SET v_remaining = v_orgenhed_raw;
            WHILE v_remaining IS NOT NULL AND v_remaining <> '' DO
                SET v_pos = LOCATE(',', v_remaining);
                IF v_pos > 0 THEN
                    SET v_token = TRIM(SUBSTRING(v_remaining, 1, v_pos - 1));
                    SET v_remaining = SUBSTRING(v_remaining, v_pos + 1);
                ELSE
                    SET v_token = TRIM(v_remaining);
                    SET v_remaining = '';
                END IF;
                IF v_token <> '' THEN
                    INSERT INTO _oc_split_org (val) VALUES (v_token);
                END IF;
            END WHILE;
        END IF;

        INSERT IGNORE INTO _oc_priv_match_new (user_id, sensitivity_uuid, kle_pattern, org_uuid, access_level)
        SELECT v_user_id, s.val, k.val, o.val, 'read'
        FROM _oc_split_sens s
        CROSS JOIN _oc_split_kle k
        CROSS JOIN _oc_split_org o;
    END LOOP;
    CLOSE cur_read;

    -- ════════════════════════════════════════════════════════════════════════
    -- 3. Commit the rebuilt match table, then recompute privilege-sourced grants
    --    in one set-based pass per access level (write first, so INSERT IGNORE
    --    on the (user_id, access_profile_id) unique key lets write win ties —
    --    mirrors PrivilegeService::rebuildUserAccess()).
    -- ════════════════════════════════════════════════════════════════════════

    START TRANSACTION;

    DELETE FROM oc_opencase_user_priv_match;

    INSERT INTO oc_opencase_user_priv_match (user_id, sensitivity_uuid, kle_pattern, org_uuid, access_level)
    SELECT user_id, sensitivity_uuid, kle_pattern, org_uuid, access_level
    FROM _oc_priv_match_new;

    DELETE FROM oc_opencase_user_access WHERE grant_source = 'privilege';

    INSERT IGNORE INTO oc_opencase_user_access
        (user_id, access_profile_id, access_level, granted_at, granted_by, grant_source)
    SELECT DISTINCT upm.user_id, ap.id, 'write', v_now, 'system', 'privilege'
    FROM   oc_opencase_user_priv_match upm
    JOIN   oc_opencase_access_profiles ap
           ON ap.sensitivity_uuid = upm.sensitivity_uuid
          AND (upm.org_uuid = '' OR upm.org_uuid = ap.org_uuid)
    JOIN   oc_opencase_class_subject cs
           ON cs.uuid = ap.class_subject_uuid
          AND cs.code LIKE upm.kle_pattern
    WHERE  upm.access_level = 'write'
      AND  ap.sensitivity_uuid IS NOT NULL
      AND  ap.class_subject_uuid IS NOT NULL;

    INSERT IGNORE INTO oc_opencase_user_access
        (user_id, access_profile_id, access_level, granted_at, granted_by, grant_source)
    SELECT DISTINCT upm.user_id, ap.id, 'read', v_now, 'system', 'privilege'
    FROM   oc_opencase_user_priv_match upm
    JOIN   oc_opencase_access_profiles ap
           ON ap.sensitivity_uuid = upm.sensitivity_uuid
          AND (upm.org_uuid = '' OR upm.org_uuid = ap.org_uuid)
    JOIN   oc_opencase_class_subject cs
           ON cs.uuid = ap.class_subject_uuid
          AND cs.code LIKE upm.kle_pattern
    WHERE  upm.access_level = 'read'
      AND  ap.sensitivity_uuid IS NOT NULL
      AND  ap.class_subject_uuid IS NOT NULL;

    COMMIT;

    DROP TEMPORARY TABLE IF EXISTS _oc_split_sens;
    DROP TEMPORARY TABLE IF EXISTS _oc_split_kle;
    DROP TEMPORARY TABLE IF EXISTS _oc_split_org;
    DROP TEMPORARY TABLE IF EXISTS _oc_priv_match_new;

END
        ");

        $output->info('opencase_recalc_privileges installed successfully');
    }
}
