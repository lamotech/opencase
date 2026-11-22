# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- Certificate passwords are now stored encrypted with `OCP\Security\ICrypto` instead of in plain text. Existing passwords are encrypted automatically on upgrade.
- The certificate password is no longer sent to the browser on the admin settings page.
- Certificate files are copied into OpenCase's data folder (IAppData) when registered, instead of being read from an arbitrary path. Existing certificates are copied automatically on upgrade.
- Removed `POST /settings/certificate/validate`, which accepted any file path and password. The admin settings now show the registered certificates, described server-side.
- `occ opencase:register-certificate` offers to delete the original `.p12` file after registration.
- Mailbox passwords for import locations are now stored encrypted. Existing passwords are encrypted automatically on upgrade.
- The secret config values `process_engine_password`, `redaction_service_api_key`, `sftp_ssh_key_pass_phrase`, `datafordeler_apikey_cvr` and `datafordeler_apikey_estate` are now stored encrypted, and are no longer sent to the browser. The admin settings fields are blank and show "Gemt" when a value is set; leaving a field blank keeps the stored value. Existing values are encrypted automatically on upgrade.
- The config API (`/api/v1/config`) for OpenCase Administrators no longer returns secret values and rejects changes to them. They can only be changed by Nextcloud admins in the administration settings or with `occ opencase:config:set`.
- `occ opencase:config:list` shows `********` for secret values, and `occ opencase:config:set` no longer prints them. `occ opencase:config:get` still prints the decrypted value.

### Added

- First release
- Process module (phase 1): process designer (bpmn-js) under **Administrer processer**, with drafts, versioning, publishing to the Flowable process engine (`process-engine/`) and scoping by case type, organisation and KLE number. New role *Process Designer* (`opencaseprocessadministrator`) and feature flag `feature_process-engine`.
- Process module (phase 2): processes run on cases. New **Processer** tab on the case to start and cancel processes, take, release and complete tasks (with outcomes and comments) and see the diagram with the current step highlighted. Process tasks are shown under *Foreslået arbejde*, in the employee task list and in the case log, and assignees are notified. A background job (`ProcessSyncJob`) keeps OpenCase's copy of running processes in step with the engine. The designer gets an outcome editor for user tasks.
- Closing a case cancels its running processes (reason "Sagen er lukket"). If the process engine is unreachable at that moment, the case still closes and `ProcessSyncJob` cancels the processes once the engine is back.
- Process module (phase 3): automatic steps. BPMN service tasks run as Flowable external workers by OpenCase (`ProcessWorker`, `ProcessWorkerJob`): *Opret journalnotat*, *Opret dokument* (optionally from a template) and *Luk sagen*, configured in the designer and validated on publish. Steps run as the case responsible (else the process starter); failed steps stop the process and can be retried from the case. Daily deadline notifications for process tasks (`ProcessTaskDeadlineNotificationJob`).

