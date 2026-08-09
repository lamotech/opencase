<?php

declare(strict_types=1);

namespace OCA\OpenCase\Search;

use OCA\OpenCase\Service\ElasticsearchService;
use OCA\OpenCase\Service\PermissionService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IMimeTypeDetector;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IPreview;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Global search provider for OpenCase files.
 *
 * Primary path — Elasticsearch:
 *   Searches file content, filename, case title, and document title via
 *   ElasticsearchService directly (bypassing SearchService to avoid the
 *   virtual_path overwrite). Results include hits where the search term
 *   appears *inside* the document body (Word, PDF, …), not just in the
 *   filename.
 *
 * Fallback path — DB LIKE:
 *   If ES is unavailable or throws, falls back to a simple
 *   virtual_filename LIKE %term% query so search still works.
 *
 * Both paths show "OpenCase sagsnummer XXXX" as the result subline instead
 * of the raw virtual path produced by NC's core FilesSearchProvider.
 * The core provider is excluded from returning OpenCase files by
 * OpenCaseCacheWrapper::getQueryFilterForStorage() returning storage = -1.
 */
class OpenCaseFilesSearchProvider implements IProvider {

    public function __construct(
        private IL10N $l10n,
        private IURLGenerator $urlGenerator,
        private IDBConnection $db,
        private IMimeTypeDetector $mimeTypeDetector,
        private IPreview $previewManager,
        private IConfig $config,
        private ElasticsearchService $esService,
        private PermissionService $permissionService,
    ) {
    }

    public function getId(): string {
        return 'opencase_files';
    }

    public function getName(): string {
        return $this->l10n->t('Filer');
    }

    /**
     * Order 4 places this section just before the core Files provider (order 5).
     */
    public function getOrder(string $route, array $routeParameters): int {
        return 4;
    }

    public function search(IUser $user, ISearchQuery $query): SearchResult {
        $term = trim($query->getTerm());
        if ($term === '') {
            return SearchResult::paginated(
                $this->getName(),
                [],
                $query->getCursor() + $query->getLimit()
            );
        }

        $limit  = $query->getLimit();
        $offset = (int)$query->getCursor();

        // Primary: Elasticsearch (searches file content + metadata)
        try {
            $entries = $this->searchViaEs($user->getUID(), $term, $limit, $offset);
            return SearchResult::paginated(
                $this->getName(),
                $entries,
                $offset + $limit
            );
        } catch (\Throwable) {
            // ES unavailable — fall through to DB filename search
        }

        // Fallback: DB LIKE on virtual_filename
        $entries = $this->searchViaDb($user->getUID(), $term, $limit, $offset);
        return SearchResult::paginated(
            $this->getName(),
            $entries,
            $offset + $limit
        );
    }

    // ---------------------------------------------------------------
    // Primary path: Elasticsearch
    // ---------------------------------------------------------------

