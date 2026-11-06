-- =============================================================================
-- OpenCase Brevdesigner — phrase categories and phrases (without files)
-- =============================================================================
-- Exported 2026-10-28 from https://dev04.opencase.dk — 11 categories, 9 phrases.
--
-- Contains the phrase categories (oc_opencase_phrase_categories), the phrases
-- whose text is stored in OpenCase (oc_opencase_phrases.content) and their
-- scopes (oc_opencase_phrases_scope). Phrases whose content is a .docx/.odt
-- file are NOT included — their files live in opencase_storage/_phrases.
--
-- Safe to run on an environment that already has data, and to run again:
--   * no ids are hard-coded; new ids are taken from the inserted rows
--   * a category that already exists (same name under the same parent) and a
--     phrase that already exists (same title in the same category) are skipped
--   * an organisation scope is only inserted when that organisation exists
--     (oc_opencase_org) on the target
--
-- Requires OpenCase 1.2.1 or later (migrations 12 and 16: phrase tables and
-- the phrases.content column). Tables use the prefix oc_ — replace it if the
-- target uses another dbtableprefix.
--
-- Usage:
--   mysql -u nextcloud -p nextcloud < brevdesigner_phrases.sql
--
-- Compatible with MySQL 5.7+ and MariaDB 10.2+.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- -----------------------------------------------------------------------------
-- Phrase categories (parents before children)
-- -----------------------------------------------------------------------------

-- Fælles fraser
INSERT INTO oc_opencase_phrase_categories (name, description, parent_id, sort_order, created_at, created_by, updated_at, updated_by)
SELECT 'Fælles fraser', NULL, NULL, 0, NOW(), 'admin', NOW(), 'admin'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_opencase_phrase_categories WHERE name = 'Fælles fraser' AND parent_id <=> NULL);
SET @cat_5 = (SELECT id FROM oc_opencase_phrase_categories WHERE name = 'Fælles fraser' AND parent_id <=> NULL ORDER BY id LIMIT 1);

-- Indledning  (under Fælles fraser)
INSERT INTO oc_opencase_phrase_categories (name, description, parent_id, sort_order, created_at, created_by, updated_at, updated_by)
SELECT 'Indledning', NULL, @cat_5, 0, NOW(), 'admin', NOW(), 'admin'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_opencase_phrase_categories WHERE name = 'Indledning' AND parent_id <=> @cat_5);
SET @cat_6 = (SELECT id FROM oc_opencase_phrase_categories WHERE name = 'Indledning' AND parent_id <=> @cat_5 ORDER BY id LIMIT 1);

-- Afslutning  (under Fælles fraser)
INSERT INTO oc_opencase_phrase_categories (name, description, parent_id, sort_order, created_at, created_by, updated_at, updated_by)
SELECT 'Afslutning', NULL, @cat_5, 0, NOW(), 'admin', NOW(), 'admin'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_opencase_phrase_categories WHERE name = 'Afslutning' AND parent_id <=> @cat_5);
SET @cat_7 = (SELECT id FROM oc_opencase_phrase_categories WHERE name = 'Afslutning' AND parent_id <=> @cat_5 ORDER BY id LIMIT 1);

-- Svarfrister  (under Fælles fraser)
INSERT INTO oc_opencase_phrase_categories (name, description, parent_id, sort_order, created_at, created_by, updated_at, updated_by)
SELECT 'Svarfrister', NULL, @cat_5, 0, NOW(), 'admin', NOW(), 'admin'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_opencase_phrase_categories WHERE name = 'Svarfrister' AND parent_id <=> @cat_5);
SET @cat_8 = (SELECT id FROM oc_opencase_phrase_categories WHERE name = 'Svarfrister' AND parent_id <=> @cat_5 ORDER BY id LIMIT 1);

-- Forvaltningsret
INSERT INTO oc_opencase_phrase_categories (name, description, parent_id, sort_order, created_at, created_by, updated_at, updated_by)
SELECT 'Forvaltningsret', NULL, NULL, 0, NOW(), 'admin', NOW(), 'admin'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_opencase_phrase_categories WHERE name = 'Forvaltningsret' AND parent_id <=> NULL);
SET @cat_9 = (SELECT id FROM oc_opencase_phrase_categories WHERE name = 'Forvaltningsret' AND parent_id <=> NULL ORDER BY id LIMIT 1);

