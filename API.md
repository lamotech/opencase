# OpenCase REST API Reference

Base URL: `/ocs/v2.php/apps/opencase/api/v1`

All requests require authentication via Nextcloud session cookie or `Authorization: Bearer <token>` header.  
All JSON request bodies require `Content-Type: application/json`.  
All responses use `OCS-APIRequest: true` header format.

---

## Cases

### List cases
```
GET /cases?organisation=Børn+og+Unge&year=2024&status=open&limit=50&offset=0
```

### Search cases
```
GET /cases?search=vandløb&limit=20
```

### Create a case
```
POST /cases
{
  "case_number": "2024-001234",
  "title": "Tilsyn med dagtilbud",
  "organisation": "Børn og Unge",
  "classification_code": "27.69.00",
  "sensitivity": "fortrolig"
}
```

### Get a case
```
GET /cases/42
```

### Update a case
```
PUT /cases/42
{
  "title": "Updated title",
  "sensitivity": "intern"
}
```

### Change case status
```
PUT /cases/42/status
{ "status": "closed" }
```
Valid transitions: open→closed, closed→open, closed→archived, archived→closed.

### Get statistics
```
GET /cases/stats
```
Returns case counts grouped by organisation and year.

---

## Documents

### List documents in a case
```
GET /cases/42/documents
```

### Create a document
```
POST /cases/42/documents
{
  "title": "Afgørelse om anbringelse",
  "document_type": "afgørelse"
}
```

### Get a document
```
GET /documents/789
```

### Update a document
```
PUT /documents/789
{
  "title": "Updated title",
  "status": "final"
}
```
Valid statuses: draft, active, final, archived.

### Delete a draft document
```
DELETE /documents/789
```
Only works for documents with status "draft".

### Set per-user document access
```
PUT /documents/789/access/jane
{ "access_level": "write" }
```
Valid levels: read, write, deny.

---

## Files

### List files in a document
```
GET /documents/789/files
```

### List all files in a case
```
GET /cases/42/files
```

### Upload a file
```
POST /documents/789/files
Content-Type: multipart/form-data

file: <binary>
```

### Get file metadata
```
GET /files/456
```

### Download a file
```
GET /files/456/download
```
Returns binary content with Content-Disposition header.

### Upload new version
```
POST /files/456/version
Content-Type: multipart/form-data

file: <binary>
```

### Delete a file
```
DELETE /files/456
```

---

## Search

### Full-text search
```
GET /search?q=borgerhenvendelse&organisation=Teknik+og+Miljø&year=2024&limit=20&offset=0
```

Query parameters:
| Parameter | Description |
|-----------|-------------|
| q | Required. Search query text |
| organisation | Filter by organisation |
| year | Filter by year |
| document_type | Filter by type (afgørelse, notat, brev, bilag) |
| mime_type | Filter by MIME type |
| case_id | Filter to a specific case |
| date_from | ISO 8601 start date |
| date_to | ISO 8601 end date |
| limit | Max results (default 20, max 100) |
| offset | Pagination offset |

Response includes highlighted text snippets and facet aggregations
for organisations, years, and document types.

---

## Brevdesigner (phrases, templates, logos)

Reading is open to every OpenCase user; creating, changing and deleting
requires the **Administrator** or **Template Designer** role (403
otherwise). Validation errors are returned as `{ "error": "..." }` with 400,
in the user's language.

### Phrase categories
| Method & path | Purpose |
|---|---|
| `GET /phrase-categories` | `{ "categories": [{id, name, description, parent_id, sort_order, phrase_count, created_at, created_by, updated_at, updated_by}] }` |
| `POST /phrase-categories` | Create. Body: `name` (required), `description`, `parent_id`, `sort_order` |
| `PUT /phrase-categories/{id}` | Update any of the above. A category cannot be moved under itself or a sub-category |
| `DELETE /phrase-categories/{id}` | Delete — 409 while it has sub-categories or phrases |

### Phrases
A phrase's letter text is its **file** (`.docx`/`.odt`) if it has one,
otherwise its rich text **`content`** (HTML from the phrase editor; sanitised
on save to paragraphs, headings 1–3, lists, quotes, line breaks, bold,
italic, underline, strike-through). `description` is only a description.
`status` is `draft`, `active` or `archived`; only active phrases go into
letters. `scopes` limit a phrase to case types, organisations (incl.
sub-organisations) and KLE numbers, like custom fields.

All phrase endpoints are for Administrators and Template Designers.

