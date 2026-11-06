# OpenCase

OpenCase is a case and document management system (ESDH) for Danish
municipalities, built as a native Nextcloud app. It provides case handling,
document management with versioning, fine-grained access control, and
full-text search, while integrating with the Joint Municipal Infrastructure
(organisation, classification, CPR/CVR lookups, digital post) and with
Nextcloud's own apps (Files, Mail, Talk, Office).

This README targets **developers** working on the app itself. For
end-user/administrator documentation see [Documentation](#documentation)
below, and for the REST API see [API.md](API.md).

## Features

- Case management with KLE classification codes
- Document management with versioning
- Fine-grained access control based on organisation, classification, and sensitivity
- Full-text search via Elasticsearch with Danish language support
- Native integration with Nextcloud Office (Collabora/ONLYOFFICE)
- Virtual filesystem integration — case files appear in Mail, Talk, and other apps
- Per-user document access overrides
- Comprehensive REST API, including a separate mTLS/token-authenticated
  public API for machine-to-machine integrations (see [API.md](API.md))
- Microsoft Office task-pane add-ins for Outlook/Word/Excel/PowerPoint (see [msoffice/](msoffice/))
- **Enterprise edition** (separate package, not part of the public App Store
  build): Digital post (Kombi Post) sending and receiving, CPR/CVR lookups
  against Datafordeler, and AI-assisted actions — see [enterprise/](enterprise/)

## Tech stack

| Layer | Technology |
|-------|------------|
| Backend | PHP 8.1+, Nextcloud App Framework (OCP), PSR-4 autoloading |
| Frontend | Vue 3, Vuex 4, Vue Router, `@nextcloud/vue` |
| Build | Webpack (`webpack.config.js`), via `@nextcloud/webpack-vue-config` |
| Search | Elasticsearch (Danish analyzer) |
| Auth | Nextcloud session / Bearer tokens, SAML (`onelogin/php-saml`), mTLS for the public receiver API |
| Testing | PHPUnit (`tests/`), Psalm (static analysis) |
| User/admin docs | MkDocs Material (`docs/`, see [mkdocs.yml](mkdocs.yml)) |

## Project structure

```
lib/
├── AppInfo/        Application bootstrap (DI registration, event listeners)
├── BackgroundJob/  Scheduled/queued jobs (sync, reindexing, digital post dispatch)
├── Command/        occ console commands
├── Controller/     REST API controllers, incl. PublicApi/ (see API.md)
├── Dashboard/      Nextcloud dashboard widgets (favorites, my cases, recent, inbound)
├── Db/             Entities and mappers (one pair per table, ~40 tables)
├── Enum/           Shared enums (certificate type, status, sensitivity, ...)
├── Event/          Domain events (e.g. FileUpdatedEvent)
├── Exception/      Shared exception types
├── Listener/       Event listeners (Files/Talk integration, reindex trigger)
├── Middleware/     Request middleware (role checks, public API auth)
├── Migration/      Database schema + repair steps
├── Mount/          Virtual filesystem mount provider
├── Notification/   Nextcloud notifications integration
├── Search/         Elasticsearch indexing/query logic
├── Service/        Core business logic (PermissionService, CaseService, ...)
│   ├── Datafordeler/      Enterprise: CPR/CVR lookup clients (Datafordeler)
│   └── Serviceplatformen/ Organisation/classification/digital post clients (KOMBIT)
├── Settings/       Admin settings page
├── Storage/        Custom storage backend (OpenCaseStorage + wrappers)
└── Versions/       File versioning backend

src/
├── views/            Page-level Vue components (cases, documents, search, ...)
├── components/       Reusable Vue components and dialogs
├── store/            Vuex store (src/store/index.js)
├── services/         Frontend API client (src/services/api.js)
├── router/           Vue Router configuration
├── main.js           Files app integration entry point
├── files-plugin.js   Files app sidebar/actions plugin
├── talk-plugin.js    Nextcloud Talk integration entry point
├── widget.js         Dashboard widget entry point
└── admin-settings.js Admin settings page entry point

templates/     PHP templates (main app shell, login helper, admin settings)
tests/unit/    PHPUnit tests (Controller/, Service/)
appinfo/       info.xml (app metadata), routes.php, schema/ (export XSD)
docs/          End-user & administrator documentation (Danish, MkDocs site)
msoffice/      Outlook/Word/Excel/PowerPoint task-pane add-ins (see msoffice/README.md)
enterprise/    Build/install scripts for the Enterprise package (separate branch)
scripts/       Standalone SQL utilities (filecache sync, test data)
```

## Architecture

OpenCase mounts case files into each user's Nextcloud file tree through a
custom storage backend (`OCA\OpenCase\Storage\OpenCaseStorage`), so case
content is browsable from Files, Mail, Talk, Collabora/ONLYOFFICE, and WebDAV
without any app-specific integration work.

```
/Sager/                                  ← Mount point (configurable)
├── Børn og Unge/                        ← Organisation
│   ├── 2023/                            ← Year
│   │   ├── 2023-004521 - Tilsyn dagtilbud/  ← Case
│   │   │   ├── Afgørelse.pdf            ← File (from document)
│   │   │   └── Notat - besøg 2023-06.docx
│   │   └── 2023-004897 - Anbringelsessag/
│   └── 2024/
├── Teknik og Miljø/
└── Økonomi/
```

Each user only sees the organisations, cases, and files they have access to.
On disk, files are stored flat and ID-based (`{case_id}/{document_id}/{uuid}.{ext}`)
for performance; the human-readable hierarchy above is constructed virtually.

**Permission model**

```
User  ──┬──  Access Profile  ──── Case
        │    (organisation + KLE classification + sensitivity)
        │
        └──  Document Override (optional per-user read/write/deny)
```

- **Access profiles** are unique tuples of `(organisation, classification_code, sensitivity)`.
- **Users are assigned access profiles** with a level: `read` or `write`.
- **Cases reference a single access profile**; a user can access a case if they hold it.
- **Documents inherit case access** but support per-user overrides (extra grants or denials).
- Access profile lookups are cached (Redis/memcached, ~5 minutes) so a permission
  check is typically a single indexed DB lookup.

Key components:

| File | Purpose |
|------|---------|
| `lib/Storage/OpenCaseStorage.php` | Core storage backend — path resolution, file I/O, permissions |
| `lib/Storage/OpenCaseCacheWrapper.php` | Filecache wrapper — virtual path ↔ ID-based path translation for the cache |
| `lib/Storage/OpenCasePermissionWrapper.php` | Enforces per-file permissions at the storage layer |
| `lib/Storage/OpenCaseScanner.php` | No-op scanner — prevents Nextcloud from walking millions of files |
| `lib/Mount/OpenCaseMountProvider.php` | Registers the virtual mount for each authorised user |
| `lib/Service/PermissionService.php` | Central permission logic with caching |
| `lib/AppInfo/Application.php` | Bootstrap — registers mount provider and event listeners |
| `lib/Event/FileUpdatedEvent.php` | Dispatched when files are saved (triggers Elasticsearch reindex) |
| `lib/Middleware/PublicApiMiddleware.php` | Authenticates the mTLS-only receiver endpoints (`/public/v1/messages`, digital post, distribution) |
| `lib/Middleware/PublicDataApiMiddleware.php` | Authenticates the bearer-SAML-token public data API (`/public/v1/api/...`) |

See [img/architecture.svg](img/architecture.svg) for a visual overview.

## Requirements

- Nextcloud 34 (see `appinfo/info.xml` for the exact supported range)
- PHP 8.1+
- Composer 2
- Node.js 20 (pinned in `.nvmrc`) and npm `^9` or `^10`
- Elasticsearch (for full-text search)

## Getting started

Clone (or symlink) this app into your Nextcloud `apps/` directory as `opencase`,
then install dependencies:

```bash
composer install
npm install
```

Build the frontend assets:

```bash
npm run build   # production build (webpack.config.js)
npm run dev     # development build
npm run watch   # development build, rebuild on change
```

Webpack builds four entry points — `main.js` (Files app / SPA), `talk-plugin.js`
(Talk integration), `files-plugin.js` (Files sidebar/actions), and
`admin-settings.js` / `widget.js` (admin settings and dashboard widget) —
producing `js/opencase-*.js`.

Enable the app via the Nextcloud admin UI, or:

```bash
php occ app:enable opencase
```

## Development

```bash
npm run lint         # ESLint (src/, Vue 3 rule set — see .eslintrc.cjs)
npm run lint:fix      # ESLint with autofix
composer lint         # PHP lint (php -l) on all files
composer cs:check      # PHP-CS-Fixer, dry run
composer cs:fix        # PHP-CS-Fixer, apply
composer psalm         # Static analysis (lib/, PHP 8.1 baseline)
composer test:unit     # PHPUnit (tests/unit, config in tests/phpunit.xml)
composer rector        # Automated refactoring (rector.php) + cs:fix
composer openapi       # Regenerate openapi.json from controller annotations
```

CI (`.github/workflows/`) runs PHP lint, PHP-CS-Fixer, Psalm across a matrix
of supported `nextcloud/ocp` versions, ESLint, Stylelint, an `info.xml`
schema check, an OpenAPI drift check, and a webpack build-and-diff check
(compiled `js/` assets must be committed and match source). Match these
locally before opening a PR.

`occ` commands registered by the app (see `appinfo/info.xml` and
`lib/Command/`):

| Area | Commands |
|------|----------|
| Search | `opencase:reindex` |
| Organisation/classification sync | `opencase:sync-organisations`, `opencase:fetch-organisations`, `opencase:sync-classifications`, `opencase:fetch-classifications` |
| Users | `opencase:get-user`, `opencase:update-user`, `opencase:import-user`, `opencase:get-org` |
| Config | `opencase:config:list`, `opencase:config:get`, `opencase:config:set` |
| Certificates / API clients | `opencase:register-certificate`, `opencase:register-api-client`, `opencase:issue-token` |
| Digital post & distribution | `opencase:has-digital-post`, `opencase:kombipost-afsend`, `opencase:send-distribution-receipt` |
| CPR/CVR lookups | `opencase:fetch-citizen-by-cpr`, `opencase:search-citizens`, `opencase:fetch-company-by-cvr`, `opencase:fetch-company-by-name` |
| SAML | `opencase:generate-saml-metadata` |
| Maintenance | `opencase:recalculate-privileges`, `opencase:export-closed-cases`, `opencase:import-templates`, `opencase:trace-enable`, `opencase:trace-disable` |
| Enterprise | `opencase:enterprise-enable` |

Run `php occ list opencase` for the full list with descriptions. Example:

```bash
php occ opencase:reindex
php occ opencase:sync-organisations
php occ opencase:has-digital-post <user>
```

## API

The REST API is mounted under `/ocs/v2.php/apps/opencase/api/v1` and used by
the SPA (session/token auth). See [API.md](API.md) for endpoint documentation
and [openapi.json](openapi.json) for the generated OpenAPI spec.

There is also a **public API** (`/apps/opencase/public/v1/...`, see
`appinfo/routes.php`) for machine-to-machine integrations, split in two:

- **Receiver endpoints** (`/public/v1/messages`, `/public/v1/digital-post/messages`,
  `/public/v1/distribution/messages`) — authenticated via mTLS client
  certificates (nginx terminates TLS and forwards `X-SSL-Client-*` headers to
  `PublicApiMiddleware`).
- **Data API** (`/public/v1/api/cases`, `/documents`, `/files`, `/search`, ...) —
  authenticated via a bearer SAML assertion token, validated by
  `PublicDataApiMiddleware` against a registered API client
  (`opencase_api_client.valid_for = 'API'`).

### Login and backend API

API for Office desktop clients (e.g. Euro-Office desktop) that open OpenCase
documents. All endpoints are under
`https://<server>/index.php/apps/opencase/desktop/v1/api`, send CORS headers
and answer `OPTIONS` preflight requests.

| Endpoint | Auth | Purpose |
|----------|------|---------|
| `POST /login` | none | Starts a login. Wraps Nextcloud's `POST /login/v2`. |
| `POST /login/poll` | none | Returns the app password once the user has granted access. Wraps `POST /login/v2/poll`. |
| `POST /logout` | Basic | Revokes the app password used for the request. |
| `GET /me` | Basic | The current user, whether they have access to OpenCase, and what they may do. |
| `GET /documents/favorites` | Basic | The user's favorite documents. |
| `GET /documents/recent` | Basic | The user's recently opened documents. |
| `GET /documents/my-documents` | Basic | Documents the user created, as on the **Mine dokumenter** page. |
| `GET /documents/fulltextsearch` | Basic | Full-text search, files part. |
| `GET /cases` | Basic | Case search, for picking the case to save to. |
| `GET /document-categories` | Basic | Document categories. |
| `GET /document-types` | Basic | Document types. |
| `GET /insight-levels` | Basic | Insight levels. |
| `POST /documents` | Basic | Creates a document with metadata in a case. |
| `POST /documents/{id}/files` | Basic | Adds a file with content to a document. |
| `GET /files/{fileId}/document` | Basic | Document info for a file, by Nextcloud file ID. |
| `GET /templates` | Basic | Templates, with an editor URL for users who may edit them. |
| `GET /templates/fields` | Basic | Merge fields that templates can contain. |
| `GET /organisations` | Basic | Organisation tree, for limiting a new template. |
| `GET /phrases` | Basic | Phrases: for attaching to a template, for inserting, and for editing. |
| `POST /phrases`, `PUT /phrases/{id}`, `POST /phrases/{id}/file` | Basic | Creates and changes phrases (template managers). |
| `GET /phrases/{id}`, `GET /phrases/{id}/content`, `GET /phrases/{id}/file` | Basic | A phrase's details, its text for inserting, and its file. |
| `GET /phrase-categories`, `GET /case-types` | Basic | Phrase categories, and case types for a phrase's scopes. |
| `POST /templates` | Basic | Creates a template, blank or from an uploaded file. |
| `GET /templates/{id}/phrases` | Basic | A template's phrases that can be used for a case. |
| `POST /documents/{id}/files/from-template` | Basic | Creates a file on a document from a template. |
| `GET /document-statuses`, `PUT /documents/{id}/status` | Basic | Document statuses, and changing a document's status. |
| `PUT` / `DELETE /documents/{id}/favorite` | Basic | Marks or unmarks a document as a favourite. |
| `GET /contact-roles`, `GET /citizens/search`, `GET /companies/search` | Basic | Contact roles, and searching for citizens and companies. |
| `GET` / `POST /documents/{id}/contacts`, `DELETE /documents/{id}/contacts/{contactId}` | Basic | A document's senders and recipients. |
| `POST /documents/{id}/contacts/{contactId}/address` | Basic | Shows a protected address. Each call is logged. |
| `GET` / `POST /documents/{id}/notes`, `PUT` / `DELETE /documents/{id}/notes/{noteId}` | Basic | A document's notes. |
| `GET` / `POST /documents/{id}/digital-post`, `GET …/digital-post/draft` | Basic | Sends with Digital Post, and shows the shipment's status. |
| `GET /email/account`, `GET /documents/{id}/email/recipients`, `POST …/email/access` | Basic | Email: the user's account, searching recipients, checking who can open a link. |
| `POST /documents/{id}/email/link`, `POST /documents/{id}/email/pdf` | Basic | Sends a link to the document, or its files as PDF, by email. |

#### Login flow

1. `POST /login`. Response:
   ```json
   {
     "poll":  { "token": "<poll token>", "endpoint": "https://<server>/apps/opencase/desktop/v1/api/login/poll" },
     "login": "https://<server>/login/v2/flow/<login token>"
   }
   ```
2. Open `login` in the system browser.
3. Every few seconds, `POST` `token=<poll token>` (form-encoded or JSON) to
   `poll.endpoint`. It returns `404` until the user has granted access, then
   `200` with the following body. Credentials are returned once only, so
   store them right away. The flow expires after 20 minutes.
   ```json
   { "server": "https://<server>", "loginName": "<login name>", "appPassword": "<app password>" }
   ```
4. Call the other endpoints with `Authorization: Basic base64(loginName:appPassword)`.
   Do not send cookies. A `401` means the app password has expired or been
   revoked, so start a new login. App passwords expire a fixed time after
   login, 720 hours (30 days) by default (`desktop_token_lifetime_hours` in
   `opencase_config`); using them doesn't extend that.
5. Right after login, and when the client starts with stored credentials,
   call `GET /me` (see below).
6. On sign-out, call `POST /logout` with Basic auth, then forget the credentials.

The client works the same way for every customer setup. How the user logs in
in the browser depends on how the server is configured, and the client only
ever receives an app password:

- **Nextcloud user + password**: the standard Nextcloud login form.
- **OpenID Connect (Microsoft Entra ID)**: the Nextcloud `user_oidc` app sends
  the user to Entra ID.
- **SAML (KOMBIT context handler)**: OpenCase's SAML login takes over the login
  page and sends the user to the context handler.

#### The current user

`GET /me` tells the client who is logged in and which features to show:

```json
{
  "id": "admin",
  "displayName": "Administrator",
  "isOpenCaseUser": true,
  "canManageTemplates": true,
  "canSendDigitalPost": true,
  "registerSearch": { "citizens": true, "companies": true }
}
```

| Field | Meaning |
|-------|---------|
| `isOpenCaseUser` | The user has access to OpenCase. When `false`, the login worked but every other endpoint returns `403`. Tell the user they have no access to OpenCase (an administrator must grant it) and don't show the OpenCase features. Keep the credentials, so `GET /me` can be called again later. |
| `canManageTemplates` | The user may create and edit templates and phrases. Show **New template**, **Ny frase** and template/phrase editing only when `true`. |
| `canSendDigitalPost` | Digital Post is available on this server. Show **Send Digital post** only when `true`. Whether a given document may be sent is still checked per document (see "Send Digital post"). |
| `registerSearch` | Whether citizen and company searches look in the CPR/CVR registers (see "Document panel"). |

`GET /me` may also return `403` instead of `isOpenCaseUser: false` when the
server only enables the OpenCase app for certain groups and the user isn't in
one. Treat that the same way.

#### Document lists

`GET /documents/favorites`, `GET /documents/recent` and
`GET /documents/my-documents` take these query parameters:

| Parameter | Description |
|-----------|-------------|
| `client` | Editor that `editorUrl` is built for. Default `eurooffice`, the only value supported for now; other values return `400`. |
| `limit` | Maximum number of documents. Default 50, maximum 200. |
| `offset` | `my-documents` only: index of the first document, for paging. Default 0. |

The response is a JSON array. Deleted documents and documents the user can no
longer read are left out. The order depends on the endpoint:

- `favorites`: most recently added favorite first.
- `recent`: most recently opened first. This is the same list as in
  OpenCase. A document is added when the user opens it in OpenCase, when the
  client calls `GET /files/{fileId}/document` for one of its files, and when
  the user creates it or adds a file to it through this API.
- `my-documents`: documents the user created, most recently updated first,
  as on the **Mine dokumenter** page (`/apps/opencase/my-documents`). The
  `X-Total-Count` response header gives the total number of documents for
  paging, and it is readable from cross-origin code.

```json
[
  {
    "id": 6,
    "documentnumber": "2026-00003-1",
    "title": "Kommunens styrelse i almindelighed",
    "url": "https://<server>/apps/opencase/document/6",
    "files": [
      {
        "fileId": 2911,
        "name": "Kommunens styrelse i almindelighed.docx",
        "editorUrl": "https://<server>/index.php/apps/eurooffice/2911?filePath=/.Sager/2026/10/25/2026-00003/Kommunens%20styrelse%20i%20almindelighed.docx"
      }
    ]
  }
]
```

- `url` opens the document in OpenCase.
- `fileId` is the Nextcloud file ID.
- `editorUrl` opens the file in the editor.

#### Full-text search

`GET /documents/fulltextsearch` (Basic auth) searches file content and
metadata. It returns the files part of the search page in OpenCase
(`/apps/opencase/search`, tab **Filer**), with the same filters, facets and
paging.

| Parameter | Description |
|-----------|-------------|
| `q` | Required. Search text. Missing or empty returns `400`. |
| `organisation` | Filter: a `value` from `facets.organisations`. |
| `case_type` | Filter: a `value` from `facets.caseTypes` (a case type ID). |
| `document_type` | Filter: a `value` from `facets.documentTypes`. |
| `year` | Filter: case year, e.g. `2026`. |
| `limit` | Page size. Default is the server's search page size (setting `search_page_size`, normally 50). Maximum 200. |
| `offset` | Index of the first result, for paging. Default 0. |
| `client` | As for the document lists. |

Response:

```json
{
  "query": "kommune",
  "filters": { "organisation": "Korsbaek Kommune" },
  "total": 6,
  "limit": 50,
  "offset": 0,
  "facets": {
    "organisations": [ { "value": "Korsbaek Kommune", "label": "Korsbaek Kommune", "count": 1 } ],
    "caseTypes":     [ { "value": "1", "label": "Standard", "count": 1 } ],
    "documentTypes": []
  },
  "files": [
    {
      "fileId": 2911,
      "name": "Kommunens styrelse i almindelighed.docx",
      "editorUrl": "https://<server>/index.php/apps/eurooffice/2911?filePath=/.Sager/2026/10/25/2026-00003/Kommunens%20styrelse%20i%20almindelighed.docx",
      "mimeType": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
      "size": 13382,
      "updatedAt": "2026-10-25T11:29:23+00:00",
      "highlights": [
        "<mark>Kommunens</mark> styrelse er den øverste administrative enhed i en <mark>kommune</mark> …"
      ],
      "document": {
        "id": 6,
        "documentnumber": "2026-00003-1",
        "title": "Kommunens styrelse i almindelighed",
        "documentType": "",
        "url": "https://<server>/apps/opencase/document/6"
      },
      "case": {
        "id": 3,
        "caseNumber": "2026-00003",
        "title": "Desktop sag",
        "organisation": "Korsbaek Kommune",
        "year": "2026"
      }
    }
  ]
}
```

- `total` is the number of matching files, capped at the server's maximum
  result count (setting `search_max_result_count`, normally 500). Use it with
  `limit` and `offset` for "Previous" and "Next" buttons.
- `filters` echoes the filters that were applied.
- `files` is sorted by relevance. A file that was deleted, or that the user
  lost access to after it was indexed, is left out, so a page can hold fewer
  than `limit` files.
- `highlights` holds up to three text fragments from the file content. They
  are HTML-escaped, and the only markup is `<mark>…</mark>` around the
  matches, so they are safe to render as HTML.
- `fileId`, `name` and `editorUrl` mean the same as in the document lists.
  `document.url` opens the document in OpenCase.
- The facets are built the same way as on the search page, so their counts
  are not file counts. Each facet sends its `value` back in the matching
  filter parameter: `organisations` → `organisation`, `caseTypes` →
  `case_type`, `documentTypes` → `document_type`. Show `label` to the user.

To match the **Filer** tab, show each file like this:

- **Heading:** a file icon, `name`, a "Fil" badge, and
  `case.caseNumber — case.title`.
- **Snippet:** `highlights` joined with ` … `.
- **Meta line:** `case.organisation`, `case.year`, `document.documentType`
  (only if not empty).
- **Facet sidebar:** groups Organisation, Sagstype and Dokumenttype, each
  item showing `label` and `count`. Clicking an item applies its filter and
  goes back to offset 0. Show active filters as removable chips.
- **Click on a result:** open `editorUrl`, or `document.url` to show the
  document in OpenCase.

Every search is recorded in the user's activity log, as on the search page.

#### Saving a file to a case

To save a file from the editor to OpenCase, the client does the same as the
**Save to OpenCase** dialog in the Files app:

1. Let the user pick a case with `GET /cases`.
2. Let the user enter the document metadata, using the lists from
   `GET /document-categories`, `GET /document-types` and `GET /insight-levels`.
3. Create the document with `POST /documents`.
4. Upload the file with `POST /documents/{id}/files`.

In the Files app dialog, only the case and the document category are
required. The title defaults to the file name without its extension.

##### `GET /cases`

Searches the cases the user can read by case number or title, as the case
picker in the Files app does. Search as the user types, after a short pause
(the Files app waits 300 ms).

| Parameter | Description |
|-----------|-------------|
| `search` | Required. Part of a case number or title. Missing or empty returns `400`. |
| `limit` | Maximum number of cases. Default 20, maximum 200. |

```json
[
  {
    "id": 3,
    "caseNumber": "2026-00003",
    "title": "Desktop sag",
    "organisation": "Korsbaek Kommune",
    "status": "Åben",
    "isClosed": false,
    "insightLevelId": null,
    "canWrite": true,
    "url": "https://<server>/apps/opencase/case/3"
  }
]
```

- Show each case as `caseNumber — title`, as the Files app does.
- A document can only be created in a case where `canWrite` is `true`, so
  disable or hide the other cases.
- `status` is in the user's language.

##### `GET /document-categories`, `GET /document-types`, `GET /insight-levels`

Each returns a JSON array of the values that can be selected now. Expired
values are left out, and so are the system document types that integrations
set. Names are in the user's language.

```json
[ { "id": 1, "name": "Indgående" }, { "id": 2, "name": "Udgående" }, { "id": 3, "name": "Intern" } ]
```
```json
[ { "id": 100, "name": "Notat" }, { "id": 101, "name": "Brev" } ]
```
```json
[ { "id": 1, "name": "Åben (Offentlig)", "description": "Dokumenter og sager, der som udgangspunkt er tilgængelige for alle …" } ]
```

When creating a document, send the category and the insight level by `id`,
and the document type by `name`.

##### `POST /documents`

JSON body (`Content-Type: application/json`):

| Field | Description |
|-------|-------------|
| `caseId` | Required. `id` from `GET /cases`. |
| `title` | Required. Document title. |
| `documentCategoryId` | Required. `id` from `GET /document-categories`. |
| `documentType` | Optional. `name` from `GET /document-types`. Any other value returns `400`. |
| `insightLevelId` | Optional. `id` from `GET /insight-levels`. If the field is left out, the case's insight level is used, as in the web dialog. Send `null` for none. |
| `documentDate` | Optional. `YYYY-MM-DD`. Default today. |
| `receivedDate`, `registeredDate` | Optional. `YYYY-MM-DD`. |
| `summary` | Optional. Rich text as HTML. |

```json
{ "caseId": 3, "title": "Notat om høring", "documentCategoryId": 3, "documentType": "Notat" }
```

The response is `201` with the new document, in the same shape as the
document lists. The document gets its number automatically, and `files` is
empty:

```json
{ "id": 9, "documentnumber": "2026-00003-4", "title": "Notat om høring", "url": "https://<server>/apps/opencase/document/9", "files": [] }
```

Errors:
- `400`: a required field is missing, or a value is not valid.
- `404`: the case was not found, or the user has no write access to it.

##### `POST /documents/{id}/files`

`multipart/form-data` with these fields:

| Field | Description |
|-------|-------------|
| `file` | Required. The file content. Its file name and content type are used. |
| `name` | Optional. File name to store instead, e.g. `Notat om høring.docx`. Keep the extension. |
| `client` | Optional query parameter, as for the document lists. |

The response is `201` with the file, in the same shape as an entry in
`files` in the document lists, so the client can open it in the editor
straight away:

```json
{ "fileId": 2968, "name": "Notat om høring.docx", "editorUrl": "https://<server>/index.php/apps/eurooffice/2968?filePath=/.Sager/2026/10/25/2026-00003/Notat%20om%20h%C3%B8ring.docx" }
```

Errors:
- `400`: no file was sent, or the file is bigger than the server's upload
  limit (PHP `upload_max_filesize` and `post_max_size`).
- `404`: the document was not found, or the user has no write access to it.

To add several files, call this endpoint once per file.

#### Document info for a file

`GET /files/{fileId}/document` returns the OpenCase document that a file
belongs to. `fileId` is the Nextcloud file ID: the ID the editor has for the
open file, and the `fileId` returned everywhere else in this API. Use it to
show the document's metadata while a file is being edited.

Each call is logged as a document view and a file view in the audit log.

Call it every time a file is opened in the editor, even if the client doesn't
show the metadata. Opening the editor doesn't go through OpenCase, so this call
is what puts the document at the top of the user's recent list, as opening the
document page in OpenCase does.

The optional `client` query parameter works as for the document lists.

The response has the same fields as an entry in the document lists (`id`,
`documentnumber`, `title`, `url`, and `files` with every file of the
document), plus the following:

```json
{
  "id": 6,
  "documentnumber": "2026-00003-1",
  "title": "Kommunens styrelse i almindelighed",
  "url": "https://<server>/apps/opencase/document/6",
  "files": [ { "fileId": 2911, "name": "…", "editorUrl": "…" }, { "fileId": 2912, "name": "…", "editorUrl": "…" } ],
  "documentType": "",
  "status": { "id": 1, "name": "Kladde", "isFinal": false },
  "category": { "id": 3, "name": "Intern" },
  "insightLevel": null,
  "documentDate": "2026-10-25",
  "receivedDate": null,
  "registeredDate": "2026-10-25",
  "summary": null,
  "createdAt": "2026-10-25T11:29:23+00:00",
  "createdBy": { "id": "admin", "displayName": "admin" },
  "updatedAt": "2026-10-25T11:29:23+00:00",
  "updatedBy": { "id": "admin", "displayName": "admin" },
  "canWrite": true,
  "isFavorite": false,
  "case": {
    "id": 3,
    "caseNumber": "2026-00003",
    "title": "Desktop sag",
    "organisation": "Korsbaek Kommune",
    "url": "https://<server>/apps/opencase/case/3"
  },
  "file": {
    "fileId": 2912,
    "name": "International virksomhed og EU i almindelighed.docx",
    "editorUrl": "https://<server>/index.php/apps/eurooffice/2912?filePath=…",
    "mimeType": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "size": 12795,
    "version": 1,
    "updatedAt": "2026-10-25T11:29:23+00:00"
  }
}
```

- `file` is the file that was asked for. `version` goes up each time the
  file is saved.
- `status.isFinal` is `true` when the document has been finalised.
- `category` and `insightLevel` are `null` when not set.
- Names (`status.name`, `category.name`, `insightLevel.name`) are in the
  user's language.
- `canWrite` says whether the user may change the document.
- `isFavorite` says whether the user has marked the document as a favourite.
- `summary` is rich text as HTML, or `null`.

The response is `404` when the ID is not a file in OpenCase, or when the
file or its document is deleted or the user can't read the document. Calling
this endpoint does not add the document to the user's recent list.

#### Templates

`GET /templates` lists the document templates, newest first, as the template
list in OpenCase does. Users who may manage templates (the **Administrator**
or **Template Designer** role) also get a URL that opens each template for
editing in the editor.

| Parameter | Description |
|-----------|-------------|
| `search` | Optional. Keeps only templates whose name or file name contains this text. Case is ignored. |
| `caseId` or `documentId` | Optional. Keeps only templates available for the case's organisation (for `documentId`, the document's case). Returns `404` if the case is not found or the user can't read it. |
| `client` | As for the document lists. |

