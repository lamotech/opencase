<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\AccessRequestItemMapper;
use OCA\OpenCase\Db\AccessRequestItem;
use OCA\OpenCase\Db\DocumentMapper;

/**
 * Finds candidate documents for an access request.
 *
 * Uses the existing ElasticsearchService for full-text / metadata search,
 * then cross-references with the DB to get document metadata.
 */
class AccessSearchService {

    public function __construct(
        private ElasticsearchService $es,
        private DocumentMapper $documentMapper,
        private AccessRequestItemMapper $itemMapper,
    ) {
    }

    /**
     * Search for candidate documents within a case.
     *
     * Returns an array of candidate items ready to be shown in the
     * document table. Each entry includes whether it is already added
     * to the request.
     *
     * @param array{
     *   q?: string,
     *   date_from?: string,
     *   date_to?: string,
     *   document_type?: string,
     * } $filters
     * @return array<int, array>
     */
    public function searchCandidates(int $caseId, int $requestId, array $filters = []): array {
        $query = $filters['q'] ?? '';
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo   = $filters['date_to'] ?? null;
        $docType  = $filters['document_type'] ?? null;

        // Try Elasticsearch first; fall back to DB-only if ES is unavailable
        $fileHits = [];
        try {
            $esFilters = [];
            if ($dateFrom)  { $esFilters['date_from']     = $dateFrom; }
            if ($dateTo)    { $esFilters['date_to']       = $dateTo; }
            if ($docType)   { $esFilters['document_type'] = $docType; }

            $result   = $this->es->search($query, [], $esFilters, 0, 200, [$caseId]);
            $fileHits = $result['hits'] ?? [];
        } catch (\Throwable) {
            // ES unavailable — DB fallback below
        }

        // Build a map of already-added source IDs for this request
        $existingItems = $this->itemMapper->findByRequest($requestId);
        $addedMap = [];
        foreach ($existingItems as $item) {
            $addedMap[$item->getSourceType() . ':' . $item->getSourceId()] = $item;
        }

        // If ES returned results, use those doc IDs; otherwise fetch all docs in case
        $documentIds = [];
        if (!empty($fileHits)) {
            foreach ($fileHits as $hit) {
                if (!empty($hit['document_id'])) {
                    $documentIds[(int)$hit['document_id']] = true;
                }
            }
            $documentIds = array_keys($documentIds);
        } else {
            $docs = $this->documentMapper->findByCase($caseId);
            foreach ($docs as $doc) {
                $documentIds[] = $doc->getId();
            }
        }

        $candidates = [];
        foreach ($documentIds as $docId) {
            try {
                $doc  = $this->documentMapper->findById($docId);
                $key  = 'document:' . $docId;
                $item = $addedMap[$key] ?? null;

                $candidates[] = [
                    'source_type'     => 'document',
                    'source_id'       => $docId,
                    'title'           => $doc->getTitle(),
                    'document_type'   => $doc->getDocumentType(),
                    'document_date'   => $doc->getDocumentDate(),
                    'mime_type'       => null,
                    'already_added'   => $item !== null,
                    'item_id'         => $item?->getId(),
                    'decision'        => $item?->getDecision() ?? 'pending',
                ];
            } catch (\Throwable) {
                // Document may have been deleted
            }
        }

        return $candidates;
    }
}