| Method & path | Purpose |
|---|---|
| `GET /phrases` | `{ "phrases": [{id, category_id, title, description, content, has_content, original_filename, mime_type, size, has_file, status, scopes, created_at, created_by, updated_at, updated_by}] }` |
| `POST /phrases` | Create. Body: `title` (required), `description`, `content`, `category_id`, `status`, `scopes: [{scope_type: casetype\|organisation\|kle, scope_value}]` |
| `PUT /phrases/{id}` | Update any of the above (`scopes` replaces all scopes) |
| `DELETE /phrases/{id}` | Delete — also removes it from the templates it is attached to |
| `GET /phrases/{id}/templates` | Templates using it: `{ "templates": [{id, name, original_filename, is_required, is_default, org_uuids, matches_orgs}] }` |
| `POST /phrases/{id}/file` | Upload/replace the file (multipart `file`, `.docx`/`.odt`) |
| `POST /phrases/{id}/new-blank` | Give it a blank document; response includes `nc_file_id` |
| `POST /phrases/{id}/open-for-edit` | `{ "nc_file_id" }` — open `/f/{nc_file_id}` in Nextcloud Office |
| `GET /phrases/{id}/download` | The phrase file |

### Templates
Template files are `.docx`, `.odt`, `.xlsx` or `.ods`; they live only in
`<data dir>/opencase_storage/_templates` (phrases in `_phrases`) and are edited
there in place in Nextcloud Office — no per-user copies.

| Method & path | Purpose |
|---|---|
| `GET /templates` | `{ "templates": [{id, name, original_filename, mime_type, size, uploaded_by, created_at, phrase_count, org_uuids}] }`. Optional `?document_id=` or `?case_id=`: only templates available for that case's organisation |
| `POST /templates` | Upload (multipart): `file`, `name`, and optionally `org_uuids` and `phrases` as JSON strings — all in one request; nothing is kept if any part is invalid |
| `POST /templates/new-blank` | Create a blank document. Body: `name`, optional `org_uuids`, `phrases`. Response includes `nc_file_id` |
| `PUT /templates/{id}` | Body: any of `name`, `org_uuids` (`[]` = all cases) |
| `DELETE /templates/{id}` | Delete |
| `GET /templates/{id}/phrases` | `{ "phrases": [{phrase_id, sort_order, is_default, is_required, title, description, category_id, status, has_file, has_content}] }`. Optional `?document_id=` / `?case_id=`: only phrases usable in a letter for that case (active, with content, scopes matching the case) |
| `PUT /templates/{id}/phrases` | Replace the attached phrases. Body: `phrases: [{phrase_id, is_default, is_required}]` in order. A required phrase is always pre-selected; phrases must match the template's organisations |
| `POST /templates/{id}/open-for-edit` | `{ "nc_file_id" }` — open in Nextcloud Office |
| `GET /templates/{id}/download` | The template file |

A template limited to organisations (`org_uuids`) is available only for cases
in those organisations and their sub-organisations, and only phrases whose
organisation scope covers one of them (or that have none) can be attached.

### Create a file from a template
```
POST /documents/{docId}/files/from-template
{ "template_id": 7, "filename": "Afgørelse", "phrase_ids": [3, 5] }
```
`phrase_ids` (optional): the phrases to merge into `{{fraser.indhold}}`.
Required phrases are always included; omitted → the template's pre-selected
phrases. Only phrases usable for the case are merged. The organisation's logo
is inserted at `{{organisation.logo}}`. Returns 400 when the template is not
available for the case's organisation.

### Organisation logos
| Method & path | Purpose |
|---|---|
| `GET /org/{orgUuid}/logo-info` | `{ has_own_logo, logo_org_uuid, logo_org_name }` — the logo used in templates: the organisation's own or the nearest parent's |
| `GET /org/{orgUuid}/logo` | The organisation's own logo (404 without one) |
| `POST /org/{orgUuid}/logo` | Upload/replace (multipart `file`: PNG, JPEG, GIF or SVG, max 5 MB) |
| `DELETE /org/{orgUuid}/logo` | Remove — the parent's logo is used again |

Logos are stored in `<data dir>/opencase_storage/_logos`.