```json
[
  {
    "id": 3,
    "name": "Brev til borger",
    "originalFilename": "Brev til borger.docx",
    "mimeType": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "size": 15552,
    "createdAt": "2026-10-14T09:26:12+00:00",
    "uploadedBy": { "id": "admin", "displayName": "Administrator" },
    "canEdit": true,
    "fileId": 3240,
    "editorUrl": "https://<server>/index.php/apps/eurooffice/3240?filePath=/.opencase-skabeloner/d8e973bd828f391612b40915c46ea737.docx"
  }
]
```

- Show `name` to the user. The stored file name in `editorUrl` is an
  internal name.
- `canEdit` is `true` when the user may edit the template and its file
  exists. `fileId` (the Nextcloud file ID) and `editorUrl` are then set.
  Otherwise all three are `false`, `null` and `null`.
- `editorUrl` opens the stored template itself, not a copy. Saving in the
  editor changes the template for everyone, as when editing it from
  OpenCase. Make that clear to the user.

#### Merge fields

`GET /templates/fields` lists the merge fields a template can contain, grouped
as on the **Skabelonfelter** page in OpenCase (`/apps/opencase/templates/fields`).
Use it to let a template designer insert fields while editing a template.

```json
{
  "syntax": "{{feltnavn}}",
  "groups": [
    {
      "key": "case",
      "label": "Sag",
      "note": null,
      "fields": [
        { "name": "sag.titel", "placeholder": "{{sag.titel}}", "da": "sag.titel", "en": "case.title", "description": "Sagsoverskrift" }
      ]
    },
    {
      "key": "address",
      "label": "Adresse",
      "note": "Adressefelterne udfyldes først, når dokumentet sendes med Digital Post …",
      "fields": [ … ]
    }
  ]
}
```