-- Partshøring  (under Forvaltningsret)
INSERT INTO oc_opencase_phrase_categories (name, description, parent_id, sort_order, created_at, created_by, updated_at, updated_by)
SELECT 'Partshøring', NULL, @cat_9, 0, NOW(), 'admin', NOW(), 'admin'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_opencase_phrase_categories WHERE name = 'Partshøring' AND parent_id <=> @cat_9);
SET @cat_10 = (SELECT id FROM oc_opencase_phrase_categories WHERE name = 'Partshøring' AND parent_id <=> @cat_9 ORDER BY id LIMIT 1);

-- Afgørelser  (under Forvaltningsret)
INSERT INTO oc_opencase_phrase_categories (name, description, parent_id, sort_order, created_at, created_by, updated_at, updated_by)
SELECT 'Afgørelser', NULL, @cat_9, 0, NOW(), 'admin', NOW(), 'admin'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_opencase_phrase_categories WHERE name = 'Afgørelser' AND parent_id <=> @cat_9);
SET @cat_11 = (SELECT id FROM oc_opencase_phrase_categories WHERE name = 'Afgørelser' AND parent_id <=> @cat_9 ORDER BY id LIMIT 1);

-- Byggesag
INSERT INTO oc_opencase_phrase_categories (name, description, parent_id, sort_order, created_at, created_by, updated_at, updated_by)
SELECT 'Byggesag', NULL, NULL, 0, NOW(), 'admin', NOW(), 'admin'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_opencase_phrase_categories WHERE name = 'Byggesag' AND parent_id <=> NULL);
SET @cat_12 = (SELECT id FROM oc_opencase_phrase_categories WHERE name = 'Byggesag' AND parent_id <=> NULL ORDER BY id LIMIT 1);

-- Byggetilladelser  (under Byggesag)
INSERT INTO oc_opencase_phrase_categories (name, description, parent_id, sort_order, created_at, created_by, updated_at, updated_by)
SELECT 'Byggetilladelser', NULL, @cat_12, 0, NOW(), 'admin', NOW(), 'admin'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_opencase_phrase_categories WHERE name = 'Byggetilladelser' AND parent_id <=> @cat_12);
SET @cat_13 = (SELECT id FROM oc_opencase_phrase_categories WHERE name = 'Byggetilladelser' AND parent_id <=> @cat_12 ORDER BY id LIMIT 1);

-- Borgerservice
INSERT INTO oc_opencase_phrase_categories (name, description, parent_id, sort_order, created_at, created_by, updated_at, updated_by)
SELECT 'Borgerservice', NULL, NULL, 0, NOW(), 'admin', NOW(), 'admin'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_opencase_phrase_categories WHERE name = 'Borgerservice' AND parent_id <=> NULL);
SET @cat_14 = (SELECT id FROM oc_opencase_phrase_categories WHERE name = 'Borgerservice' AND parent_id <=> NULL ORDER BY id LIMIT 1);

-- Manglende oplysninger  (under Borgerservice)
INSERT INTO oc_opencase_phrase_categories (name, description, parent_id, sort_order, created_at, created_by, updated_at, updated_by)
SELECT 'Manglende oplysninger', NULL, @cat_14, 0, NOW(), 'admin', NOW(), 'admin'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_opencase_phrase_categories WHERE name = 'Manglende oplysninger' AND parent_id <=> @cat_14);
SET @cat_15 = (SELECT id FROM oc_opencase_phrase_categories WHERE name = 'Manglende oplysninger' AND parent_id <=> @cat_14 ORDER BY id LIMIT 1);

-- -----------------------------------------------------------------------------
-- Phrases
-- -----------------------------------------------------------------------------

-- Kvittering for modtaget henvendelse  [active]
INSERT INTO oc_opencase_phrases (category_id, title, description, content, status, created_at, created_by, updated_at, updated_by)
SELECT @cat_6, 'Kvittering for modtaget henvendelse', NULL,
       '<p>Vi bekræfter hermed, at vi den  har modtaget din henvendelse vedrørende.</p><p>Din henvendelse er registreret under sagsnummer.</p><p>Vi vil behandle din henvendelse hurtigst muligt og kontakte dig, hvis vi har behov for yderligere oplysninger.</p>',
       'active', NOW(), 'admin', NOW(), 'admin'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_opencase_phrases WHERE title = 'Kvittering for modtaget henvendelse' AND category_id <=> @cat_6);
