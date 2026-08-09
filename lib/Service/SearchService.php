<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\CaseTypeMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\OrganisationMapper;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Search service bridging the permission model and Elasticsearch.
 *
 * Resolves the user's access profiles, passes them as a filter
 * to ES, and enriches results with virtual path information.
 */
class SearchService {

    public function __construct(
        private ElasticsearchService $esService,
        private PermissionService $permissionService,
        private CaseMapper $caseMapper,
        private CaseTypeMapper $caseTypeMapper,
        private DocumentMapper $documentMapper,
        private OrganisationMapper $organisationMapper,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Return the user's preferred Nextcloud language code (e.g. 'da', 'en'),
     * normalised to a plain 2-char code to match the casetype table.
     */
    private function userLanguage(string $userId): string {
        $lang = $this->config->getUserValue($userId, 'core', 'lang', 'en');
        return substr($lang, 0, 2) ?: 'en';
    }

    /**
     * Perform a full-text search scoped to the user's permissions.
     *
     * Returns cases and documents from the database (by title/number)
     * and files from Elasticsearch (by content and metadata).
     *
     * @return array{cases: array, documents: array, files: array, total: int, aggregations: array}
     */
    public function search(
        string $userId,
        string $query,
        array $filters = [],
        int $offset = 0,
        int $limit = 20,
    ): array {
        if (trim($query) === '') {
            return ['cases' => [], 'documents' => [], 'files' => [], 'total' => 0, 'aggregations' => []];
        }

        // Cheap gate: without it, a user with no access at all would make the
        // optimiser scan opencase_documents looking for rows that can never match.
        if (!$this->permissionService->userHasAnyAccess($userId)) {
            return ['cases' => [], 'documents' => [], 'files' => [], 'total' => 0, 'aggregations' => []];
        }

        // Search cases by title/number in DB
        $caseEntities = $this->caseMapper->searchByTitleOrNumber($query, $userId, 200);

        // Resolve org names for cases
        $caseOrgUuids = array_unique(array_filter(array_map(fn($c) => $c->getOrgUuid(), $caseEntities)));
        $orgNames = $this->organisationMapper->getNamesByUuids($caseOrgUuids);

        // Resolve case type names for cases
        $caseTypeIds   = array_unique(array_filter(array_map(fn($c) => $c->getCasetypeId(), $caseEntities)));
        $caseTypeNames = $this->caseTypeMapper->getNamesByIds($caseTypeIds, $this->userLanguage($userId));

        $allCases = array_map(fn($c) => [
            'id'             => $c->getId(),
            'case_number'    => $c->getCaseNumber(),
            'title'          => $c->getTitle(),
            'year'           => $c->getYear(),
            'org_name'       => $orgNames[$c->getOrgUuid()] ?? '',
            'casetype_id'    => $c->getCasetypeId(),
            'casetype_name'  => $caseTypeNames[$c->getCasetypeId()] ?? '',
        ], $caseEntities);

        $documentTypeFilter  = $filters['document_type'] ?? null;
        $organisationFilter  = $filters['organisation'] ?? null;
        $yearFilter          = $filters['year'] ?? null;
        // Case type is filtered by id — names are localised, ids are not.
        $caseTypeFilter      = isset($filters['case_type']) && $filters['case_type'] !== ''
            ? (int)$filters['case_type']
            : null;

        // Search all documents by title (unfiltered) to build aggregations
        $allDocumentRows = $this->documentMapper->searchByTitle($query, $userId, 200, 0);

        // Resolve org names for documents
        $docOrgUuids = array_unique(array_filter(array_map(fn($d) => $d['org_uuid'] ?? '', $allDocumentRows)));
        $docOrgNames = $this->organisationMapper->getNamesByUuids($docOrgUuids);

        // Case type names for the documents' cases — merged with the ones already
        // resolved for the case hits so every facet bucket can carry a label.
        $docCaseTypeIds   = array_unique(array_filter(array_map(fn($d) => (int)($d['casetype_id'] ?? 0), $allDocumentRows)));
        $caseTypeNames   += $this->caseTypeMapper->getNamesByIds($docCaseTypeIds, $this->userLanguage($userId));

        // Build aggregations from all DB results (cases + documents combined).
        // The facets exposed on the search page are organisation, case type and
        // document type; case type is counted by id and labelled on output.
        $docTypeCounts  = [];
        $orgCounts      = [];
        $caseTypeCounts = [];

        foreach ($allCases as $c) {
            if ($c['org_name']) {
                $orgCounts[$c['org_name']] = ($orgCounts[$c['org_name']] ?? 0) + 1;
            }
            if ($c['casetype_id']) {
                $caseTypeCounts[$c['casetype_id']] = ($caseTypeCounts[$c['casetype_id']] ?? 0) + 1;
            }
        }
        foreach ($allDocumentRows as $d) {
            $type     = $d['document_type'] ?? '';
            $orgName  = $docOrgNames[$d['org_uuid'] ?? ''] ?? '';
            $caseType = (int)($d['casetype_id'] ?? 0);
            if ($type)     { $docTypeCounts[$type]           = ($docTypeCounts[$type]           ?? 0) + 1; }
            if ($orgName)  { $orgCounts[$orgName]            = ($orgCounts[$orgName]            ?? 0) + 1; }
            if ($caseType) { $caseTypeCounts[$caseType]      = ($caseTypeCounts[$caseType]      ?? 0) + 1; }
        }

        arsort($docTypeCounts);
        arsort($orgCounts);
        arsort($caseTypeCounts);

        $buildBuckets = fn(array $counts) => array_values(array_map(
            fn($key, $count) => ['key' => $key, 'doc_count' => $count],
            array_keys($counts), array_values($counts)
        ));

        // Case-type buckets keep the id as the filter value and add a display
        // label, so the UI never has to map ids back to (localised) names.
        $caseTypeBuckets = [];
        foreach ($caseTypeCounts as $id => $count) {
            $caseTypeBuckets[] = [
                'key'       => (string)$id,
                'label'     => $caseTypeNames[$id] ?? (string)$id,
                'doc_count' => $count,
            ];
        }

        $dbAggregations = [
            'document_types' => ['buckets' => $buildBuckets($docTypeCounts)],
            'organisations'  => ['buckets' => $buildBuckets($orgCounts)],
            'case_types'     => ['buckets' => $caseTypeBuckets],
        ];

        // Apply filters to case results
        $filteredCases = array_filter($allCases, function ($c) use ($organisationFilter, $yearFilter, $caseTypeFilter) {
            if ($organisationFilter && ($c['org_name'] ?? '') !== $organisationFilter) {
                return false;
            }
            if ($yearFilter && ($c['year'] ?? '') !== $yearFilter) {
                return false;
            }
            if ($caseTypeFilter !== null && (int)($c['casetype_id'] ?? 0) !== $caseTypeFilter) {
                return false;
            }
            return true;
        });
        $cases = array_values(array_slice($filteredCases, 0, 20));
        // Remove internal org_name from output
        $cases = array_map(function ($c) { unset($c['org_name']); return $c; }, $cases);

        // Apply filters to document results
        $filteredDocRows = array_filter($allDocumentRows, function ($d) use ($documentTypeFilter, $organisationFilter, $yearFilter, $caseTypeFilter, $docOrgNames) {
            if ($documentTypeFilter && ($d['document_type'] ?? '') !== $documentTypeFilter) {
                return false;
            }
            if ($organisationFilter) {
                $orgName = $docOrgNames[$d['org_uuid'] ?? ''] ?? '';
                if ($orgName !== $organisationFilter) {
                    return false;
                }
            }
            if ($yearFilter && ($d['case_year'] ?? '') !== $yearFilter) {
                return false;
            }
            if ($caseTypeFilter !== null && (int)($d['casetype_id'] ?? 0) !== $caseTypeFilter) {
                return false;
            }
            return true;
        });

        $documents = array_values(array_map(fn($d) => [
            'id'            => (int)$d['id'],
            'title'         => $d['title'],
            'document_type' => $d['document_type'] ?? '',
            'case_id'       => (int)$d['case_id'],
            'case_number'   => $d['case_number'] ?? '',
            'case_title'    => $d['case_title'] ?? '',
        ], array_slice($filteredDocRows, 0, 20)));

        // Search files in Elasticsearch (graceful fallback when ES is unavailable)
        $esResults = ['hits' => [], 'total' => 0, 'aggregations' => []];
        try {
            $profileIds    = $this->permissionService->getAccessProfileIdsForUser($userId);
            $directCaseIds = $this->permissionService->getDirectAccessCaseIds($userId);
            $esResults = $this->esService->search($query, $profileIds, $filters, $offset, $limit, $directCaseIds);
        } catch (\Throwable $e) {
            $this->logger->warning('OpenCase ES search unavailable, returning DB results only: ' . $e->getMessage());
        }

        foreach ($esResults['hits'] as &$hit) {
            $hit['virtual_path'] = sprintf(
                'Sager/%s/%s/%s - %s/%s',
                $hit['organisation'] ?? '',
                $hit['year'] ?? '',
                $hit['case_number'] ?? '',
                $hit['case_title'] ?? '',
                $hit['filename'] ?? ''
            );
        }

        // Merge aggregations: prefer ES buckets when available, fill gaps from DB.
        // Only the three facets the search page offers are returned; case_types
        // always comes from the DB because those buckets carry display labels
        // that ES (which indexes the id alone) cannot provide.
        $aggregations = [];
        foreach (['document_types', 'organisations'] as $facet) {
            $aggregations[$facet] = empty($esResults['aggregations'][$facet]['buckets'] ?? [])
                ? $dbAggregations[$facet]
                : $esResults['aggregations'][$facet];
        }
        $aggregations['case_types'] = $dbAggregations['case_types'];

        return [
            'cases'        => $cases,
            'documents'    => $documents,
            'files'        => $esResults['hits'],
            'total'        => $esResults['total'],
            'aggregations' => $aggregations,
        ];
    }
}
