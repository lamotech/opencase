<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller\PublicApi;

use OCA\OpenCase\Service\SearchService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Public data REST API for free-text search (mirrors the internal
 * /opencase/search page). Searches case/document metadata in the database
 * plus file content and metadata in Elasticsearch, filtered by the acting
 * user's access profiles.
 *
 * GET /index.php/apps/opencase/public/v1/api/search?q=...
 *   — free-text search. Query parameters:
 *       q             (required)  — search query text
 *       organisation  (optional)  — filter by organisation name
 *       year          (optional)  — filter by year
 *       document_type (optional)  — filter by document type
 *       mime_type     (optional)  — filter by file MIME type
 *       case_id       (optional)  — filter to a specific case
 *       date_from     (optional)  — ISO 8601 date string
 *       date_to       (optional)  — ISO 8601 date string
 *       limit         (optional, default 20, max 1000)
 *       offset        (optional, default 0)
 *     Response: SearchResult
 *       { Total, Limit, Offset,
 *         Cases: [{Id, CaseNumber, Title, Year, CasetypeId, CasetypeName}, ...],
 *         Documents: [{Id, Title, DocumentType, CaseId, CaseNumber, CaseTitle}, ...],
 *         Files: [{FileId, DocumentId, CaseId, CaseNumber, CaseTitle, Filename,
 *                  DocumentType, MimeType, Organisation, Year, Score, VirtualPath}, ...] }
 *     Files results are omitted (not an error) if Elasticsearch is
 *     unavailable — Cases/Documents results still come from the database.
 *
 * See AbstractPublicDataApiController for auth and response-format handling.
 */
class PublicSearchApiController extends AbstractPublicDataApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private SearchService $searchService,
        IUserSession $userSession,
    ) {
        parent::__construct($appName, $request, $userSession);
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function search(): Response {
        $userId = $this->actingUserId();
        if ($userId === null) {
            return $this->errorResponse('No acting user resolved', Http::STATUS_FORBIDDEN);
        }

        $query = (string)($this->request->getParam('q') ?? '');
        if (trim($query) === '') {
            return $this->errorResponse("Query parameter 'q' is required", Http::STATUS_BAD_REQUEST);
        }

        $filters = [];
        foreach (['organisation', 'year', 'document_type', 'mime_type', 'case_id', 'date_from', 'date_to'] as $key) {
            $value = $this->request->getParam($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

        $limit  = max(1, min((int)$this->request->getParam('limit', '20'), 1000));
        $offset = max(0, (int)$this->request->getParam('offset', '0'));

        $result = $this->searchService->search($userId, $query, $filters, $offset, $limit);

        $cases = array_map(static function (array $c): array {
            return [
                'Id'            => (string)$c['id'],
                'CaseNumber'    => $c['case_number'],
                'Title'         => $c['title'],
                'Year'          => $c['year'],
                'CasetypeId'    => $c['casetype_id'] !== null ? (string)$c['casetype_id'] : null,
                'CasetypeName'  => $c['casetype_name'],
            ];
        }, $result['cases']);

        $documents = array_map(static function (array $d): array {
            return [
                'Id'           => (string)$d['id'],
                'Title'        => $d['title'],
                'DocumentType' => $d['document_type'],
                'CaseId'       => (string)$d['case_id'],
                'CaseNumber'   => $d['case_number'],
                'CaseTitle'    => $d['case_title'],
            ];
        }, $result['documents']);

        $files = array_map(static function (array $f): array {
            return [
                'FileId'       => isset($f['file_id']) ? (string)$f['file_id'] : null,
                'DocumentId'   => isset($f['document_id']) ? (string)$f['document_id'] : null,
                'CaseId'       => isset($f['case_id']) ? (string)$f['case_id'] : null,
                'CaseNumber'   => $f['case_number'] ?? null,
                'CaseTitle'    => $f['case_title'] ?? null,
                'Filename'     => $f['filename'] ?? null,
                'DocumentType' => $f['document_type'] ?? null,
                'MimeType'     => $f['mime_type'] ?? null,
                'Organisation' => $f['organisation'] ?? null,
                'Year'         => $f['year'] ?? null,
                'Score'        => isset($f['_score']) ? (string)$f['_score'] : null,
                'VirtualPath'  => $f['virtual_path'] ?? null,
            ];
        }, $result['files']);

        $payload = [
            'Total'     => (string)$result['total'],
            'Limit'     => (string)$limit,
            'Offset'    => (string)$offset,
            'Cases'     => $cases,
            'Documents' => $documents,
            'Files'     => $files,
        ];

        return $this->respond('SearchResult', $payload);
    }
}
