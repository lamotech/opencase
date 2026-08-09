<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Service\SearchService;
use OCA\OpenCase\Service\TransactionLogService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * REST API controller for full-text search.
 *
 * Searches file content and metadata via Elasticsearch, automatically
 * filtered by the current user's access profiles.
 *
 * Routes:
 *   GET /api/v1/search — Search across all accessible files
 */
class SearchController extends ApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private SearchService $searchService,
        private TransactionLogService $transactionLogService,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Full-text search across all accessible files.
     *
     * Query parameters:
     *   q              — Required. Search query text
     *   organisation   — Optional filter by organisation
     *   case_type      — Optional filter by case type (casetype id)
     *   year           — Optional filter by year
     *   document_type  — Optional filter by document type
     *   mime_type      — Optional filter by MIME type
     *   case_id        — Optional filter to a specific case
     *   date_from      — Optional. ISO 8601 date string
     *   date_to        — Optional. ISO 8601 date string
     *   limit          — Max results (default 20, max 100)
     *   offset         — Pagination offset
     *
     * Response:
     *   total         — Total number of matching documents
     *   hits          — Array of search results with highlights
     *   aggregations  — Facet counts for organisations, case types, document types
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/search')]
    public function search(): DataResponse {
        $query = $this->request->getParam('q', '');
        if (trim($query) === '') {
            return new DataResponse([
                'error' => "Query parameter 'q' is required",
            ], 400);
        }

        $filters = [];
        $filterKeys = ['organisation', 'case_type', 'year', 'document_type', 'mime_type', 'case_id', 'date_from', 'date_to'];
        foreach ($filterKeys as $key) {
            $value = $this->request->getParam($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

        $limit = min((int)($this->request->getParam('limit', '20')), 1000);
        $offset = max((int)($this->request->getParam('offset', '0')), 0);

        try {
            $results = $this->searchService->search(
                $this->userId,
                $query,
                $filters,
                $offset,
                $limit,
            );

            if ($this->userId !== null) {
                $this->transactionLogService->log($this->userId, 'search_freetext', array_merge(['q' => $query], $filters));
            }

            return new DataResponse([
                'query'        => $query,
                'filters'      => $filters,
                'cases'        => $results['cases'],
                'documents'    => $results['documents'],
                'files'        => $results['files'],
                'total'        => $results['total'],
                'aggregations' => $results['aggregations'],
                'limit'        => $limit,
                'offset'       => $offset,
            ]);
        } catch (\Throwable $e) {
            return new DataResponse([
                'error' => 'Search failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
