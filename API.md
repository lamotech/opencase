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
