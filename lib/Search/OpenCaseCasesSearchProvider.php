<?php

declare(strict_types=1);

namespace OCA\OpenCase\Search;

use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Service\PermissionService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Global search provider for OpenCase cases.
 *
 * Searches case titles and case numbers in the database.
 * Results link directly to the case detail page in the OpenCase app.
 */
class OpenCaseCasesSearchProvider implements IProvider {

    public function __construct(
        private IL10N $l10n,
        private IURLGenerator $urlGenerator,
        private CaseMapper $caseMapper,
        private PermissionService $permissionService,
    ) {
    }

    public function getId(): string {
        return 'opencase_cases';
    }

    public function getName(): string {
        return $this->l10n->t('Sager');
    }

    public function getOrder(string $route, array $routeParameters): int {
        return 2;
    }

    public function search(IUser $user, ISearchQuery $query): SearchResult {
        $term = trim($query->getTerm());
        if ($term === '') {
            return SearchResult::paginated($this->getName(), [], $query->getCursor() + $query->getLimit());
        }

        $limit  = $query->getLimit();
        $offset = (int)$query->getCursor();

        $profileIds = $this->permissionService->getAccessProfileIdsForUser($user->getUID());

        // searchByTitleOrNumber does not support offset — fetch limit + offset and slice
        $cases = $this->caseMapper->searchByTitleOrNumber($term, $profileIds, $user->getUID(), $limit + $offset);
        $cases = array_slice($cases, $offset, $limit);
        $entries = [];

        foreach ($cases as $case) {
            $link = $this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->linkToRoute(
                    'opencase.page.catch_all',
                    ['path' => 'case/' . $case->getId()]
                )
            );

            $entries[] = new SearchResultEntry(
                '',
                $case->getTitle(),
                $case->getCaseNumber(),
                $link,
                $this->urlGenerator->imagePath('opencase', 'app-dark.svg'),
            );
        }

        return SearchResult::paginated($this->getName(), $entries, $offset + $limit);
    }
}
