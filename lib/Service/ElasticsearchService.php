<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Service for communicating with Elasticsearch.
 *
 * Handles:
 *   - Index creation and mapping setup
 *   - Document indexing (with ingest-attachment pipeline)
 *   - Permission-filtered search
 *   - Index management (reindex, delete, etc.)
 *
 * Configuration (app config keys):
 *   es_host         — Elasticsearch URL (default: http://localhost:9200)
 *   es_index        — Index name (default: opencase_files)
 *   es_pipeline     — Ingest pipeline name (default: opencase_attachment)
 *   es_max_file_size — Max file size to index content (default: 50MB)
 *
 * Requires the ingest-attachment plugin on the Elasticsearch cluster.
 */
class ElasticsearchService {

    private const DEFAULT_HOST = 'http://localhost:9200';
    private const DEFAULT_INDEX = 'opencase_files';
    private const DEFAULT_PIPELINE = 'opencase_attachment';

    private string $host;
    private string $index;
    private string $pipeline;

    public function __construct(
        private IClientService $httpClientService,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
        $this->host = $config->getAppValue('opencase', 'es_host', self::DEFAULT_HOST);
        $this->index = $config->getAppValue('opencase', 'es_index', self::DEFAULT_INDEX);
        $this->pipeline = $config->getAppValue('opencase', 'es_pipeline', self::DEFAULT_PIPELINE);
    }

    // ---------------------------------------------------------------
    // Index setup
    // ---------------------------------------------------------------

    /**
     * Create the ES index with appropriate mappings and the ingest pipeline.
     * Idempotent — safe to call multiple times.
     */
    public function setupIndex(): void {
        $this->createPipeline();
        $this->createIndex();
    }

    /**
     * Create the ingest-attachment pipeline.
     * This pipeline uses Tika to extract text from binary files.
     */
    private function createPipeline(): void {
        $pipelineBody = [
            'description' => 'OpenCase: Extract text from file attachments',
            'processors' => [
                [
                    'attachment' => [
                        'field' => 'data',
                        'target_field' => 'attachment',
                        'indexed_chars' => -1, // Index all content
                        'ignore_missing' => true,
                    ],
                ],
                [
                    'remove' => [
                        'field' => 'data',
                        'ignore_missing' => true,
                    ],
                ],
            ],
        ];

        $this->esRequest('PUT', "/_ingest/pipeline/{$this->pipeline}", $pipelineBody);
    }

    /**
     * Create the index with field mappings optimised for the OpenCase query patterns.
     */
    private function createIndex(): void {
        // Check if index already exists
        try {
            $this->esRequest('HEAD', "/{$this->index}");
            return; // Already exists
        } catch (\Exception $e) {
            // Index doesn't exist, create it
        }

        $indexBody = [
            'settings' => [
                'number_of_shards' => 3,   // Tune based on cluster size
                'number_of_replicas' => 1,
                'analysis' => [
                    'analyzer' => [
                        'danish_content' => [
                            'type' => 'custom',
                            'tokenizer' => 'standard',
                            'filter' => ['lowercase', 'danish_stemmer', 'danish_stop'],
                        ],
                    ],
                    'filter' => [
                        'danish_stemmer' => [
                            'type' => 'stemmer',
                            'language' => 'danish',
                        ],
                        'danish_stop' => [
                            'type' => 'stop',
                            'stopwords' => '_danish_',
                        ],
                    ],
                ],
            ],
            'mappings' => [
                'properties' => [
                    // Identifiers
                    'file_id' => ['type' => 'integer'],
                    'document_id' => ['type' => 'integer'],
                    'case_id' => ['type' => 'integer'],

                    // Searchable text fields (Danish-analysed)
                    'case_number' => ['type' => 'keyword'], // Exact match
                    'case_title' => [
                        'type' => 'text',
                        'analyzer' => 'danish_content',
                        'fields' => ['keyword' => ['type' => 'keyword']],
                    ],
                    'document_title' => [
                        'type' => 'text',
                        'analyzer' => 'danish_content',
                        'fields' => ['keyword' => ['type' => 'keyword']],
                    ],
                    'document_type' => ['type' => 'keyword'],
                    'filename' => [
                        'type' => 'text',
                        'analyzer' => 'danish_content',
                        'fields' => ['keyword' => ['type' => 'keyword']],
                    ],
                    'original_filename' => ['type' => 'text'],
                    'mime_type' => ['type' => 'keyword'],

                    // Extracted content (from ingest-attachment pipeline)
                    'attachment' => [
                        'properties' => [
                            'content' => [
                                'type' => 'text',
                                'analyzer' => 'danish_content',
                            ],
                            'content_type' => ['type' => 'keyword'],
                            'language' => ['type' => 'keyword'],
                            'title' => ['type' => 'text', 'analyzer' => 'danish_content'],
                            'author' => ['type' => 'text'],
                            'date' => ['type' => 'date'],
                            'content_length' => ['type' => 'integer'],
                        ],
                    ],

                    // Permission filtering (critical for search-time ACL)
                    'access_profile_id' => ['type' => 'integer'],

                    // Faceting and filtering
                    'organisation' => ['type' => 'keyword'],
                    'year' => ['type' => 'keyword'],
                    'size' => ['type' => 'long'],

                    // Dates
                    'created_at' => ['type' => 'date'],
                    'updated_at' => ['type' => 'date'],

                    // Virtual path in the opencase storage (year/month/day/case/file).
                    // Used by the global search provider to resolve the NC filecache ID.
                    'virtual_path' => ['type' => 'keyword'],
                ],
            ],
        ];

        $this->esRequest('PUT', "/{$this->index}", $indexBody);
    }

    // ---------------------------------------------------------------
    // Document indexing
    // ---------------------------------------------------------------

    /**
     * Index a document with file content (via the attachment pipeline).
     *
     * @param array $document Must include 'data' field with base64-encoded content
     */
    public function indexDocument(int $fileId, array $document): void {
        $this->esRequest(
            'PUT',
            "/{$this->index}/_doc/{$fileId}?pipeline={$this->pipeline}",
            $document
        );
    }

    /**
     * Store a pre-built document directly (no attachment pipeline).
     *
     * Used by IndexFileJob for files that exceed the size limit — the caller
     * has already assembled the full document; we just persist it as-is.
     */
    public function storeDocument(int $fileId, array $document): void {
        $this->esRequest('PUT', "/{$this->index}/_doc/{$fileId}", $document);
    }

    /**
     * Index only the metadata (no file content).
     * Used for files exceeding the size limit.
     */
    public function indexMetadataOnly(int $fileId, array $meta): void {
        $esDocument = [
            'file_id' => $fileId,
            'document_id' => (int)$meta['document_id'],
            'case_id' => (int)$meta['case_id'],
            'case_number' => $meta['case_number'],
            'case_title' => $meta['case_title'],
            'document_title' => $meta['document_title'],
            'document_type' => $meta['document_type'],
            'filename' => $meta['virtual_filename'],
            'original_filename' => $meta['original_filename'],
            'mime_type' => $meta['mime_type'],
            'size' => (int)$meta['size'],
            'organisation' => $meta['organisation'],
            'year' => $meta['year'],
            'access_profile_id' => (int)$meta['access_profile_id'],
            'created_at' => $this->toIso8601($meta['file_created_at']),
            'updated_at' => $this->toIso8601($meta['file_updated_at']),
        ];

        // Index directly without the attachment pipeline
        $this->esRequest('PUT', "/{$this->index}/_doc/{$fileId}", $esDocument);
    }

    /**
     * Delete a document from the index.
     */
    public function deleteDocument(int $fileId): void {
        try {
            $this->esRequest('DELETE', "/{$this->index}/_doc/{$fileId}");
        } catch (\Exception $e) {
            // Ignore 404 — file may not have been indexed yet
            $this->logger->debug('OpenCase ES: Delete for file {fileId} failed (may not exist): {error}', [
                'fileId' => $fileId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ---------------------------------------------------------------
    // Search
    // ---------------------------------------------------------------

    /**
     * Search for files with permission filtering.
     *
     * The caller provides the user's access profile IDs, which are
     * used as a terms filter on the access_profile_id field. This
     * ensures users only see results they have access to.
     *
     * @param string $query Search query text
     * @param int[] $accessProfileIds User's accessible profile IDs
     * @param array $filters Optional additional filters (org, year, document_type, etc.)
     * @param int $offset Pagination offset
     * @param int $limit Max results
     * @return array{total: int, hits: array}
     */
    public function search(
        string $query,
        array $accessProfileIds,
        array $filters = [],
        int $offset = 0,
        int $limit = 20,
        array $directAccessCaseIds = [],
    ): array {
        if (empty($accessProfileIds) && empty($directAccessCaseIds)) {
            return ['total' => 0, 'hits' => []];
        }

        // Build the access filter: profile-based OR direct-access case IDs
        $accessShould = [];
        if (!empty($accessProfileIds)) {
            $accessShould[] = ['terms' => ['access_profile_id' => $accessProfileIds]];
        }
        if (!empty($directAccessCaseIds)) {
            $accessShould[] = ['terms' => ['case_id' => $directAccessCaseIds]];
        }
        $accessFilter = count($accessShould) === 1
            ? $accessShould[0]
            : ['bool' => ['should' => $accessShould, 'minimum_should_match' => 1]];

        // Build the filter clauses
        $filterClauses = [$accessFilter];

        // Optional facet filters
        if (!empty($filters['organisation'])) {
            $filterClauses[] = ['term' => ['organisation' => $filters['organisation']]];
        }
        if (!empty($filters['year'])) {
            $filterClauses[] = ['term' => ['year' => $filters['year']]];
        }
        if (!empty($filters['document_type'])) {
            $filterClauses[] = ['term' => ['document_type' => $filters['document_type']]];
        }
        if (!empty($filters['mime_type'])) {
            $filterClauses[] = ['term' => ['mime_type' => $filters['mime_type']]];
        }
        if (!empty($filters['case_id'])) {
            $filterClauses[] = ['term' => ['case_id' => $filters['case_id']]];
        }

        // Date range filter
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $dateRange = [];
            if (!empty($filters['date_from'])) {
                $dateRange['gte'] = $filters['date_from'];
            }
            if (!empty($filters['date_to'])) {
                $dateRange['lte'] = $filters['date_to'];
            }
            $filterClauses[] = ['range' => ['updated_at' => $dateRange]];
        }

        // Build the query
        $esQuery = [
            'from' => $offset,
            'size' => $limit,
            'query' => [
                'bool' => [
                    'must' => [
                        'multi_match' => [
                            'query' => $query,
                            'fields' => [
                                'attachment.content',   // Extracted file content
                                'case_title^2',         // Boost case title matches
                                'document_title^2',     // Boost document title matches
                                'filename^1.5',
                                'case_number^3',        // Strong boost for case number matches
                            ],
                            'type' => 'best_fields',
                            'fuzziness' => 'AUTO',
                        ],
                    ],
                    'filter' => $filterClauses,
                ],
            ],
            'highlight' => [
                'fields' => [
                    'attachment.content' => [
                        'fragment_size' => 200,
                        'number_of_fragments' => 3,
                    ],
                    'case_title' => new \stdClass(),
                    'document_title' => new \stdClass(),
                ],
                'pre_tags' => ['<mark>'],
                'post_tags' => ['</mark>'],
            ],
            // Aggregations for faceted search UI
            // Use .keyword sub-field for text fields that lack fielddata
            'aggs' => [
                'organisations' => ['terms' => ['field' => 'organisation.keyword', 'size' => 50]],
                'years' => ['terms' => ['field' => 'year.keyword', 'size' => 20, 'order' => ['_key' => 'desc']]],
                'document_types' => ['terms' => ['field' => 'document_type.keyword', 'size' => 20]],
            ],
            '_source' => [
                'excludes' => ['attachment.content'], // Don't return full content in results
            ],
        ];

        $response = $this->esRequest('POST', "/{$this->index}/_search", $esQuery);

        // Parse response
        $hits = [];
        foreach (($response['hits']['hits'] ?? []) as $hit) {
            $source = $hit['_source'];
            $source['_score'] = $hit['_score'];
            $source['_highlights'] = $hit['highlight'] ?? [];
            $hits[] = $source;
        }

        return [
            'total' => $response['hits']['total']['value'] ?? 0,
            'hits' => $hits,
            'aggregations' => $response['aggregations'] ?? [],
        ];
    }

    // ---------------------------------------------------------------
    // HTTP helper
    // ---------------------------------------------------------------

    /**
     * Make an HTTP request to Elasticsearch.
     *
     * @return array Decoded JSON response
     * @throws \Exception on HTTP errors
     */
    private function toIso8601(?string $datetime): ?string {
        if ($datetime === null || $datetime === '') {
            return null;
        }
        return (new \DateTime($datetime))->format(\DateTime::ATOM);
    }

    private function esRequest(string $method, string $path, ?array $body = null): array {
        $client = $this->httpClientService->newClient();
        $url = rtrim($this->host, '/') . $path;

        $options = [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ];

        if ($body !== null) {
            $options['body'] = json_encode($body);
        }

        try {
            $response = match (strtoupper($method)) {
                'GET' => $client->get($url, $options),
                'POST' => $client->post($url, $options),
                'PUT' => $client->put($url, $options),
                'DELETE' => $client->delete($url, $options),
                'HEAD' => $client->head($url, $options),
                default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
            };

            $responseBody = $response->getBody();
            if (empty($responseBody)) {
                return [];
            }

            return json_decode($responseBody, true) ?? [];
        } catch (\Exception $e) {
            $this->logger->error('OpenCase ES request failed: {method} {path}: {error}', [
                'method' => $method,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