SET @ph_2 = (SELECT id FROM oc_opencase_phrases WHERE title = 'Kvittering for modtaget henvendelse' AND category_id <=> @cat_6 ORDER BY id LIMIT 1);

-- Henvisning til tidligere korrespondance  [active]
INSERT INTO oc_opencase_phrases (category_id, title, description, content, status, created_at, created_by, updated_at, updated_by)
SELECT @cat_6, 'Henvisning til tidligere korrespondance', NULL,
       '<p>Vi henviser til vores tidligere korrespondance vedrørende.</p><p>På baggrund af de foreliggende oplysninger ønsker vi hermed at orientere dig om den videre behandling af sagen.</p>',
       'active', NOW(), 'admin', NOW(), 'admin'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_opencase_phrases WHERE title = 'Henvisning til tidligere korrespondance' AND category_id <=> @cat_6);
SET @ph_3 = (SELECT id FROM oc_opencase_phrases WHERE title = 'Henvisning til tidligere korrespondance' AND category_id <=> @cat_6 ORDER BY id LIMIT 1);

-- Kontaktoplysninger  [active]
INSERT INTO oc_opencase_phrases (category_id, title, description, content, status, created_at, created_by, updated_at, updated_by)
SELECT @cat_7, 'Kontaktoplysninger', NULL,
       '<p>Har du spørgsmål til dette brev eller til behandlingen af din sag, er du velkommen til at kontakte  på telefon.</p><p>Ved henvendelse bedes du oplyse sagsnummer.</p>',
       'active', NOW(), 'admin', NOW(), 'admin'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_opencase_phrases WHERE title = 'Kontaktoplysninger' AND category_id <=> @cat_7);
SET @ph_4 = (SELECT id FROM oc_opencase_phrases WHERE title = 'Kontaktoplysninger' AND category_id <=> @cat_7 ORDER BY id LIMIT 1);

-- Anmodning om svar inden frist  [active]
INSERT INTO oc_opencase_phrases (category_id, title, description, content, status, created_at, created_by, updated_at, updated_by)
SELECT @cat_8, 'Anmodning om svar inden frist', NULL,
       '<h1><strong>Svarfrist</strong></h1><p><u>Vi beder dig sende dit svar senest den.</u></p><p>Hvis vi ikke modtager dit svar inden fristens udløb, kan vi blive nødt til at behandle sagen på baggrund af de oplysninger, vi allerede har.</p><p><em>Hvis du har behov for mere tid, bedes du kontakte os hurtigst muligt.</em></p>',
       'active', NOW(), 'admin', NOW(), 'admin'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_opencase_phrases WHERE title = 'Anmodning om svar inden frist' AND category_id <=> @cat_8);
SET @ph_5 = (SELECT id FROM oc_opencase_phrases WHERE title = 'Anmodning om svar inden frist' AND category_id <=> @cat_8 ORDER BY id LIMIT 1);

-- Afgørelse – bevilling  [draft]
INSERT INTO oc_opencase_phrases (category_id, title, description, content, status, created_at, created_by, updated_at, updated_by)
SELECT @cat_11, 'Afgørelse – bevilling', NULL,
       '<p>har behandlet din ansøgning vedrørende.</p><p>Vi har besluttet at imødekomme din ansøgning.</p><p>Afgørelsen er truffet på baggrund af de oplysninger, der foreligger i sagen, og efter en konkret vurdering af de relevante forhold.</p><p>Eventuelle vilkår for bevillingen fremgår nedenfor.</p>',
       'draft', NOW(), 'admin', NOW(), 'admin'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_opencase_phrases WHERE title = 'Afgørelse – bevilling' AND category_id <=> @cat_11);
SET @ph_8 = (SELECT id FROM oc_opencase_phrases WHERE title = 'Afgørelse – bevilling' AND category_id <=> @cat_11 ORDER BY id LIMIT 1);

