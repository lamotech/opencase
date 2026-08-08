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
`casetype_id`, `limit` (default 50, max 1000), `offset`. Response:
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
Lowercase/snake_case field names are also accepted throughout. Response
(201): `{ "Case": CaseType }`.

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
| `GET /classification-facets` | `{ "ClassificationFacets": [{Uuid, Code, Title}, ...] }` — "handlingsfacetter" |
| `GET /sensitivities` | `{ "Sensitivities": [{Uuid, Key, Title}, ...] }` — `Key` is what `POST /cases` expects as `sensitivity` |
| `GET /users` | `{ "Users": [{Id, DisplayName}, ...] }` — Nextcloud users with an OpenCase role, sorted by display name |
| `GET /templates` | `{ "Templates": [{Id, Name, OriginalFilename, MimeType, Size, UploadedBy, CreatedAt}, ...] }` |

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