### Merge fields
The full list is on the *Skabelonfelter* page (`/apps/opencase/templates/fields`).
Brevdesigner fields: `{{fraser.indhold}}` / `{{phrases.content}}` (place it
alone in a paragraph to keep the phrases' formatting), `{{organisation.logo}}`
(as text: a 2 cm logo; as the alt text of a picture: the logo fitted into the
picture), `{{organisation.email}}`, `{{organisation.telefon}}` /
`{{organisation.phone}}`, `{{organisation.adresse}}` / `{{organisation.location}}`,
`{{sag.ansvarlig.email|telefon|adresse}}`, `{{sag.indsigtsgrad}}`,
`{{sag.handlingsfacet}}`, `{{dokument.kategori}}`, `{{dokument.dokumentdato}}`,
`{{dokument.modtagetdato}}`, `{{dokument.registreretdato}}`,
`{{dokument.indsigtsgrad}}` (English forms on the fields page).
`{{adresse.*}}` fields are filled per recipient when the document is sent
with Digital Post.

---

## Desktop API (Office desktop clients)

Not under `/api/v1` — full paths are `/index.php/apps/opencase/desktop/v1/api/...`.
Authenticated with Basic auth (login name + app password). Login (`POST /login`,
`POST /login/poll`) and logout (`POST /logout`) are described in
[README.md](README.md#login-and-backend-api).

### Current user
```
GET /index.php/apps/opencase/desktop/v1/api/me
```
`{id, displayName, isOpenCaseUser, canManageTemplates, canSendDigitalPost, registerSearch:{citizens, companies}}`.
Also answers users without OpenCase access (`isOpenCaseUser: false`). See
[README.md](README.md#the-current-user).

### Favorite documents
```
GET /index.php/apps/opencase/desktop/v1/api/documents/favorites
```

### Recently accessed documents
```
GET /index.php/apps/opencase/desktop/v1/api/documents/recent
```

### Documents created by the user
```
GET /index.php/apps/opencase/desktop/v1/api/documents/my-documents?limit=50&offset=0
```
Same response as `recent`. Total count in the `X-Total-Count` header.

### Saving a file to a case
```
GET  /index.php/apps/opencase/desktop/v1/api/cases?search=2026-00003
GET  /index.php/apps/opencase/desktop/v1/api/document-categories
GET  /index.php/apps/opencase/desktop/v1/api/document-types
GET  /index.php/apps/opencase/desktop/v1/api/insight-levels
POST /index.php/apps/opencase/desktop/v1/api/documents
POST /index.php/apps/opencase/desktop/v1/api/documents/{id}/files
```
Fields and responses: see [README.md](README.md#saving-a-file-to-a-case).

### Document info for a file
```
GET /index.php/apps/opencase/desktop/v1/api/files/{fileId}/document
```
`fileId` is the Nextcloud file ID. Response: see [README.md](README.md#document-info-for-a-file).
Also puts the document on the user's recent list, so call it whenever a file is opened.

### Templates
```
GET /index.php/apps/opencase/desktop/v1/api/templates?search=brev&caseId=3
```
Response: see [README.md](README.md#templates).

### Template merge fields
```
GET /index.php/apps/opencase/desktop/v1/api/templates/fields
```
Response: see [README.md](README.md#merge-fields).

### Creating a template
```
GET  /index.php/apps/opencase/desktop/v1/api/organisations
GET  /index.php/apps/opencase/desktop/v1/api/phrases?orgUuids=…&templateFormat=blank
POST /index.php/apps/opencase/desktop/v1/api/templates
```
Fields and responses: see [README.md](README.md#creating-a-template).

### New file from a template
```
GET  /index.php/apps/opencase/desktop/v1/api/templates?documentId={id}
GET  /index.php/apps/opencase/desktop/v1/api/templates/{templateId}/phrases?documentId={id}
POST /index.php/apps/opencase/desktop/v1/api/documents/{id}/files/from-template
```
Fields and responses: see [README.md](README.md#new-file-from-a-template).

### Document panel: status, favourite, contacts, notes
```
GET    /index.php/apps/opencase/desktop/v1/api/document-statuses
PUT    /index.php/apps/opencase/desktop/v1/api/documents/{id}/status
PUT    /index.php/apps/opencase/desktop/v1/api/documents/{id}/favorite
DELETE /index.php/apps/opencase/desktop/v1/api/documents/{id}/favorite
GET    /index.php/apps/opencase/desktop/v1/api/contact-roles
GET    /index.php/apps/opencase/desktop/v1/api/citizens/search
GET    /index.php/apps/opencase/desktop/v1/api/companies/search
GET    /index.php/apps/opencase/desktop/v1/api/documents/{id}/contacts
POST   /index.php/apps/opencase/desktop/v1/api/documents/{id}/contacts
DELETE /index.php/apps/opencase/desktop/v1/api/documents/{id}/contacts/{contactId}
POST   /index.php/apps/opencase/desktop/v1/api/documents/{id}/contacts/{contactId}/address
GET    /index.php/apps/opencase/desktop/v1/api/documents/{id}/notes
POST   /index.php/apps/opencase/desktop/v1/api/documents/{id}/notes
PUT    /index.php/apps/opencase/desktop/v1/api/documents/{id}/notes/{noteId}
DELETE /index.php/apps/opencase/desktop/v1/api/documents/{id}/notes/{noteId}
```
Fields and responses: see [README.md](README.md#document-panel-status-favourite-senders-and-recipients-notes).

### Sending: Digital Post, link by email, PDF by email
```
GET  /index.php/apps/opencase/desktop/v1/api/documents/{id}/digital-post
GET  /index.php/apps/opencase/desktop/v1/api/documents/{id}/digital-post/draft
GET  /index.php/apps/opencase/desktop/v1/api/documents/{id}/contacts/{contactId}/digital-post-subscription
POST /index.php/apps/opencase/desktop/v1/api/documents/{id}/digital-post
GET  /index.php/apps/opencase/desktop/v1/api/digital-post/files/{fileId}/pdf
GET  /index.php/apps/opencase/desktop/v1/api/digital-post/receivers/{receiverId}/pdf
GET  /index.php/apps/opencase/desktop/v1/api/email/account
GET  /index.php/apps/opencase/desktop/v1/api/documents/{id}/email/recipients?q=…
POST /index.php/apps/opencase/desktop/v1/api/documents/{id}/email/access
POST /index.php/apps/opencase/desktop/v1/api/documents/{id}/email/link
POST /index.php/apps/opencase/desktop/v1/api/documents/{id}/email/pdf
```
Fields and responses: see [README.md](README.md#sending-digital-post-link-by-email-pdf-by-email).

### Phrases
```
GET  /index.php/apps/opencase/desktop/v1/api/phrases?search=…&status=active
POST /index.php/apps/opencase/desktop/v1/api/phrases
GET  /index.php/apps/opencase/desktop/v1/api/phrases/{id}
PUT  /index.php/apps/opencase/desktop/v1/api/phrases/{id}
POST /index.php/apps/opencase/desktop/v1/api/phrases/{id}/file
GET  /index.php/apps/opencase/desktop/v1/api/phrases/{id}/file
GET  /index.php/apps/opencase/desktop/v1/api/phrases/{id}/content
GET  /index.php/apps/opencase/desktop/v1/api/phrase-categories
GET  /index.php/apps/opencase/desktop/v1/api/case-types
```
Fields and responses: see [README.md](README.md#phrases-fraser).

### Full-text search (files)
```
GET /index.php/apps/opencase/desktop/v1/api/documents/fulltextsearch?q=kommune&limit=50&offset=0
```
Filters, facets and response format: see [README.md](README.md#full-text-search).

Query parameters:
| Parameter | Description |
|-----------|-------------|
| client | Editor to build `editorUrl` for (default `eurooffice`) |
| limit | Max documents (default 50, max 200) |

Deleted documents and documents the user can no longer read are left out.
Response (JSON array, newest first):
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

---

## Admin (requires admin privileges)

### Access profiles

```
GET  /admin/profiles?organisation=Børn+og+Unge
POST /admin/profiles
     { "organisation": "...", "classification_code": "27.69.00", "sensitivity": "intern" }
GET  /admin/profiles/5/users
```

### User access management

```
POST   /admin/users/jane/access
       { "access_profile_id": 5, "access_level": "write" }

DELETE /admin/users/jane/access/5

POST   /admin/users/jane/bulk-access
       { "profile_ids": [1, 2, 3, 5], "access_level": "read" }

DELETE /admin/users/jane/access
       (revokes ALL access — use for offboarding)
```

### Elasticsearch management

```
POST /admin/es/setup
     Creates/updates ES index with Danish analyzer and ingest pipeline.

POST /admin/reindex
     Triggers full re-index (background, batched).

POST /admin/reindex/42
     Re-indexes all files in case 42.
```

---

## Public API

Machine-to-machine API for integrating other municipal systems with
OpenCase. Base URL: `/index.php/apps/opencase/public/v1/api`. 

A companion [public-api.schema.json](public-api.schema.json) gives the exact
JSON Schema (draft 2020-12) for every request body and response shown below;
`$ref` it from validators, codegen, or an IDE's JSON-schema-backed
autocomplete. The controller source (`lib/Controller/PublicApi/`) is the
source of truth if the two ever disagree.

**Content negotiation**: JSON by default; send `Accept: application/xml` for
XML instead (schema: [appinfo/schema/case-export.xsd](appinfo/schema/case-export.xsd),
namespace `https://opencase.dk/case`). Request bodies may be JSON or XML
(detected from `Content-Type`); the field names are identical either way —
XML wraps them in an element matching the response's root element (e.g.
`<Case><Title>...</Title></Case>`).

**⚠️ Every scalar field — including `Id`, `StatusId`, and every other
numeric-looking field — is serialized as a **JSON string**, not a JSON
number, and every boolean field (e.g. `HasAddressProtection`) is the string
`"true"`/`"false"`, not a JSON boolean.** This falls out of the field
builders in `CaseExportService` being shared verbatim between the XML export
and the JSON public API. The exception is the ad-hoc `Id`/`PriorCanWrite`
fields returned inline by `addParticipants`/`addCaseworkers`/`addContacts`
(see below), which are native JSON numbers — the schema flags each field's
actual type explicitly, so check it rather than assuming.

Fields that are `null`/absent are omitted from XML but present as JSON
`null` in the JSON response.

**Errors**: any endpoint returns `{ "error": "<message>" }` (or an XML
`<Error>` element) with a 4xx/5xx status on failure. Bulk endpoints
(`addParticipants`, `addCaseworkers`, `addContacts`) instead return **HTTP
200/201 with a per-item result list**, where a failed item carries an
`Error` field instead of the created record — a single bad entry never fails
the whole request; see each endpoint below.

### Authentication

Access to the OpenCase API is secured using **token-based authentication** based on the Danish Common Municipal Service Platform (**Serviceplatformen**) and follows the **SF1512 Security Token Service (STS)** specification.

Clients must obtain a valid security token from SF1512 before invoking any API endpoint.

---

#### Prerequisites

Before your application can authenticate against the OpenCase API, the following prerequisites must be fulfilled:

- A valid **Service Agreement (Serviceaftale)** for the **OpenCase API** must be established in **Fælleskommunal Administration**.
- The calling system must be configured to authenticate against **Serviceplatformen SF1512**.
- The client must possess the required certificates and credentials for requesting security tokens from the Security Token Service (STS).

---

#### Obtaining an access token

Request a security token from **Serviceplatformen SF1512** for the following Entity ID:

```text
http://opencase.dk/service/api/1
```

The issued SAML token represents the authenticated client and is used to authorize subsequent API requests.

---

#### Calling the API

Include the issued token in the HTTP `Authorization` header using the Bearer authentication scheme.

```http
Authorization: Bearer <Base64-encoded token>
```

The token must be Base64 encoded before it is included in the request header.

Example:

```http
GET /index.php/apps/opencase/public/v1/api/cases HTTP/1.1
Host: api.example.dk
Authorization: Bearer PHNhbWwyOkFzc2VydGlvbj4uLi48L3NhbWwyOkFzc2VydGlvbj4=
```

---

#### Token validation

For each request, OpenCase validates:

- the authenticity of the SAML token
- the token signature
- the issuing Security Token Service
- token validity period
- the requested audience (`http://opencase.dk/service/api/1`)

Requests containing an invalid, expired or incorrectly issued token are rejected with an authentication error.

!!! note
    The public key of your certificate must be registered in OpenCase for validation.

---

#### Authentication flow

The authentication process consists of the following steps:

1. The client requests a SAML token from **Serviceplatformen SF1512**.
2. SF1512 issues a signed security token for the OpenCase API.
3. The client Base64-encodes the token.
4. The token is included in the `Authorization` header as a Bearer token.
5. OpenCase validates the token before processing the request.

```text
Client
   │
   │ Request token
   ▼
Serviceplatformen SF1512
   │
   │ SAML Token
   ▼
Client
   │
   │ Authorization: Bearer <Base64 token>
   ▼
OpenCase API
```


### Cases — `PublicCaseApiController`

| Method & path | Purpose |
|---|---|
| `GET /cases?uuid=...` or `?case_number=...` | Get a single case (`CaseType` minus `Documents`/`JournalNotes`) |
| `GET /cases/search?...` | Search cases by metadata |
| `POST /cases` | Create a case |
| `PUT /cases` | Update a case's metadata |
| `GET /cases/documents?uuid=...` or `?case_number=...` | List a case's documents |
| `POST /cases/documents` | Create a document on a case (metadata only, no file) |
| `GET /cases/journal-notes?uuid=...` or `?case_number=...` | List a case's journal notes |
| `POST /cases/journal-notes` | Add a journal note to a case |
| `POST /cases/participants` | Add one or more participants to a case |
| `POST /cases/caseworkers` | Add one or more additional caseworkers to a case |

#### Get a case
```
GET /cases?uuid=8f14e...
GET /cases?case_number=2024-001234
```
Exactly one of `uuid`/`case_number` is required. Response: `{ "Case": CaseType }`
(schema: `#/$defs/CaseType`), with `Participants`/`Caseworkers` nested but
**not** `Documents`/`JournalNotes` (use the endpoints below for those).

#### Search cases
```
GET /cases/search?organisation=Børn+og+Unge&year=2024&search=vandløb&limit=50&offset=0
```
Query parameters (all optional): `search`, `organisation`, `year`,
`status_id`, `classification_code`, `sensitivity_key`,
`classification_facet_uuid`, `insight_level_id`, `responsible_user_id`,
`casetype_id`, `class_plan_code` (Emneplan item — matches part of its code or
title), `limit` (default 50, max 1000), `offset`. Response:
`{ "CaseSearchResult": { "Total", "Limit", "Offset", "Cases": [CaseType, ...] } }`.

#### Create a case
```json
POST /cases
{
  "Title": "Tilsyn med dagtilbud",
  "OrgUuid": "5a1e...",
  "ClassificationCodeUuid": "27.69.00-uuid",
  "SensitivityUuid": "intern-uuid",
  "ClassificationFacetUuid": "facet-uuid",
  "ResponsibleUserId": "jane",
  "InsightLevelId": 1,
  "CasetypeId": 2,
  "ParentCaseId": null,
  "Summary": "<p>Rich-text summary</p>"
}
```
`Title`, `ClassificationFacetUuid`, and one of each `{OrgUuid | organisation}`,
`{ClassificationCodeUuid | classification_code}`,
`{SensitivityUuid | sensitivity}` are required — the `Uuid` variants resolve
via `GET /organisations`, `/kle-numbers`, `/sensitivities`; the plain
variants take the name/code/key directly. `ResponsibleUserId`,
`InsightLevelId`, `CasetypeId`, `ParentCaseId`, `Summary` are optional.

That is with the default access profile type, FK Adgang. With Grupper
(`accessprofile_type` = `group`) access is given by a Nextcloud group instead:
`GroupId` (or `group_id`, see `GET /groups`) is required. With the KLE
classification plan, `ClassificationCodeUuid`, `SensitivityUuid` and
`ClassificationFacetUuid` are still required and are stored on the case; with
an Emneplan (`classification_plan` = `Emneplan`) they are not, and
`ClassPlanUuid` is required instead. `CaseType` carries `GroupId`/`GroupName`
for such cases.
Lowercase/snake_case field names are also accepted throughout. Response
(201): `{ "Case": CaseType }`.

With the classification plan Emneplan (`classification_plan` = `Emneplan`)
the case is also classified by an item of the customer's own Emneplan:
`ClassPlanUuid` (see `GET /classification-plan`) or `class_plan_code`
(matched by code — which is the topic name for plans without codes) is then
required. The KLE fields still apply with FK Adgang, where the KLE number is
part of the access profile. `CaseType` carries `ClassPlanUuid`,
`ClassPlanCode` and `ClassPlanTitle`.

#### Update a case
```json
PUT /cases
{ "Uuid": "8f14e...", "Title": "Updated title", "SensitivityUuid": "fortrolig-uuid" }
```
`Uuid` or `CaseNumber` is required to identify the case; any subset of the
Create fields may follow — omitted fields are left unchanged.
`ClassificationFacetUuid`, `InsightLevelId`, `Summary` can be explicitly
cleared by sending them as an empty string. Response: `{ "Case": CaseType }`.

#### List / create a case's documents
```
GET  /cases/documents?case_number=2024-001234
```
Response: `{ "Documents": { "Document": [DocumentType, ...] } }` in XML, or
`{ "Documents": [DocumentType, ...] }` in JSON (all list wrappers follow this
shape — see `public-api.schema.json` for the exact JSON form).
```json
POST /cases/documents
{ "CaseNumber": "2024-001234", "Title": "Afgørelse", "DocumentCategoryId": 3 }
```
`Uuid`/`CaseNumber` (one of) and `Title`, `DocumentCategoryId` are required;
`DocumentType`, `InsightLevelId`, `DocumentDate`, `ReceivedDate`,
`RegisteredDate` are optional. Creates metadata only — no attached file.
Response (201): `{ "Document": DocumentType }` (with empty
`Files`/`Contacts`/`Notes`/`WorkflowHistory`).

#### List / add a case's journal notes
```
GET /cases/journal-notes?uuid=8f14e...
```
Response: `{ "JournalNotes": [NoteType, ...] }`.
```json
POST /cases/journal-notes
{ "Uuid": "8f14e...", "Title": "Telefonopkald", "Text": "Ringede til borger ..." }
```
`Uuid`/`CaseNumber` (one of) and `Title` are required; `Text` optional.
Response (201): `{ "JournalNote": NoteType }`.

#### Add participants to a case
```json
POST /cases/participants
{
  "CaseNumber": "2024-001234",
  "Participants": [
    { "ParticipantRoleId": 1, "CprCvr": "0101011234" }
  ]
}
```
`Uuid`/`CaseNumber` (one of); `Participants` is a list of
`{ParticipantRoleId, CprCvr}` (role: see `GET /participantroles`; `CprCvr`:
10 digits → CPR lookup via Datafordeler, 8 digits → CVR lookup — name and
address come back from that lookup, not the request). Requires write access
to the case. Each entry is resolved independently; the response is a
per-entry result list (status 201 if at least one succeeded, else 400):
```json
{ "Participants": [
  { "Id": 12, "RoleId": 1, "RoleName": "Part", "ContactType": 1,
    "CprCvr": "0101011234", "Name": "Jane Citizen", "...": "...",
    "HasAddressProtection": "false" }
] }
```
An entry that failed (bad role/CPR-CVR, lookup miss, duplicate) has
`{ "CprCvr": "...", "Error": "..." }` instead. Note `Id`/`RoleId`/`ContactType`
here are native JSON numbers, unlike the string-typed fields elsewhere in
this API — see `#/$defs/ParticipantAddResult` in the schema.

#### Add caseworkers to a case
```json
POST /cases/caseworkers
{ "CaseNumber": "2024-001234", "Caseworkers": [ { "UserId": "jane" } ] }
```
`Uuid`/`CaseNumber` (one of); `Caseworkers` is a list of `{UserId}` (a
Nextcloud user id). Requires write access to the case. Resolved
independently per entry, same 201/400 + per-item `Error` pattern as
participants above. Granting write access to a user who previously had less
(or no) access records their prior level in `PriorCanWrite` so it can be
restored if the caseworker assignment is later removed.

### Documents — `PublicDocumentApiController`

| Method & path | Purpose |
|---|---|
| `GET /documents?uuid=...` or `?document_number=...` | Get a single document (`DocumentType`, with `Files`/`Contacts`/`Notes`/`WorkflowHistory` nested) |
| `GET /documents/search?...` | Search documents by metadata |
| `PUT /documents` | Update a document's metadata |
| `POST /documents/contacts` | Add one or more contacts to a document |
| `POST /documents/files` | Upload a file to a document (base64 body) |
| `POST /documents/files/from-template` | Create a file by merging a template |
| `POST /documents/notes` | Add a note to a document |

#### Get a document
```
GET /documents?document_number=2024-001234-3
```
Response: `{ "Document": DocumentType }`.

#### Search documents
```
GET /documents/search?title=afgørelse&status=2&limit=50&offset=0
```
Query parameters (all optional): `title`, `document_type`, `status`,
`date_from`, `date_to`, `org_name`, `document_category_id`,
`insight_level_id`, `created_by`, `limit` (default 50, max 1000), `offset`.
Response: `{ "DocumentSearchResult": { "Total", "Limit", "Offset", "Documents": [{Id, Uuid, DocumentNumber, Title, DocumentType, Status, DocumentDate, ReceivedDate, CreatedAt, UpdatedAt, CreatedBy, CaseId, CaseNumber, CaseTitle, OrgName}, ...] } }`
— note this is a flatter, search-result-specific shape, not the full `DocumentType`.

#### Update a document
```json
PUT /documents
{ "Uuid": "9c2b...", "Status": 3 }
```
`Uuid`/`DocumentNumber` (one of) required; any subset of `Title`,
`DocumentType`, `Status` (1=draft/active, 2=?, 3=final — see
`GET /documentstatus` for the active code list), `InsightLevelId`,
`DocumentDate`, `ReceivedDate`, `RegisteredDate` may follow. Omitted fields
are unchanged; `InsightLevelId`/`DocumentDate`/`ReceivedDate`/`RegisteredDate`
can be cleared by sending them empty. Setting a **final** status locks the
document's files read-only (matches the internal UI). Response:
`{ "Document": DocumentType }`.

#### Add a note to a document
```json
POST /documents/notes
{ "Uuid": "9c2b...", "Title": "Kvalitetssikret", "Text": "..." }
```
`Uuid`/`DocumentNumber` (one of) and `Title` required, `Text` optional.
Requires write access to the document's case. Response (201):
`{ "Note": NoteType }`.

#### Add contacts to a document
```json
POST /documents/contacts
{
  "DocumentNumber": "2024-001234-3",
  "Contacts": [ { "ContactRoleId": 2, "CprCvr": "12345678" } ]
}
```
Same shape/semantics as `POST /cases/participants` above, but for a
document's sender/receiver contacts (`ContactRoleId`: see
`GET /contactroles`). Requires write access to the document's case.
Response: `{ "Contacts": [ContactAddResult, ...] }`, 201/400 depending on
whether any entry succeeded.

#### Upload a file to a document
```json
POST /documents/files
{ "DocumentNumber": "2024-001234-3", "FileName": "afgørelse.pdf", "Content": "<base64>", "MimeType": "application/pdf" }
```
`Uuid`/`DocumentNumber` (one of), `FileName`, `Content` (base64) required;
`MimeType` optional (default `application/octet-stream`). There is no
`multipart/form-data` upload on this API — content is base64 in the JSON/XML
body. Response (201): `{ "File": FileType }`.

#### Create a file from a template
```json
POST /documents/files/from-template
{ "DocumentNumber": "2024-001234-3", "TemplateId": 7 }
```
`Uuid`/`DocumentNumber` (one of) and `TemplateId` (see `GET /templates`)
required. Merges the template with case/document metadata
(e.g. `{{case.number}}`, `{{sag.titel}}`) and attaches the result as a new
file. Response (201): `{ "File": FileType }`.

The template's pre-selected and required phrases that are usable for the
case (active, scopes matching the case) are merged into `{{fraser.indhold}}`,
and the case organisation's logo (or its nearest parent's) into
`{{organisation.logo}}`. A template limited to organisations (see
`OrgUuids` in `GET /templates`) can only be used for cases in those
organisations or below them — otherwise 400.

### Files — `PublicFileApiController`

| Method & path | Purpose |
|---|---|
| `GET /files?uuid=...` | Get a file's metadata + base64 content |
| `POST /files/version` | Upload a new version of an existing file |
| `GET /files/versions?uuid=...` | List a file's historical (superseded) versions |
| `GET /files/versions/content?uuid=...` | Get a historical version's metadata + base64 content |

#### Get a file
```
GET /files?uuid=3af0...
```
Response: `{ "File": FileType }` with an added `Content` field (base64 of
the current/live content).

#### Upload a new version
```json
POST /files/version
{ "Uuid": "3af0...", "Content": "<base64>" }
```
Keeps the existing filename/MIME type; increments `Version`. Response:
`{ "File": FileType }` (updated).

#### List historical versions
```
GET /files/versions?uuid=3af0...
```
Response: `{ "FileVersions": [FileVersionType, ...] }`, newest first. Only
superseded versions — the current content is not included; use
`GET /files?uuid=...` for that.

#### Get a historical version's content
```
GET /files/versions/content?uuid=<fileversion-uuid>
```
Note this `uuid` identifies the **file version** row, not the file.
Response: `{ "FileVersion": FileVersionType }` with an added `Content` field
(base64).

### Reference data — `PublicReferenceApiController`

Code lists and other lookup values referenced by the endpoints above (e.g.
`StatusId`, `DocumentCategoryId`). All accept an optional `?lang=` (default
`da`, falling back to `en` if the requested language has no rows) and return
only active/non-expired entries.

| Method & path | Response |
|---|---|
| `GET /casestatus` | `{ "CaseStatuses": [{Id, Name, IsClosed, Expired}, ...] }` |
| `GET /casetype` | `{ "CaseTypes": [{Id, Name, PrimaryParticipant, Expired}, ...] }` |
| `GET /contactroles` | `{ "ContactRoles": [{Id, Name, Expired}, ...] }` |
| `GET /documentcategory` | `{ "DocumentCategories": [{Id, Name, Expired}, ...] }` |
| `GET /documentstatus` | `{ "DocumentStatuses": [{Id, Name, IsFinal, Expired}, ...] }` |
| `GET /insightlevel` | `{ "InsightLevels": [{Id, Name, Description}, ...] }` |
| `GET /participantroles` | `{ "ParticipantRoles": [{Id, Name, Expired}, ...] }` |
| `GET /organisations` | `{ "Organisations": [{Uuid, Name}, ...] }` — scoped to the acting user's access, like `GET /cases/search` |
| `GET /kle-numbers` | `{ "KleNumbers": [{Uuid, Code, Title}, ...] }` — KLE classification subjects ("emneord") |
| `GET /classification-plan` | `{ "ClassificationPlanItems": [{Uuid, Code, Title, Description}, ...] }` — the Emneplan items for `ClassPlanUuid` on `POST /cases`; Code equals Title when the plan has no codes |
| `GET /classification-facets` | `{ "ClassificationFacets": [{Uuid, Code, Title}, ...] }` — "handlingsfacetter" |
| `GET /sensitivities` | `{ "Sensitivities": [{Uuid, Key, Title}, ...] }` — `Key` is what `POST /cases` expects as `sensitivity` |
| `GET /groups` | `{ "Groups": [{GroupId, Name}, ...] }` — access groups for `GroupId` on `POST /cases`; empty unless the access profile type is Grupper |
| `GET /users` | `{ "Users": [{Id, DisplayName}, ...] }` — Nextcloud users with an OpenCase role, sorted by display name |
| `GET /templates` | `{ "Templates": [{Id, Name, OriginalFilename, MimeType, Size, UploadedBy, CreatedAt, OrgUuids}, ...] }` — `OrgUuids`: the organisations a template is limited to (`[]` = all cases) |

Note the fields in this section are plain JSON types (numbers/booleans),
**not** the string-encoded scheme used by the Case/Document/File resources
above — see `public-api.schema.json` for the precise per-endpoint types.

### Search — `PublicSearchApiController`

```
GET /search?q=borgerhenvendelse&organisation=Teknik+og+Miljø&year=2024&limit=20&offset=0
```
Mirrors the internal `/search` page: searches case/document metadata in the
database plus file content/metadata in Elasticsearch, filtered by the acting
user's access profiles. Query parameters: `q` (required), `organisation`,
`year`, `document_type`, `mime_type`, `case_id`, `date_from`, `date_to`
(all optional), `limit` (default 20, max 1000), `offset`. Response:
```json
{ "SearchResult": {
  "Total": "42", "Limit": "20", "Offset": "0",
  "Cases": [ { "Id": "1", "CaseNumber": "2024-001234", "Title": "...", "Year": "2024", "CasetypeId": "2", "CasetypeName": "..." } ],
  "Documents": [ { "Id": "5", "Title": "...", "DocumentType": "brev", "CaseId": "1", "CaseNumber": "2024-001234", "CaseTitle": "..." } ],
  "Files": [ { "FileId": "9", "DocumentId": "5", "CaseId": "1", "CaseNumber": "2024-001234", "CaseTitle": "...", "Filename": "brev.pdf", "DocumentType": "brev", "MimeType": "application/pdf", "Organisation": "...", "Year": "2024", "Score": "3.21", "VirtualPath": "/Sager/.../brev.pdf" } ]
} }
```
`Files` is omitted (not an error) if Elasticsearch is unavailable —
`Cases`/`Documents` still come from the database.