-- Byggetilladelse – standardvilkår  [active]
INSERT INTO oc_opencase_phrases (category_id, title, description, content, status, created_at, created_by, updated_at, updated_by)
SELECT @cat_13, 'Byggetilladelse – standardvilkår', NULL,
       '<p>Byggetilladelsen vedrører ejendommen beliggende.</p><p>Byggearbejdet skal udføres i overensstemmelse med det godkendte projektmateriale og de vilkår, der fremgår af tilladelsen.</p><p>Tilladelsen fritager ikke ansøger for at indhente eventuelle andre nødvendige tilladelser efter gældende lovgivning.</p>',
       'active', NOW(), 'admin', NOW(), 'admin'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_opencase_phrases WHERE title = 'Byggetilladelse – standardvilkår' AND category_id <=> @cat_13);
SET @ph_10 = (SELECT id FROM oc_opencase_phrases WHERE title = 'Byggetilladelse – standardvilkår' AND category_id <=> @cat_13 ORDER BY id LIMIT 1);

-- Anmodning om supplerende oplysninger  [active]
INSERT INTO oc_opencase_phrases (category_id, title, description, content, status, created_at, created_by, updated_at, updated_by)
SELECT @cat_15, 'Anmodning om supplerende oplysninger', NULL,
       '<p>Vi er i gang med at behandle din henvendelse vedrørende.</p><p>For at kunne fortsætte sagsbehandlingen har vi behov for følgende oplysninger:</p><p>Vi beder dig sende oplysningerne senest den.</p><p>Hvis du har spørgsmål eller har vanskeligt ved at fremskaffe oplysningerne, er du velkommen til at kontakte os.</p>',
       'active', NOW(), 'admin', NOW(), 'admin'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_opencase_phrases WHERE title = 'Anmodning om supplerende oplysninger' AND category_id <=> @cat_15);
SET @ph_11 = (SELECT id FROM oc_opencase_phrases WHERE title = 'Anmodning om supplerende oplysninger' AND category_id <=> @cat_15 ORDER BY id LIMIT 1);

-- Org frase - Absalon Team 1  [draft]
INSERT INTO oc_opencase_phrases (category_id, title, description, content, status, created_at, created_by, updated_at, updated_by)
SELECT @cat_6, 'Org frase - Absalon Team 1', NULL,
       '<p>Org frase - Absalon Team 1</p>',
       'draft', NOW(), 'admin', NOW(), 'admin'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_opencase_phrases WHERE title = 'Org frase - Absalon Team 1' AND category_id <=> @cat_6);
SET @ph_27 = (SELECT id FROM oc_opencase_phrases WHERE title = 'Org frase - Absalon Team 1' AND category_id <=> @cat_6 ORDER BY id LIMIT 1);
INSERT INTO oc_opencase_phrases_scope (phrases_id, scope_type, scope_value)
SELECT @ph_27, 'organisation', 'b8eb67c4-1580-473e-a557-9230557b672e'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_opencase_phrases_scope WHERE phrases_id = @ph_27 AND scope_type = 'organisation' AND scope_value = 'b8eb67c4-1580-473e-a557-9230557b672e') AND EXISTS (SELECT 1 FROM oc_opencase_org WHERE org_uuid = 'b8eb67c4-1580-473e-a557-9230557b672e');

-- Org frase - Absalon team 2  [draft]
INSERT INTO oc_opencase_phrases (category_id, title, description, content, status, created_at, created_by, updated_at, updated_by)
SELECT NULL, 'Org frase - Absalon team 2', NULL,
       '<p>Org frase - Absalon team 2</p>',
       'draft', NOW(), 'admin', NOW(), 'admin'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_opencase_phrases WHERE title = 'Org frase - Absalon team 2' AND category_id <=> NULL);
SET @ph_28 = (SELECT id FROM oc_opencase_phrases WHERE title = 'Org frase - Absalon team 2' AND category_id <=> NULL ORDER BY id LIMIT 1);
INSERT INTO oc_opencase_phrases_scope (phrases_id, scope_type, scope_value)
SELECT @ph_28, 'organisation', '65a0643a-cdf9-4410-b659-948a9979514e'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_opencase_phrases_scope WHERE phrases_id = @ph_28 AND scope_type = 'organisation' AND scope_value = '65a0643a-cdf9-4410-b659-948a9979514e') AND EXISTS (SELECT 1 FROM oc_opencase_org WHERE org_uuid = '65a0643a-cdf9-4410-b659-948a9979514e');

COMMIT;
