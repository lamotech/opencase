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
