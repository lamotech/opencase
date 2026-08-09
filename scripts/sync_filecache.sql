-- =============================================================================
-- OpenCase filecache sync — shared storage model, date-based virtual paths
-- =============================================================================
-- Keeps the NC system table oc_filecache consistent with the opencase data
-- model.  All files share ONE storage row (id = 'opencase') in oc_storages.
--
-- Virtual path structure (immutable — derived from cases.created_at):
--
--   ''                                              level 0  root
--   {year}                                          level 1  e.g. 2026
--   {year}/{month}                                  level 2  e.g. 2026/03
--   {year}/{month}/{day}                            level 3  e.g. 2026/03/01
--   {year}/{month}/{day}/{case_number}              level 4  e.g. 2026/03/01/2026-001
--   {year}/{month}/{day}/{case_number}/{filename}   level 5  e.g. …/Contract.docx
--
-- Per-user access control is enforced at query time by OpenCaseCacheWrapper
-- (PROPFIND / file browser) and OpenCasePermissionWrapper (file I/O).
-- This script does NOT touch oc_mounts — mounts are registered dynamically
-- by OpenCaseMountProvider when a user logs in.
--
-- Usage:
--   # Load (once, or after procedure changes):
--   docker exec -i master-database-mysql-1 mysql -u nextcloud -pnextcloud nextcloud \
--     < apps-extra/opencase/scripts/sync_filecache.sql
--
--   # Run sync:
--   docker exec -i master-database-mysql-1 mysql -u nextcloud -pnextcloud nextcloud \
--     -e "CALL opencase_sync_filecache();"
--
-- Compatible with MySQL 5.7+ and MariaDB 10.1+.
-- Safe to run repeatedly (INSERT IGNORE / ON DUPLICATE KEY UPDATE).
-- =============================================================================

DELIMITER $$

-- ---------------------------------------------------------------------------
-- Main procedure
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS opencase_sync_filecache$$
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
    --
    --    Six virtual levels (no per-user duplication):
    --      0  ''                                          root
    --      1  {year}                                     year directory
    --      2  {year}/{month}                             month directory
    --      3  {year}/{month}/{day}                       day directory
    --      4  {year}/{month}/{day}/{case_number}         case directory
    --      5  {year}/{month}/{day}/{case_number}/{file}  file
    --
    --    Permissions stored here are the CEILING (maximum any user could have).
    --    OpenCaseCacheWrapper masks them down to the individual user's rights.
    --
    --    Levels 0-3 (directories): permissions = 1 (READ — not writable).
    --    Level 4 (case dir): permissions = 7 (READ|UPDATE|CREATE) when any
    --      user has write access to that case's access profile; else 1 (READ).
    --    Level 5 (file): permissions = 3 (READ|UPDATE) when any user has
    --      write access; else 1 (READ).
    --
    --    Cases with NULL created_at are excluded — they have no virtual path.
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

    -- Pre-compute the set of access profiles that have at least one WRITE user.
    -- Used at levels 4 and 5 to set ceiling permissions without a correlated
    -- subquery per row (which would execute 4 M + 10 M times at scale).
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
    -- permissions = 7 (READ|UPDATE|CREATE) when any user has write access to
    -- this case's access profile; otherwise 1 (READ).
    -- LEFT JOIN _oc_write_profiles replaces the per-row correlated EXISTS.
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
    -- All files are included regardless of per-user deny overrides.
    -- The cache wrapper filters denied files at query time.
    -- permissions = 3 (READ|UPDATE) when any user has write access AND the
    -- document is not Final; otherwise 1 (READ).
    -- LEFT JOIN _oc_write_profiles replaces the per-row correlated EXISTS.
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
    --    (top-down guarantees parent row exists before child is inserted)
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
    --    Any path in the 'opencase' storage not in the desired set is stale
    --    (deleted case, file moved between days, etc.). Remove it.
    -- ════════════════════════════════════════════════════════════════════════

    -- LEFT JOIN is more efficient than NOT EXISTS for large tables: the engine
    -- can use the path_hash index on _oc_desired rather than a full anti-join.
    DELETE fc
    FROM   oc_filecache fc
    LEFT JOIN _oc_desired d ON d.path_hash = fc.path_hash
    WHERE  fc.storage = v_storage_id
      AND  d.path_hash IS NULL;

    COMMIT;

    DROP TEMPORARY TABLE IF EXISTS _oc_desired;
    DROP TEMPORARY TABLE IF EXISTS _oc_write_profiles;

END$$

-- ---------------------------------------------------------------------------
-- One-time migration: clean up old per-user opencase::{uid} storages
--
-- Run this ONCE after deploying the shared-storage model to remove the old
-- per-user storage rows and their filecache entries.
-- After running, disable/enable the app to refresh NC's app-type cache.
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS opencase_migrate_to_shared$$
CREATE PROCEDURE opencase_migrate_to_shared()
BEGIN
    -- Remove old per-user filecache entries
    DELETE fc
    FROM   oc_filecache fc
    JOIN   oc_storages s ON s.numeric_id = fc.storage
    WHERE  s.id LIKE 'opencase::%';

    -- Remove old per-user mounts
    DELETE m
    FROM   oc_mounts m
    JOIN   oc_storages s ON s.numeric_id = m.storage_id
    WHERE  s.id LIKE 'opencase::%';

    -- Remove old per-user storage rows
    DELETE FROM oc_storages
    WHERE  id LIKE 'opencase::%';
END$$

DELIMITER ;
