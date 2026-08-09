<?php

declare(strict_types=1);

namespace OCA\OpenCase\Search;

use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Service\PermissionService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Global search provider for OpenCase documents.
 *
 * Searches document titles in the database, filtered by the user's access
 * profiles. Results link directly to the document detail page in the
 * OpenCase app.
 */
class OpenCaseDocumentsSearchProvider implements IProvider {

    public function __construct(
        private IL10N $l10n,
        private IURLGenerator $urlGenerator,
        private DocumentMapper $documentMapper,
        private PermissionService $permissionService,
    ) {
    }

    public function getId(): string {
        return 'opencase_documents';
    }

    public function getName(): string {
        return $this->l10n->t('Dokumenter');
    }

    public function getOrder(string $route, array $routeParameters): int {
        return 3;
    }

    public function search(IUser $user, ISearchQuery $query): SearchResult {
        $term = trim($query->getTerm());
        if ($term === '') {
            return SearchResult::paginated($this->getName(), [], $query->getCursor() + $query->getLimit());
        }

        $limit  = $query->getLimit();
        $offset = (int)$query->getCursor();

        // Cheap gate: without it, a user with no access at all would make the
        // optimiser scan opencase_documents looking for rows that can never match.
        if (!$this->permissionService->userHasAnyAccess($user->getUID())) {
            return SearchResult::paginated($this->getName(), [], $offset + $limit);
        }

        $rows = $this->documentMapper->searchByTitle($term, $user->getUID(), $limit, $offset);
        $entries = [];

        foreach ($rows as $row) {
            $link = $this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->linkToRoute(
                    'opencase.page.catch_all',
                    ['path' => 'document/' . $row['id']]
                )
            );

            $entries[] = new SearchResultEntry(
                '',
                $row['title'],
                'Sag: ' . $row['case_number'] . ' – ' . $row['case_title'],
                $link,
                $this->urlGenerator->imagePath('opencase', 'app-dark.svg'),
            );
        }

        return SearchResult::paginated($this->getName(), $entries, $offset + $limit);
    }
}