    /** @return SearchResultEntry[] */
    private function searchViaEs(string $userId, string $term, int $limit, int $offset): array {
        $profileIds     = $this->permissionService->getAccessProfileIdsForUser($userId);
        $directCaseIds  = $this->permissionService->getDirectAccessCaseIds($userId);
        if (empty($profileIds) && empty($directCaseIds)) {
            return [];
        }

        $results = $this->esService->search($term, $profileIds, [], $offset, $limit, $directCaseIds);

        $hits = $results['hits'] ?? [];
        if (empty($hits)) {
            return [];
        }

        // Batch-resolve NC filecache IDs from virtual_path fields
        $fileIdMap = $this->resolveFilecacheIds(
            array_column($hits, 'virtual_path')
        );

        $mountName = $this->config->getAppValue('opencase', 'mount_name', '.Sager');
        $entries   = [];

        foreach ($hits as $hit) {
            $caseNumber  = $hit['case_number'] ?? '';
            $filename    = $hit['filename']    ?? '';
            $mimeType    = $hit['mime_type']   ?? 'application/octet-stream';
            $virtualPath = $hit['virtual_path'] ?? '';
            $ncFileId    = $fileIdMap[$virtualPath] ?? 0;

            [$thumbnailUrl, $link] = $this->buildLink(
                $ncFileId, $mimeType, $mountName, $virtualPath
            );

            $entry = new SearchResultEntry(
                $thumbnailUrl,
                $filename,
                'OpenCase sagsnummer ' . $caseNumber,
                $link,
                $this->mimeTypeDetector->mimeTypeIcon($mimeType),
            );
            if ($ncFileId > 0) {
                $entry->addAttribute('fileId', (string)$ncFileId);
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    // ---------------------------------------------------------------
    // Fallback path: DB LIKE on virtual_filename
    // ---------------------------------------------------------------

    /** @return SearchResultEntry[] */
    private function searchViaDb(string $userId, string $term, int $limit, int $offset): array {
        $storageNumericId = $this->getStorageNumericId();

        $qb = $this->db->getQueryBuilder();

        $yearFn  = $qb->createFunction("DATE_FORMAT(c.created_at, '%Y')");
        $monthFn = $qb->createFunction("DATE_FORMAT(c.created_at, '%m')");
        $dayFn   = $qb->createFunction("DATE_FORMAT(c.created_at, '%d')");

        $qb->select('f.id', 'f.virtual_filename', 'f.mime_type', 'c.case_number')
            ->selectAlias($yearFn,  'yr')
            ->selectAlias($monthFn, 'mo')
            ->selectAlias($dayFn,   'dy')
            ->from('opencase_files', 'f')
            ->innerJoin('f', 'opencase_cases', 'c',
                $qb->expr()->eq('c.id', 'f.case_id'))
            ->innerJoin('c', 'opencase_user_access', 'ua',
                $qb->expr()->eq('ua.access_profile_id', 'c.access_profile_id'))
            ->leftJoin('f', 'opencase_doc_user_access', 'dua',
                $qb->expr()->andX(
                    $qb->expr()->eq('dua.document_id', 'f.document_id'),
                    $qb->expr()->eq('dua.user_id', $qb->createNamedParameter($userId))
                ))
            ->where($qb->expr()->eq('ua.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->iLike(
                'f.virtual_filename',
                $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($term) . '%')
            ))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('dua.access_level'),
                $qb->expr()->neq('dua.access_level', $qb->createNamedParameter('deny'))
            ))
            ->groupBy('f.id', 'f.virtual_filename', 'f.mime_type', 'c.case_number')
            ->addGroupBy($yearFn)
            ->addGroupBy($monthFn)
            ->addGroupBy($dayFn)
            ->orderBy('f.virtual_filename', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($storageNumericId !== null) {
            $pathFn = $qb->createFunction(
                "CONCAT(DATE_FORMAT(c.created_at, '%Y/%m/%d'), '/', c.case_number, '/', f.virtual_filename)"
            );
            $qb->selectAlias($qb->func()->max('fc.fileid'), 'nc_file_id')
                ->leftJoin('f', 'filecache', 'fc',
                    $qb->expr()->andX(
                        $qb->expr()->eq('fc.storage',
                            $qb->createNamedParameter($storageNumericId, IQueryBuilder::PARAM_INT)),
                        $qb->expr()->eq('fc.path', $pathFn)
                    ));
        }

        $result    = $qb->executeQuery();
        $entries   = [];
        $mountName = $this->config->getAppValue('opencase', 'mount_name', '.Sager');

        while ($row = $result->fetch()) {
            $caseNumber  = $row['case_number'];
            $mimeType    = $row['mime_type'] ?: 'application/octet-stream';
            $ncFileId    = isset($row['nc_file_id']) ? (int)$row['nc_file_id'] : 0;
            $virtualPath = $row['yr'] . '/' . $row['mo'] . '/' . $row['dy']
                . '/' . $caseNumber . '/' . $row['virtual_filename'];

            [$thumbnailUrl, $link] = $this->buildLink(
                $ncFileId, $mimeType, $mountName, $virtualPath
            );

            $entry = new SearchResultEntry(
                $thumbnailUrl,
                $row['virtual_filename'],
                'OpenCase sagsnummer ' . $caseNumber,
                $link,
                $this->mimeTypeDetector->mimeTypeIcon($mimeType),
            );
            if ($ncFileId > 0) {
                $entry->addAttribute('fileId', (string)$ncFileId);
            }
            $entries[] = $entry;
        }
        $result->closeCursor();

        return $entries;
    }

    // ---------------------------------------------------------------
    // Shared helpers
    // ---------------------------------------------------------------

    /**
     * Batch-resolve NC filecache IDs for a list of virtual paths.
     *
     * @param string[] $virtualPaths  paths relative to the opencase storage root
     * @return array<string, int>     virtualPath → NC filecache fileid
     */
    private function resolveFilecacheIds(array $virtualPaths): array {
        $virtualPaths = array_filter($virtualPaths);
        if (empty($virtualPaths)) {
            return [];
        }

        $storageNumericId = $this->getStorageNumericId();
        if ($storageNumericId === null) {
            return [];
        }

        $hashes  = array_map('md5', $virtualPaths);
        $hashMap = array_combine($hashes, $virtualPaths); // md5 → virtualPath

        $qb = $this->db->getQueryBuilder();
        $qb->select('fileid', 'path_hash')
            ->from('filecache')
            ->where($qb->expr()->eq(
                'storage',
                $qb->createNamedParameter($storageNumericId, IQueryBuilder::PARAM_INT)
            ))
            ->andWhere($qb->expr()->in(
                'path_hash',
                $qb->createNamedParameter(array_values($hashes), IQueryBuilder::PARAM_STR_ARRAY)
            ));

        $result  = $qb->executeQuery();
        $fileIds = [];
        while ($row = $result->fetch()) {
            $path = $hashMap[$row['path_hash']] ?? null;
            if ($path !== null) {
                $fileIds[$path] = (int)$row['fileid'];
            }
        }
        $result->closeCursor();

        return $fileIds;
    }

    /**
     * Build thumbnail URL and link for a search result entry.
     *
     * @return array{0: string, 1: string}  [thumbnailUrl, link]
     */
    private function buildLink(
        int $ncFileId,
        string $mimeType,
        string $mountName,
        string $virtualPath,
    ): array {
        if ($ncFileId > 0) {
            $thumbnailUrl = $this->previewManager->isMimeSupported($mimeType)
                ? $this->urlGenerator->linkToRouteAbsolute(
                    'core.Preview.getPreviewByFileId',
                    ['x' => 32, 'y' => 32, 'fileId' => $ncFileId]
                )
                : '';
            $link = $this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->linkToRoute('files.View.showFile', ['fileid' => $ncFileId])
            );
        } else {
            $thumbnailUrl = '';
            // Fallback: open the parent directory (year/month/day/case_number)
            $parts   = explode('/', $virtualPath);
            $dirPath = '/' . $mountName . '/' . implode('/', array_slice($parts, 0, 4));
            $link    = $this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->linkToRoute('files.View.index', ['dir' => $dirPath])
            );
        }

        return [$thumbnailUrl, $link];
    }

    private function getStorageNumericId(): ?int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('numeric_id')
            ->from('storages')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter('opencase')));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return $row ? (int)$row['numeric_id'] : null;
    }
}