- The groups are `case`, `organisation`, `document`, `address` and
  `phrases`, in that order.
- `label`, `note` and `description` are in the user's language. `note` is
  `null` for most groups. When it is set, show it above the group's fields.
- `placeholder` is the text to insert into the template. `name` is the field
  name without the braces. Both use the Danish field names (`da`) when the
  user's language is Danish, and the English ones (`en`) otherwise, as the
  page does.
- Both name forms work in a template, and case is ignored. `syntax` shows
  the general form in the user's language.

#### Creating a template

A user can create a template from the client, as with the **Ny skabelon**
dialog in OpenCase. Only users who may manage templates can do this.

1. Show **New template** only when `canManageTemplates` in `GET /me` (see
   "The current user") is `true`. Otherwise `POST /templates` returns `403`.
2. Show a dialog with two tabs, as OpenCase does:
   - **Generelt**: name, organisations, and file (new blank file or upload).
   - **Fraser**: phrases to attach to the template.
3. Send everything in one `POST /templates`. If anything is invalid, the
   server keeps nothing and returns `400` with the reason in `error`.
4. For a new blank file, open the returned `editorUrl` straight away so the
   user can write the template, as OpenCase does.

##### Generelt tab

| Field | How to fill it |
|-------|----------------|
| **Navn** | Free text. If left empty, a blank template is named "Ny skabelon" (in the user's language), and an uploaded one gets its file name. |
| **Organisationer** | Optional. Pick from `GET /organisations`; multiple choice. Leave it empty to make the template usable in every case. Explain this as OpenCase does: "Skabelonen kan kun bruges i sager under de valgte organisationer. Uden valg kan den bruges i alle sager." |
| **Fil** | Either **Ny fil** (a blank document, created by the server as `.docx` or `.odt` according to Nextcloud Office's format setting) or **Upload fil**. An upload may be `.docx`, `.odt`, `.xlsx` or `.ods`. |

`GET /organisations` returns the active organisations in display order, with
parents before their children and siblings sorted by name. Indent each by
`depth`:

```json
[
  { "uuid": "0235dc7b-…", "name": "Korsbaek Kommune", "parentUuid": null, "depth": 0 },
  { "uuid": "aab8d8a0-…", "name": "Afdelingen for Nye Tider", "parentUuid": "0235dc7b-…", "depth": 1 }
]
```

##### Fraser tab

`GET /phrases` returns the phrases the user can attach, sorted by category
and title. Call it again whenever the selected organisations or the file
type change. See "Phrases" below for the full response and the other
parameters.

| Parameter | Description |
|-----------|-------------|
| `orgUuids` | The selected organisations, comma-separated. Empty means all. A phrase that is limited to organisations outside these is left out, and so is an archived phrase. |
| `templateFormat` | `blank` for a new blank file, or the extension of the uploaded file (`docx`, `odt`, `xlsx`, `ods`). Fills in `formatNote`. |

```json
[
  {
    "id": 7,
    "title": "Anmodning om supplerende oplysninger",
    "description": "",
    "categoryPath": "Borgerservice › Manglende oplysninger",
    "status": "active",
    "fileFormat": null,
    "formatNote": null,
    "…": "…"
  }
]
```

- Let the user search phrases by `title`, `categoryPath` and `description`,
  and add them to a list. A phrase can be in the list only once.
- The user can reorder the list. That order is the order in which the
  phrases are offered when a file is created from the template.
- For each phrase in the list, the user sets **Obligatorisk** (`isRequired`,
  the phrase is always included) and **Forudvalgt** (`isDefault`, ticked in
  advance). An obligatory phrase is always pre-selected too.
- Show `formatNote` under a phrase when it is set. It warns that the phrase
  will be inserted without formatting, or as plain text in a spreadsheet.
- `status` can be `draft` or `active`. For a draft, show what OpenCase
  shows: "Kladde — indsættes ikke i breve, før frasen er aktiv." A draft
  phrase is not inserted until it is made active.
- If the user changes the organisations, some phrases already in the list
  may no longer match. Look for them in a new `GET /phrases` response and
  mark the missing ones. OpenCase disables saving until they are removed,
  and the server returns `400` if they are sent.

##### `POST /templates`

To create a **blank file**, send JSON (`Content-Type: application/json`):

```json
{
  "name": "Afslag på ansøgning",
  "orgUuids": ["97dd3961-…"],
  "phrases": [
    { "phraseId": 7, "isRequired": true, "isDefault": false },
    { "phraseId": 6, "isRequired": false, "isDefault": true }
  ]
}
```

To create a template from an **uploaded file**, send `multipart/form-data`
with these fields:

| Field | Description |
|-------|-------------|
| `file` | The template file. |
| `name` | Optional. |
| `orgUuids` | Optional. The JSON array as a string, e.g. `["97dd3961-…"]`. |
| `phrases` | Optional. The JSON array as a string, in the same form as above. |

The response is `201` with the new template, in the same shape as an entry
in `GET /templates`, plus the following:

```json
{
  "id": 8,
  "name": "Afslag på ansøgning",
  "originalFilename": "Afslag på ansøgning.docx",
  "canEdit": true,
  "fileId": 3290,
  "editorUrl": "https://<server>/index.php/apps/eurooffice/3290?filePath=/.opencase-skabeloner/f3ad2edfb4c1097b66be2f0e990ee6b2.docx",
  "orgUuids": ["97dd3961-…"],
  "phraseCount": 2,
  "…": "…"
}
```

Errors:
- `400`: the file type is not allowed, the upload failed, or an
  organisation or phrase is unknown or doesn't match. `error` holds a
  message in the user's language that can be shown as it is.
- `403`: the user may not manage templates.

**Saving template edits:** changes made in the editor are only written to
the template when every user has closed it, about 10 seconds after the last
one. Until then, files created from the template use the previous version.
After editing a template, close it.

#### New file from a template

**Ny fil fra skabelon** lets a case worker write a letter or another file
from a template, as the dialog of the same name in OpenCase does. The server
fills in the merge fields with the case's and the document's data, inserts
the chosen phrases and stores the file on the document. Any user with write
access to the document can do this.

1. **Choose the document.** Use the open file's document (`GET
   /files/{fileId}/document`), a document from the lists or the search, or
   create a new one (pick a case with `GET /cases`, then `POST /documents`).
   The document must allow writing: `canWrite` in the document info, or
   `canWrite` on the case.
2. **Skabelon:** list the templates with `GET /templates?documentId={id}`,
   which only returns templates available for the document's case. Sort them
   by `name`, and let the user search by `name` and `originalFilename`. If
   the list is empty, show "Ingen skabeloner tilgængelige".
3. **Filnavn:** fill it in with the template's `name` when a template is
   picked. If the user picks another template, change the name too, unless
   the user typed their own. The file type of the template is always kept,
   so tell the user, e.g. "Filtypen (docx) bevares" (the extension of
   `originalFilename`).
4. **Fraser:** list the template's phrases with
   `GET /templates/{templateId}/phrases?documentId={id}`, and show a
   checkbox for each.
5. **Opret og rediger:** create the file with
   `POST /documents/{id}/files/from-template`, then open the returned
   `editorUrl`.

##### `GET /templates/{id}/phrases`

| Parameter | Description |
|-----------|-------------|
| `documentId` or `caseId` | The document or case the file is for. Only phrases that may be used in that case are returned. |

```json
[
  {
    "phraseId": 4,
    "title": "Anmodning om svar inden frist",
    "description": "",
    "categoryPath": "Fælles fraser › Svarfrister",
    "isRequired": false,
    "isDefault": true
  }
]
```

- The phrases come in the template's order, and are inserted in that order.
- Only phrases that are active, have content, and match the case's case
  type, organisation and KLE number are returned. The server inserts no
  others.
- `isRequired`: the phrase is always inserted. Show it ticked and disabled,
  with the label "Obligatorisk".
- `isDefault`: tick the phrase from the start, with the label "Forudvalgt".
  The user may untick it.
- If the list is empty, show "Skabelonen har ingen fraser, der kan bruges i
  denne sag." The file can still be created.
- Returns `404` if the template is not found, or the case is not found or
  can't be read.

##### `POST /documents/{id}/files/from-template`

`{id}` is the document. JSON body:

| Field | Description |
|-------|-------------|
| `templateId` | Required. `id` from `GET /templates`. |
| `filename` | Optional. Name of the new file. The template's extension is always kept: `Brev` becomes `Brev.docx`. Left out or empty, the template's file name is used. If a file with the name already exists on the case, a number is added, e.g. `Brev (2).docx`. |
| `phraseIds` | Optional. The `phraseId`s the user ticked. Obligatory phrases are always included, even if left out. If the field is left out entirely, the pre-selected phrases are used; send `[]` for none except the obligatory ones. |

```json
{ "templateId": 9, "filename": "Svar til borger", "phraseIds": [4] }
```

The response is `201` with the file and its document. Open `editorUrl`
straight away:

```json
{
  "fileId": 3296,
  "name": "Svar til borger.docx",
  "editorUrl": "https://<server>/index.php/apps/eurooffice/3296?filePath=/.Sager/2026/10/25/2026-00003/Svar%20til%20borger.docx",
  "document": {
    "id": 14,
    "documentnumber": "2026-00003-9",
    "title": "Desktop skabelontest",
    "url": "https://<server>/apps/opencase/document/14"
  }
}
```

Errors:
- `400`: `templateId` is missing, the template isn't available for the
  case's organisation, or the file name is invalid.
- `404`: the template or document was not found, or the user has no write
  access to the document.
- `500`: the template could not be merged. Show "Kunne ikke oprette fil fra
  skabelon".

Merging can take a few seconds, especially with a logo or many phrases.
Show progress ("Opretter…") and disable the button meanwhile.

#### Document panel: status, favourite, senders and recipients, notes

These endpoints extend **Dokument info** with what the document page in
OpenCase offers. All of them take the document's `id` (from `GET
/files/{fileId}/document` or the document lists).

Rules that apply to the whole panel:
- Only show the editing controls when `canWrite` in the document info is
  `true`. Without write access, the change endpoints return `404`.
- While the document's status is final (`status.isFinal`), its senders,
  recipients and notes can't be changed. OpenCase hides those controls, and
  the server returns `409`. The status itself can still be changed.
- A `404` means the document doesn't exist, was deleted, or the user has no
  access (no write access, for changes).

##### Status

`GET /document-statuses` lists the statuses in the user's language:

```json
[ { "id": 1, "name": "Kladde", "isFinal": false }, { "id": 2, "name": "Passiv", "isFinal": false }, { "id": 3, "name": "Endelig", "isFinal": true } ]
```

`PUT /documents/{id}/status` with the JSON body `{ "status": 3 }` changes
the status. The response is the new status:

```json
{ "status": { "id": 3, "name": "Endelig", "isFinal": true } }
```

- A final status makes the document's files read-only, so the open file
  can no longer be saved. Ask the user to confirm first, e.g. "Når
  dokumentet er endeligt, kan filerne ikke længere redigeres." Save the
  file before changing the status.
- Changing the status back to a non-final one makes the files editable
  again.
- An unknown status gives `400`.

##### Favourite

- `PUT /documents/{id}/favorite` marks the document as a favourite, and
  `DELETE /documents/{id}/favorite` unmarks it. Both return
  `{ "isFavorite": true|false }`.
- Both can be called again without harm.
- Show the current state from `isFavorite` in the document info, e.g. as a
  star.
- The document then appears in, or disappears from,
  `GET /documents/favorites`.

##### Senders and recipients (Afsendere/Modtagere)

`GET /documents/{id}/contacts`:

```json
[
  {
    "id": 7,
    "role": { "id": 1, "name": "Afsender" },
    "type": "company",
    "name": "Novo Nordisk A/S - NSH",
    "cprCvr": "24256790",
    "pnumber": "1007676243",
    "phone": "",
    "email": "",
    "addressProtected": false,
    "address": {
      "streetname": "Niels Steensens Vej", "housenumber": "2A", "floor": "", "door": "",
      "zipcode": "2820", "zipdistrict": "Gentofte",
      "formatted": "Niels Steensens Vej 2A, 2820 Gentofte"
    }
  }
]
```

- `role` is one of the roles from `GET /contact-roles` (e.g. Afsender,
  Modtager).
- `type` is `citizen` (CPR number), `company` (CVR number) or `employee`
  (neither).
- Show a CPR number masked, as OpenCase does: the first six digits, then
  `XXXX` (e.g. `010180XXXX`). Show it in full only when the user clicks it.
- **Address protection:** when `addressProtected` is `true`, `address` is
  `null`. Show "Beskyttet adresse" instead. Only if the user clicks it, call
  `POST /documents/{id}/contacts/{contactId}/address`, which returns
  `{ "address": { … } }`. Each call is recorded in the transaction log, as in
  OpenCase. Don't cache the address.

**Adding a contact.** OpenCase has two buttons, **Tilføj borger** and
**Tilføj virksomhed**, each opening a dialog:

1. **Rolle** (required): from `GET /contact-roles`.
2. **Search.**
   - Citizens: `GET /citizens/search` with `cpr`, or with any of
     `firstname`, `lastname`, `streetname`, `housenumber`, `zipcode`,
     `zipdistrict`.
   - Companies: `GET /companies/search` with `cvr` or `name`. With
     `cvr=…&pnumbers=1` you get the company's production units
     (P-numbers) instead.
   - If the search returns exactly one result, OpenCase adds it straight
     away.
3. **Add:** `POST /documents/{id}/contacts` with the role and the result's
   number. The server looks the person or company up again and stores the
   details it finds, including a protected address the client never sees:

   ```json
   { "roleId": 2, "cprCvr": "24256790" }
   { "roleId": 1, "cprCvr": "24256790", "pnumber": "1007676243" }
   ```

4. **Not found:** let the user enter the contact by hand. Send `name`
   (required) and any of `cprCvr`, `pnumber`, `streetname`, `housenumber`,
   `floor`, `door`, `zipcode`, `zipdistrict`, `phone`, `email` and
   `addressProtected`.

The search response:

```json
{
  "source": "register",
  "results": [
    { "cprCvr": "24256790", "pnumber": "1007676243", "name": "Novo Nordisk A/S - NSH", "phone": "", "email": "",
      "addressProtected": false, "address": { "formatted": "Niels Steensens Vej 2A, 2820 Gentofte", "…": "…" } }
  ]
}
```

- `source` is `register` when the search went to the CPR/CVR registers, and
  `local` when the instance has no register access and only people and
  companies already on cases and documents were searched.
  `registerSearch` in `GET /me` tells you which in advance, so you can say
  "Søger kun i borgere/virksomheder, der allerede er på sager" when it is
  `false`.
- A result with address protection has `address: null`.
- Every search is recorded in the transaction log.
- `400`: no search criteria. `502`: the register couldn't be reached
  (`error` holds the reason).

Adding gives `201` with the new contact (same shape as the list). Other
responses:
- `404`: a number that is looked up isn't found.
- `409`: the same CPR/CVR (and P-number) already has that role on the
  document. OpenCase shows "Denne borger er allerede tilføjet med den valgte
  rolle".

`DELETE /documents/{id}/contacts/{contactId}` removes a contact. Ask first:
"Er du sikker på, at du vil slette denne kontakt?"

##### Notes (Noter)

`GET /documents/{id}/notes`:

```json
[
  {
    "id": 4,
    "title": "Telefonsamtale med borger",
    "text": "<p>Borgeren ringede og <strong>bekræftede</strong> adressen.</p>",
    "createdAt": "2026-10-28T14:02:00+00:00",
    "createdBy": { "id": "admin", "displayName": "Administrator" },
    "updatedAt": "2026-10-28T14:02:00+00:00"
  }
]
```

- **Create:** `POST /documents/{id}/notes` with
  `{ "title": "…", "text": "<p>…</p>" }`. The response is `201` with the
  note.
- **Edit:** `PUT /documents/{id}/notes/{noteId}`, with the same body.
- **Delete:** `DELETE /documents/{id}/notes/{noteId}`.
- `title` is required (`400` without it).
- `text` is rich text as HTML. The server keeps only paragraphs, headings
  1–3, lists, block quotes, line breaks, bold, italic, underline and
  strike-through, and drops everything else (scripts, links, images,
  attributes). Plain text is fine too.

#### Phrases (Fraser)

Phrases are standard texts that a template inserts in place of
`{{fraser.indhold}}` (see "Creating a template" and "New file from a
template"). A phrase is one of two kinds:
- **Text phrase:** has text only (`content`, rich text).
- **File phrase:** has a `.docx` or `.odt` file. The file is used instead
  of the text, with its formatting.

The ribbon has three buttons for template managers. Show them only when
`canManageTemplates` in `GET /me` is `true`:
- **Ny frase** creates a phrase.
- **Åbn frase** opens a file phrase in the editor.
- **Indsæt frase** inserts a phrase's text into the open document.

Indsæt frase also works for other users. Creating and changing phrases
returns `403` for them, and they get no `editorUrl`.

Users who aren't template managers only reach what could go into a letter:
active phrases, and a phrase with scopes only together with a case it matches.
So for Indsæt frase, pass `documentId` (or `caseId`) of the open document to
`GET /phrases`, `GET /phrases/{id}`, `GET /phrases/{id}/content` and
`GET /phrases/{id}/file`. Without one they only get phrases without scopes.
Any other phrase returns `404`, and so does a case they can't read.

##### `GET /phrases`

| Parameter | Description |
|-----------|-------------|
| `search` | Optional. Part of the title, description or category path. Case is ignored. |
| `status` | Optional, template managers only. Comma-separated: `draft`, `active`, `archived`. Default `draft,active`. Other users always get `active`. |
| `documentId` or `caseId` | Optional. Only phrases whose scopes match that case. See above for what happens without one. |
| `orgUuids` | Optional. Only phrases that can be attached to a template limited to these organisations. |
| `templateFormat` | Optional. Fills in `formatNote` (see "Creating a template"). |
| `client` | As for the document lists. |

```json
[
  {
    "id": 11,
    "title": "Partshøring – mulighed for bemærkninger",
    "description": "Du har mulighed for at sende dine bemærkninger …",
    "categoryId": 6,
    "categoryPath": "Forvaltningsret › Partshøring",
    "status": "active",
    "hasText": false,
    "hasFile": true,
    "fileFormat": "docx",
    "canEdit": true,
    "fileId": 3311,
    "editorUrl": "https://<server>/index.php/apps/eurooffice/3311?filePath=/.opencase-fraser/ef76655e….docx",
    "updatedAt": "2026-10-28T10:28:31+00:00",
    "formatNote": null
  }
]
```

- Sorted by `categoryPath`, then `title`. Show the category path as a
  heading or next to the title.
- `canEdit` is `true` for template managers.
- `editorUrl` is set when the user may edit the phrase and it has a file.
  It opens the stored file itself, so saving in the editor changes the
  phrase for everyone, as with templates. Close the file after editing:
  the changes count only once every user has closed it.

##### Åbn frase

1. List with `GET /phrases`. The user searches by title, description or
   category.
2. **File phrase:** open `editorUrl`.
3. **Text phrase:** it has no file. Either:
   - show the phrase dialog (below) to edit its text, or
   - give it a file with `POST /phrases/{id}/file` and the body
     `{ "blank": true }`, then open the returned `editorUrl`. The file is
     then used instead of the text.

##### Ny frase

The dialog has the same fields as **Opret frase** in OpenCase:

| Field | Request field | Content |
|-------|---------------|---------|
| **Titel** | `title` | Required. |
| **Beskrivelse** | `description` | Optional. Hint: "Kort beskrivelse af, hvornår frasen bruges". |
| **Tekst** | `content` | Rich text as HTML. Kept: paragraphs, headings 1–3, lists, block quotes, line breaks, bold, italic, underline, strike-through. |
| **Kategori** | `categoryId` | From `GET /phrase-categories`. `null` for none ("Ingen kategori"). |
| **Status** | `status` | `draft` (Kladde, the default), `active` (Aktiv) or `archived` (Arkiveret). Only active phrases go into letters. |
| **Afgrænsning** | `scopes` | Optional. Limits where the phrase can be used. Each scope is `{ "type": …, "value": … }`. |
| **Frasefil** | `file`, or `blankFile` | Optional. A `.docx` or `.odt` file, or a blank document to write in the editor. |

Scope types:
- `casetype`: a case type `id` from `GET /case-types`, as a string.
- `organisation`: an organisation `uuid` from `GET /organisations`. The
  phrase is then usable in cases under that organisation.
- `kle`: a KLE number, e.g. `00.01`.

To create a phrase to **write in the editor** (the natural choice in the
desktop client), send JSON with `blankFile: true`, then open the returned
`editorUrl`:

```json
{ "title": "Partshøring – frist", "categoryId": 6, "status": "draft", "blankFile": true }
```

For a **text phrase**, send `content` instead:

```json
{
  "title": "Anmodning om supplerende oplysninger",
  "content": "<p>Vi beder dig sende oplysningerne senest den …</p>",
  "categoryId": 11,
  "status": "active",
  "scopes": [ { "type": "casetype", "value": "2" }, { "type": "kle", "value": "00.01" } ]
}
```

To **upload a file**, send `multipart/form-data` with the fields above,
`scopes` as a JSON string, and the file in `file`.

The response is `201` with the phrase, as from `GET /phrases/{id}`.

- `400`: the title is missing, or a category, status or scope type is
  invalid. Nothing is saved.
- If the phrase was created but its file could not be stored, the
  response has `fileError`. The phrase exists; offer to upload the file
  again with `POST /phrases/{id}/file`.

##### Changing a phrase

- `GET /phrases/{id}` gives everything the dialog shows: the summary
  fields above, plus the following.

  ```json
  {
    "content": "<p>…</p>",
    "scopes": [ { "type": "casetype", "value": "1", "label": "Standard" } ],
    "templates": [ { "id": 9, "name": "Brev til borger", "isRequired": false, "isDefault": true, "matchesOrgs": true } ],
    "createdAt": "…", "createdBy": { "id": "admin", "displayName": "Administrator" }, "updatedBy": { "…": "…" }
  }
  ```

  `templates` lists the templates the phrase is attached to. Show it as
  "Bruges i skabeloner". When `matchesOrgs` is false, the phrase's scopes
  no longer match that template's organisations. Show "Afgrænsningen passer
  ikke til skabelonens organisationer".
- `PUT /phrases/{id}` takes any of the fields in the table. Only the fields
  sent are changed, and `scopes` replaces all the phrase's scopes. The
  response is the phrase.
- `POST /phrases/{id}/file` replaces the phrase's file. Send an upload
  (`multipart/form-data`, field `file`) or `{ "blank": true }`.

##### Indsæt frase

1. Let the user pick a phrase from `GET /phrases`. Template managers may
   see drafts; for other users pass `status=active`.
2. Get its text with `GET /phrases/{id}/content`:

   ```json
   {
     "id": 10,
     "title": "Partshøring – indledning",
     "status": "active",
     "source": "file",
     "html": "<p>Vi behandler i øjeblikket din sag vedrørende …</p><p>…</p>",
     "text": "Vi behandler i øjeblikket din sag vedrørende …\n…",
     "fileFormat": "docx",
     "fileUrl": "https://<server>/index.php/apps/opencase/desktop/v1/api/phrases/10/file"
   }
   ```

3. Insert `html` at the cursor, or `text` where HTML can't be pasted.

- **Text phrase** (`source: "text"`): `html` is its rich text.
- **File phrase** (`source: "file"`): `html` and `text` are the text of the
  file, paragraph by paragraph, without its formatting, tables or images.
  To keep the formatting, download the file from `fileUrl` (with Basic
  auth) and insert it as a document, if the editor can do that.
- Merge fields in the phrase (e.g. `{{sag.nummer}}`) are inserted as they
  are. In a template they are filled in when a file is created from it.

##### `GET /phrase-categories` and `GET /case-types`

```json
[ { "id": 10, "name": "Borgerservice", "parentId": null, "depth": 0, "path": "Borgerservice" },
  { "id": 11, "name": "Manglende oplysninger", "parentId": 10, "depth": 1, "path": "Borgerservice › Manglende oplysninger" } ]
```

Categories come in tree order: parents before their children, siblings
sorted as in OpenCase. Indent them by `depth`.

```json
[ { "id": 1, "name": "Standard" }, { "id": 2, "name": "Borgersag" } ]
```

#### Sending: Digital Post, link by email, PDF by email

The ribbon's send buttons are **Send Digital post**, **Send link med email**
and **Send PDF med email**. Every send is recorded in the document's audit
log, which shows on the document's **Log** tab in OpenCase:

| Send | Audit entry |
|------|-------------|
| Digital Post | `digital_post_queued` when sent, then `digital_post_sent` or `digital_post_failed` per receiver |
| Link by email | `document_link_sent_by_email` |
| PDF by email | `document_pdf_sent_by_email` |
| An email that failed | `document_send_by_email_failed`, with the variant |

All these endpoints need read access to the document. Without it, or if the
document doesn't exist, they return `404`.

##### Send Digital post

This works like **Digital post** on the document page in OpenCase. A
document is sent with Digital Post once. After that, the button shows the
status of that shipment.

**1. When the button is clicked**, call `GET /documents/{id}/digital-post`:

```json
{ "enabled": true, "canSend": true, "inProgress": false, "shipment": null }
```

- `enabled: false`: Digital Post isn't available on this server. Hide or
  disable the button. `canSendDigitalPost` in `GET /me` tells you this in
  advance, so the button can be hidden from the start.
- `shipment` set: show the status (step 4).
- `shipment` null and `canSend: true`: show the send dialog (step 2).
- `canSend: false` and no shipment: the user may not send this document. A
  document can be sent by someone who can change it, or by anyone who can
  read it once its status is final.

**2. Dialog "Digital post".** Fill it from
`GET /documents/{id}/digital-post/draft`:

```json
{
  "canSend": true,
  "canBeReplied": true,
  "includeAttachments": true,
  "mainFileId": 31,
  "files": [ { "id": 31, "name": "Brev.docx", "mimeType": "application/vnd.openxmlformats-officedocument.wordprocessingml.document" } ],
  "attachments": [
    { "id": 12, "documentnumber": "2026-00003-5", "title": "Bilag 1", "files": [ { "id": 40, "name": "Kort.pdf", "mimeType": "application/pdf" } ] }
  ],
  "receivers": [
    { "contactId": 10, "name": "Jens Hansen", "cprCvr": "0101801234", "addressProtected": false, "address": "Testvej 1, 4000 Roskilde" }
  ]
}
```

The dialog has these fields:

| Field | Content |
|-------|---------|
| **Kan besvares** | Checkbox, default `canBeReplied`. Whether the receiver can reply. |
| **Filer** | The document's files, with a radio button for **Hoveddokument**, the letter itself. The default is `mainFileId`. The other files go along as additional documents. |
| **Inkluder bilag** | Checkbox, default `includeAttachments`. When ticked, also list the files of `attachments` (the document's **Bilag**), and send them along. |
| **Modtagere** | `receivers`: the document's contacts with the role Modtager. All of them receive the letter. |

- If there are no receivers, the user must add a Modtager first (see
  "Senders and recipients"). Sending without one returns `400`.
- For each receiver with a `cprCvr`, call
  `GET /documents/{id}/contacts/{contactId}/digital-post-subscription`, one
  call per receiver and not waiting on each other. Show a tick or a cross
  from `{ "hasSubscription": true|false }`. `null` means it couldn't be
  checked.
- Show CPR numbers masked. When `addressProtected` is true, show
  "Beskyttet adresse" instead of the address.

**3. Send:** `POST /documents/{id}/digital-post` with this body:

```json
{ "mainFileId": 31, "canBeReplied": true, "includeAttachments": true }
```

- The server takes the receivers from the document's contacts and checks
  their Digital Post subscription itself.
- The response is `201` with the same content as
  `GET /documents/{id}/digital-post`, now with the shipment. Show the
  status (step 4).
- Errors:
  - `400`: no receivers, or `mainFileId` isn't a file of the document.
  - `409`: the document has already been sent with Digital Post. The server
    enforces this for every way of sending.
  - `501`: Digital Post isn't enabled.
  - `503`: the Digital Post lookup failed for a receiver with a CPR/CVR
    number. Nothing is sent, and the user can try again. `error` can be shown
    as it is.

**4. Dialog "Digital post – status"**, from the response's `shipment`:

```json
{
  "id": 3,
  "status": { "code": "Pending", "label": "Afventer", "kind": "progress" },
  "canBeReplied": true,
  "includeAttachments": true,
  "createdAt": "2026-10-28T14:39:06+00:00",
  "createdBy": { "id": "admin", "displayName": "Administrator" },
  "updatedAt": "2026-10-28T14:39:06+00:00",
  "files": [ { "id": 2, "name": "Brev.docx", "isMain": true, "hasPdf": false } ],
  "receivers": [
    { "id": 2, "name": "Jens Hansen", "cprCvr": "0101801234", "status": { "code": "Sent", "label": "Afsendt", "kind": "sent" }, "hasPdf": true, "messageId": "…" }
  ]
}
```

- Show the shipment's status and **Oprettet** (`createdAt`) at the top,
  then two tables:
  - **Yderligere dokumenter:** the files with `isMain: false`.
  - **Modtagere:** each receiver with its status.
- `status.label` is in the user's language. `status.kind` is `progress`,
  `sent` or `failed`; colour the label by it.
- When `hasPdf` is true, show **Download**, which fetches the PDF that was
  sent:
  - For a file: `GET /digital-post/files/{id}/pdf`.
  - For a receiver's letter: `GET /digital-post/receivers/{id}/pdf`.
- While `inProgress` is true (the status is Pending, Generating PDFs or
  Sending), call `GET /documents/{id}/digital-post` every 10 seconds, as
  OpenCase does. Also offer an **Opdater** button.
- The server sends the shipment in a background job. The user who sent it
  gets a Nextcloud notification when it is done.

##### Email: what both variants share

Email is sent through the user's own account in the Nextcloud **Mail** app.

- **Account:** check it first with `GET /email/account`:

  ```json
  { "mailAppEnabled": true, "configured": true, "from": { "address": "jens@kommune.dk", "label": "Jens Hansen" } }
  ```

  If `configured` is false, show "Der er ikke konfigureret en e-mailkonto i
  Mail-appen. Opret venligst en e-mailkonto i Mail-appen først." and don't
  open the dialog. Show `from` as **Fra**.
- **Til / Cc / Bcc:** search with
  `GET /documents/{id}/email/recipients?q=…` (at least 2 characters). It
  searches Nextcloud users, organisations, the case's participants and the
  document's contacts:

  ```json
  [ { "type": "user", "name": "Jens Hansen", "email": "jens@kommune.dk", "contactType": 1, "userId": "jens",
      "cprCvr": null, "pnumber": null, "phone": null, "addressProtected": false,
      "streetname": null, "housenumber": null, "floor": null, "door": null, "zipcode": null, "zipdistrict": null,
      "documentAccess": true } ]
  ```

  Send the chosen results back as they are, in `to`, `cc` and `bcc`. For an
  address the user types, send `{ "email": "…", "name": "…" }`. At least one
  `to` is required.
- **Emne** (`subject`): required.
- **Message** (`bodyHtml`): HTML. Only paragraphs, headings, lists, bold,
  italic, underline and strike-through are kept. Links, images and scripts
  are removed.
- **Gem som dokument** (`saveAsDocument`, default `true`): the sent email
  is also saved on the case as a new outgoing document (`.eml`), with the
  recipients as Modtager. This is the default in OpenCase too.
- **Response:** `{ "sent": true, "attachments": ["Brev.pdf"] }`.
- **Errors:**
  - `400`: no valid recipient, or no subject.
  - `412`: no Mail app account.
  - `502`: the mail server refused the email. This is logged on the
    document; `error` holds the reason.

##### Send link med email

`POST /documents/{id}/email/link` sends a link to the document in OpenCase,
without files:

```json
{
  "to": [ { "type": "user", "name": "Jens Hansen", "email": "jens@kommune.dk", "userId": "jens", "…": "…" } ],
  "cc": [],
  "bcc": [],
  "subject": "Dokument til gennemsyn",
  "bodyHtml": "<p>Hej Jens</p><p>Se venligst dokumentet.</p>",
  "saveAsDocument": true
}
```

- The server adds the link below the message, as a line with the
  document's number and title, e.g. "2026-00003-1 Kommunens styrelse i
  almindelighed".
- The link only works for OpenCase users who can read the document.
  Recipients from the search have `documentAccess`:
  - `true`: the link works for them.
  - `false`: they are an OpenCase user without access.
  - `null`: unknown; not an OpenCase user, typically an external address.
- For typed addresses, check with `POST /documents/{id}/email/access` and
  the body `{ "emails": ["a@b.dk"] }`. It returns
  `[{ "email": "a@b.dk", "documentAccess": null }]`.
- Warn before sending when a recipient doesn't have `true`, e.g. "Følgende
  modtagere kan ikke åbne linket: …". The user may still send it.

##### Send PDF med email

`POST /documents/{id}/email/pdf` sends the document's files as PDF
attachments. The body is the same as for the link, plus `fileIds`:

```json
{ "to": [ … ], "subject": "Afgørelse", "bodyHtml": "<p>…</p>", "fileIds": [31], "saveAsDocument": true }
```

- `fileIds`: the files to send. Let the user tick them, all ticked from the
  start. Left out, all the document's files are sent. Take the list from
  `files` in `GET /documents/{id}/digital-post/draft` or in the document
  info.
- **Conversion:** each file is converted to PDF on the server, with the
  case's and the document's merge fields filled in, as Digital Post does.
  PDF files are sent as they are. Each PDF is named after its file, e.g.
  `Brev.docx` becomes `Brev.pdf`.
- **Time:** conversion takes about 2 seconds per file. Show progress
  ("Sender…") and disable the button meanwhile.
- **Errors:**
  - `422`: a file couldn't be converted. `error` names it, e.g. `Filen
    "Data.zip" kunne ikke konverteres til PDF`. Nothing was sent.
  - `404`: a file wasn't found.

## Documentation

- [API.md](API.md) — REST API reference
- [openapi.json](openapi.json) — generated OpenAPI spec
- `docs/` — end-user and administrator documentation (Danish), built with
  MkDocs Material and published at https://docs.opencase.dk/. Preview
  locally with `mkdocs serve` (requires `pip install mkdocs-material
  mkdocs-awesome-pages-plugin`); scaffold new doc pages with `create-docs.sh`.
- [msoffice/README.md](msoffice/README.md) — Office add-ins architecture and deployment

## License

AGPL-3.0-or-later — see [LICENSE](LICENSE).