- Process module (phase 4): form fields on user tasks (text, integer, date, yes/no; strictly validated by OpenCase, stored as process variables and shown in the case history); moving running processes to the published version with mappings for removed steps (`ProcessMigrationService`, `ProcessMigrationJob` for more than 25); statistics per process and running counts in the process list.
- Process module: automatic action **Send Digital Post** (`opencase.sendDigitalPost`): sends the document of the process's latest *Opret dokument* step (else the newest case document in a chosen category) to the document's *Modtager* contacts, else the case's primary party, with the same subscription lookup and shipment as the manual dialog (now shared in `DigitalPostShipmentService`). Only offered, and publishable, when `feature_digital-post` is on.
- Process module: **Sag** setting on *Opret journalnotat*, *Opret dokument* and *Send Digital Post*: the case itself, or the sub-case created by the nearest *Opret undersag* step before the step in the same branch (an explicit error after branches are joined). *Send Digital Post* takes the document of the nearest *Opret dokument* step in its branch. Steps made ready by another step now run in the same worker run instead of up to 5 minutes later.
- Process module: automatic action **Vent på inaktivitet** (`opencase.waitForInactivity`): waits until the case (or branch's sub-case) has had no activity for N days and the step was reached at least N days ago; meanwhile the job is handed back to the engine until the wait can be over, without using retries. Timer settings in the designer (after a period, repeat every, at a fixed time, expression; interrupting or not for timers on tasks), with publish checks for timers and for plain start events.
- Adding or changing documents, files, document notes and relations now counts as activity on the case: it updates the case's `updated_at`, so the case is re-sent to SAPA (also for file contents saved through Office).
- Process module: automatic action **Send notifikation** (`opencase.sendNotification`): a Nextcloud notification linking to the case (or the branch's sub-case) to the responsible caseworker, the other caseworkers or a chosen employee; only recipients with read access get it, the others are shown as skipped. Action parameters can now depend on another parameter (`when`).
- Process module: automatic action **Opret undersag** (`opencase.createSubCase`): creates a sub-case with the case's organisation, KLE number, facet, sensitivity, insight level and responsible (title with `{sagsnummer}`/`{sagstitel}`, optional case type), and optionally starts another process on it. The case history links to the sub-case.
- Process module: automatic actions **Vent på dokument** (`opencase.waitForDocument`: waits for a document — created, received by an inbound job, or moved from the inbox — on the case after the step is reached, optionally in a category; later steps use it as the process's document), **Send til godkendelse** (`opencase.requestApproval`: starts the document review/approval workflow for a chosen employee, the responsible or the other caseworkers, waits for it and stores `godkendt`/`afvist`/`gennemset`/`annulleret` in `udfald`; offered as quick conditions after a gateway) and **Gør dokument endeligt** (`opencase.finalizeDocument`: sets status *Endelig* and makes the files read-only). *Send Digital Post* now also takes the document of a preceding *Vent på dokument* step.
- Process module: automatic actions **Tildel sag** (`opencase.assignCase`: make a user the responsible caseworker or add them as caseworker — chosen in the designer or taken from a task's *Medarbejder* field), **Udfyld tillægsfelt** (`opencase.setCustomField`: set a custom field to a fixed value or a task field's value, converted and checked against the field type) and **Tilføj modtager** (`opencase.addRecipient`: add the primary party or all parties with CPR/CVR as *Modtager* on the process's document). New task form field type **Medarbejder** (employee picker; a string marked `fieldType="user"` in the BPMN). Publishing checks that referenced task fields exist. Adding a caseworker is now shared in `CaseWorkerService`.
- Process module: automatic action **Opret påmindelse** (`opencase.createReminder`): an ordinary reminder on the case or the process's document for the responsible, a chosen employee or a task's *Medarbejder* field, with a deadline N days after the step, from a task's date field, or none; notifies the recipient and grants write access like the reminder dialog.
- Process designer: publishing is refused for BPMN elements OpenCase can't run (send, receive, business rule and script tasks, call activities, transactions, ad-hoc sub-processes, message catch events), with a Danish explanation and an alternative; plain and manual tasks give a warning in the publish dialog (`warnings` in the validate response).
- Documents received by Digital Post, Fordelingskomponenten and import are logged as *Dokument oprettet* (by `system`), so they show in the case log and count as activity.
- "Ny sag" dialog: optional **Proces** field (processes matching the chosen case type, organisation and KLE number, `GET /api/v1/processes/available`); the process is started once the case and its parties, caseworkers and custom fields are saved.

### Changed

- Digital Post is enabled by its own feature flag `feature_digital-post` (default `0`) instead of `enterprise_version`, like `feature_redaction-service` and `feature_process-engine`. The Serviceplatformen components still ship only in the Enterprise package, and `enterprise/install.sh` sets the flag to 1. Sending a shipment is now also refused server-side when the flag is off.

### Security

- Process designer: publishing only accepts expressions that compare process variables and the Flowable extensions the designer uses. Listeners, delegate classes and method calls in expressions are refused: they could run code in the process engine. The engine no longer exposes Spring beans to expressions; rebuild its image.
- Moving a case to another access group or FK profile updates the search index and filecache permissions. File search also checks access against the database, so the old group no longer finds the files. Cases moved before this release need a reindex to be found by their new group.
- `/citizen/cases`, `/citizen/documents`, `/company/cases` and `/company/documents` only return what the user can read. Without the CPR/CVR registers, citizen and company search only covers cases the user can read.
- Only administrators can register any group as access group. From case forms and the API, a group must already be registered or be named with the access group prefix.
- App passwords from the desktop login and the MS Office add-ins expire a fixed time after login, also while in use: 720 hours (30 days) by default, set by `desktop_token_lifetime_hours` in `opencase_config`. Users, privileges and groups are only updated at login, so a user removed in the identity provider could otherwise keep working in these clients. Existing app passwords are not changed.
- Phrases: users who aren't template managers no longer get draft and archived phrases, and in the desktop API a phrase with scopes only together with a case it matches (`documentId`/`caseId` on `GET /phrases`, `/phrases/{id}`, `/content` and `/file`; without one, only phrases without scopes). The web endpoints `GET /phrases`, `/phrases/{id}/templates` and `/phrases/{id}/download` are for template managers only.
- Process tasks: the notification about a new task only goes to an assignee with write access to the case, so case number and title aren't shown to others.
- Desktop: opening a file in the editor is logged as a document view and a file view in the audit log, like in the web app. Document lists aren't logged, also as in the web app.

### Fixed

- The case's responsible user and users with a direct write grant can edit files in Office also when nobody has write access through the case's access profile. The files used to open read-only after the filecache repair job had run.
- *Opret dokument* with a template now creates the letter like the "Ny fil fra skabelon" dialog: with required and pre-selected phrases and the organisation's logo, and it is refused if the template isn't available for the case's organisation.
- The default role groups `OpenCase Role - <rolle>` only give roles with Grupper, and only until **Roller fra grupper** is saved. With FK Adgang such a group no longer gives a role, so whoever can create groups can't make themselves Administrator there. Once saved, a role only has the groups saved for it; at upgrade, existing default groups for roles a saved mapping doesn't mention are added to it.
- A deleted access group's cases don't go to a new group with the same id. The cases stay on the old profile until an administrator adds the group under **Adgangsgrupper**, which gives its members the cases again. Cases can still be edited and moved to another group.
- Emneplan: a case can be edited, also through the public API, and get a sub-case after its item has been deactivated. Only a new item has to be active.
- Emneplan: **Konfiguration** and separation sheets can be saved when their item has been deactivated. The inbox case keeps its item.
- Emneplan: an item can't be deleted while phrases, templates, processes, custom fields, separation sheets or the setting for incoming documents point at it. They would silently stop matching. The message says what uses the item.
- Desktop: if the Digital Post lookup fails for a receiver, nothing is sent and the user can try again (`503`). The letter used to go as physical post.
- A document can only be sent with Digital Post once, also from the web API, and two requests at the same time can't both pass. *Send Digital Post* in a process moves on without sending again and shows this at the step. The step has a new setting **Medtag bilag** (on by default, also for processes already published).
- A process task assigned to someone without write access to the case, or who loses it, no longer stops the process: it is given back for others to take, and the case's responsible caseworker is notified.
- The process variable `caseResponsible` was only set when a process started, so after the case got a new responsible (on the case or with *Tildel sag*) later tasks for "Sagens ansvarlige sagsbehandler" still went to the previous one. Changing the responsible now updates running processes (`CaseResponsibleChangedEvent`).
- Parallel automatic steps (several service tasks active at once, e.g. after a parallel gateway) were never run: Flowable hands out no *exclusive* external-worker job of a process while it has several. Automatic steps are now saved and published with `flowable:exclusive="false"`; running processes on an older version can be moved to the new version to continue. Reporting a finished step to the engine no longer marks the step failed if that call fails, so the action isn't repeated.
- The participant role "Primær part" / "Primary party" (id 0) was missing on new installations, although the "Ny sag" dialog adds the case's party with it. It is now seeded (also on upgrade, by the SeedReferenceData repair step).

## [1.2.1] - 2026-10-28

### Added

- Brevdesigner (document automation), phase 1: "Administrer skabeloner" is
  now "Administrer brevdesigner", with tabs for phrase categories, phrases
  and templates.
- Phrase library: hierarchical phrase categories; phrases with a status
  (draft / active / archived), scopes by case type, organisation and KLE
  number, and content as a Word/ODF file or as rich text edited in OpenCase
  (headings, lists, bold, italic, underline, quotes).
- Phrases on templates: attach phrases to a template, ordered and marked
  mandatory or pre-selected. Creating a file from a template lets the user
  choose phrases; they are merged into `{{fraser.indhold}}` with their
  formatting, styles, lists and images. Only active phrases whose scopes
  match the case are offered and merged.
- Templates can be limited to organisations: they are then offered only for
  cases in those organisations (and below), and only matching phrases can be
  attached.
- Organisation logos: upload a logo per organisation on the organisation
  page; it is merged into `{{organisation.logo}}` (as text or as the alt
  text of a picture placeholder), falling back to the parent organisation's.
- Merge fields: `{{organisation.email|telefon|adresse}}`,
  `{{sag.ansvarlig.email|telefon|adresse}}`, `{{sag.indsigtsgrad}}`,
  `{{sag.handlingsfacet}}`, `{{dokument.kategori}}`,
  `{{dokument.dokumentdato}}`, `{{dokument.modtagetdato}}`,
  `{{dokument.registreretdato}}`, `{{dokument.indsigtsgrad}}` (English
  forms too), listed on the template fields page.
- Public API: `GET /templates` returns `OrgUuids`.

### Changed

- Template and phrase files are stored only in
  `<data dir>/opencase_storage/_templates` and `_phrases` and edited there in
  place in Nextcloud Office; users no longer get their own copies. Existing
  copies are copied back to the storage on upgrade.
- Template uploads are limited to `.docx`, `.odt`, `.xlsx` and `.ods`.
- Creating a template, its organisations and phrases happens in one request.
- Public API: creating a file from a template merges the template's
  pre-selected phrases and logo, and is refused for templates not available
  for the case's organisation.

### Fixed

- Merging into ODT templates no longer corrupts the file when a field was
  split by formatting, and stray `{` in a template (e.g. in an embedded SVG
  picture) no longer swallows following text.
- Creating a blank template or opening one for editing now requires the
  Administrator or Template Designer role.
