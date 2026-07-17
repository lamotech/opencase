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

        $profileIds    = $this->permissionService->getAccessProfileIdsForUser($userId);
        $directCaseIds = $this->permissionService->getDirectAccessCaseIds($userId);
        if (empty($profileIds) && empty($directCaseIds)) {
            return ['cases' => [], 'documents' => [], 'files' => [], 'total' => 0, 'aggregations' => []];
        }

        // Search cases by title/number in DB
        $caseEntities = $this->caseMapper->searchByTitleOrNumber($query, $profileIds, $userId, 200);

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

        // Search all documents by title (unfiltered) to build aggregations
        $allDocumentRows = $this->documentMapper->searchByTitle($query, $profileIds, 200, 0, $directCaseIds);

        // Resolve org names for documents
        $docOrgUuids = array_unique(array_filter(array_map(fn($d) => $d['org_uuid'] ?? '', $allDocumentRows)));
        $docOrgNames = $this->organisationMapper->getNamesByUuids($docOrgUuids);

        // Build aggregations from all DB results (cases + documents combined)
        $docTypeCounts = [];
        $orgCounts     = [];
        $yearCounts    = [];

        foreach ($allCases as $c) {
            if ($c['org_name']) {
                $orgCounts[$c['org_name']] = ($orgCounts[$c['org_name']] ?? 0) + 1;
            }
            if ($c['year']) {
                $yearCounts[$c['year']] = ($yearCounts[$c['year']] ?? 0) + 1;
            }
        }
        foreach ($allDocumentRows as $d) {
            $type    = $d['document_type'] ?? '';
            $orgName = $docOrgNames[$d['org_uuid'] ?? ''] ?? '';
            $year    = $d['case_year'] ?? '';
            if ($type)    { $docTypeCounts[$type]    = ($docTypeCounts[$type]    ?? 0) + 1; }
            if ($orgName) { $orgCounts[$orgName]     = ($orgCounts[$orgName]     ?? 0) + 1; }
            if ($year)    { $yearCounts[$year]        = ($yearCounts[$year]       ?? 0) + 1; }
        }

        arsort($docTypeCounts);
        krsort($yearCounts);
        arsort($orgCounts);

        $buildBuckets = fn(array $counts) => array_values(array_map(
            fn($key, $count) => ['key' => $key, 'doc_count' => $count],
            array_keys($counts), array_values($counts)
        ));

        $dbAggregations = [
            'document_types' => ['buckets' => $buildBuckets($docTypeCounts)],
            'organisations'  => ['buckets' => $buildBuckets($orgCounts)],
            'years'          => ['buckets' => $buildBuckets($yearCounts)],
        ];

        // Apply filters to case results
        $filteredCases = array_filter($allCases, function ($c) use ($organisationFilter, $yearFilter) {
            if ($organisationFilter && ($c['org_name'] ?? '') !== $organisationFilter) {
                return false;
            }
            if ($yearFilter && ($c['year'] ?? '') !== $yearFilter) {
                return false;
            }
            return true;
        });
        $cases = array_values(array_slice($filteredCases, 0, 20));
        // Remove internal org_name from output
        $cases = array_map(function ($c) { unset($c['org_name']); return $c; }, $cases);

        // Apply filters to document results
        $filteredDocRows = array_filter($allDocumentRows, function ($d) use ($documentTypeFilter, $organisationFilter, $yearFilter, $docOrgNames) {
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

        // Merge aggregations: prefer ES buckets when available, fill gaps from DB
        $aggregations = $esResults['aggregations'];
        foreach (['document_types', 'organisations', 'years'] as $facet) {
            if (empty($aggregations[$facet]['buckets'] ?? [])) {
                $aggregations[$facet] = $dbAggregations[$facet];
            }
        }

        return [
            'cases'        => $cases,
            'documents'    => $documents,
            'files'        => $esResults['hits'],
            'total'        => $esResults['total'],
            'aggregations' => $aggregations,
        ];
    }
}
