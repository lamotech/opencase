# OpenCase

OpenCase is a case and document management system (ESDH) for Danish
municipalities, built as a native Nextcloud app. It provides case handling,
document management with versioning, fine-grained access control, and
full-text search, while integrating with the Joint Municipal Infrastructure
(organisation, classification, CPR/CVR lookups, digital post) and with
Nextcloud's own apps (Files, Mail, Talk, Office).

## Features

- Case management with KLE classification codes
- Document management with versioning
- Fine-grained access control based on organisation, classification, and sensitivity
- Full-text search via Elasticsearch with Danish language support
- Native integration with Nextcloud Office (Collabora/ONLYOFFICE)
- Virtual filesystem integration — case files appear in Mail, Talk, and other apps
- Per-user document access overrides
- Digital post (Kombi Post) sending and receiving
- CPR/CVR lookups against Datafordeler
- Comprehensive REST API (see [API.md](API.md))

## Tech stack

| Layer | Technology |
|-------|------------|
| Backend | PHP 8.1+, Nextcloud App Framework (OCP), PSR-4 autoloading |
| Frontend | Vue 2, Vuex, Vue Router, `@nextcloud/vue` |
| Build | Webpack (`webpack.config.js`), via `@nextcloud/webpack-vue-config` |
| Search | Elasticsearch (Danish analyzer) |
| Auth | Nextcloud session / Bearer tokens, SAML (`onelogin/php-saml`) |
| Testing | PHPUnit (`tests/`), Psalm (static analysis) |

## Project structure

```
lib/
├── AppInfo/        Application bootstrap (DI registration, event listeners)
├── BackgroundJob/  Scheduled/queued jobs (sync, reindexing)
├── Command/        occ console commands
├── Controller/     REST API controllers (see API.md)
├── Dashboard/       Nextcloud dashboard widgets
├── Db/             Entities and mappers
├── Enum/           Shared enums (status, sensitivity, access level, ...)
├── Event/          Domain events (e.g. FileUpdatedEvent)
├── Listener/       Event listeners
├── Middleware/      Request middleware (auth/permission checks)
├── Migration/       Database schema + repair steps
├── Mount/          Virtual filesystem mount provider
├── Notification/    Nextcloud notifications integration
├── Search/         Elasticsearch indexing/query logic
├── Service/         Core business logic (PermissionService, etc.)
├── Settings/        Admin settings page
├── Storage/         Custom storage backend (OpenCaseStorage)
└── Versions/         File versioning backend

src/
├── views/          Page-level Vue components (cases, documents, search, ...)
├── components/     Reusable Vue components and dialogs
├── store/          Vuex store modules
├── services/       Frontend API clients
├── router/         Vue Router configuration
├── main.js          Files app integration entry point
├── files-plugin.js  Files app sidebar/actions plugin
├── talk-plugin.js   Nextcloud Talk integration entry point
├── widget.js         Dashboard widget entry point
└── admin-settings.js Admin settings page entry point

templates/          PHP templates (main app shell, login helper, admin settings)
tests/unit/         PHPUnit tests
appinfo/            info.xml (app metadata) and routes.php
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
| `lib/Storage/OpenCaseScanner.php` | No-op scanner — prevents Nextcloud from walking millions of files |
| `lib/Mount/OpenCaseMountProvider.php` | Registers the virtual mount for each authorised user |
| `lib/Service/PermissionService.php` | Central permission logic with caching |
| `lib/AppInfo/Application.php` | Bootstrap — registers mount provider and event listeners |
| `lib/Event/FileUpdatedEvent.php` | Dispatched when files are saved (triggers Elasticsearch reindex) |

See [img/architecture.svg](img/architecture.svg) for a visual overview.

## Requirements

- Nextcloud 32–34 (see `appinfo/info.xml`)
- PHP 8.1+
- Composer 2
- Node.js (version pinned in `.nvmrc`) and npm `^9` or `^10`
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
npm run build      # production build
npm run dev        # development build
npm run watch       # development build, rebuild on change
```

Enable the app via the Nextcloud admin UI, or:

```bash
php occ app:enable opencase
```

## Development

```bash
npm run lint        # ESLint
npm run lint:fix     # ESLint with autofix
composer lint        # PHP lint (php -l) on all files
composer cs:check     # PHP-CS-Fixer, dry run
composer cs:fix       # PHP-CS-Fixer, apply
composer psalm        # Static analysis
composer test:unit    # PHPUnit (tests/, config in tests/phpunit.xml)
```

`occ` commands registered by the app (see `appinfo/info.xml` and
`lib/Command/`) cover certificate/API client/token management, digital post,
organisation/classification sync, user/org lookups, CPR/CVR queries, and
search reindexing, e.g.:

```bash
php occ opencase:reindex
php occ opencase:sync-organisations
php occ opencase:has-digital-post <user>
```

## API

The REST API is mounted under `/ocs/v2.php/apps/opencase/api/v1`. See
[API.md](API.md) for endpoint documentation and [openapi.json](openapi.json)
for the generated OpenAPI spec (`composer openapi` regenerates it from the
controller annotations).

## License

AGPL-3.0-or-later — see [LICENSE](LICENSE).
