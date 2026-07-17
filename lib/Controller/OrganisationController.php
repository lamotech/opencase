<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\OrganisationMapper;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;

class OrganisationController extends ApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private IDBConnection $db,
        private OrganisationMapper $organisationMapper,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Search active organisations by name.
     * Returns full data including parent org name and member count.
     *
     * Query params:
     *   q — substring match on org_name (required, min 1 char)
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/org/search')]
    public function search(): DataResponse {
        $q = trim($this->request->getParam('q', ''));
        if ($q === '') {
            return new DataResponse(['organisations' => []]);
        }

        $entities = $this->organisationMapper->searchByName($q, 50);

        if (empty($entities)) {
            return new DataResponse(['organisations' => []]);
        }

        // Build a map of all org uuids → names to resolve parent names
        $parentUuids = array_filter(array_unique(
            array_map(fn($e) => $e->getOrgParentUuid(), $entities)
        ));
        $parentNames = empty($parentUuids)
            ? []
            : $this->organisationMapper->getNamesByUuids(array_values($parentUuids));

        // Count members per org
        $orgUuids = array_map(fn($e) => $e->getOrgUuid(), $entities);
        $memberCounts = $this->getMemberCounts($orgUuids);

        $results = array_map(function ($org) use ($parentNames, $memberCounts) {
            $parentUuid = $org->getOrgParentUuid();
            return [
                'org_uuid'        => $org->getOrgUuid(),
                'org_name'        => $org->getOrgName(),
                'org_parent_uuid' => $parentUuid,
                'org_parent_name' => $parentUuid ? ($parentNames[$parentUuid] ?? '') : '',
                'active'          => (bool)$org->getActive(),
                'member_count'    => $memberCounts[$org->getOrgUuid()] ?? 0,
                'email'           => $org->getEmail(),
                'phone'           => $org->getPhone(),
                'location'        => $org->getLocation(),
            ];
        }, $entities);

        return new DataResponse(['organisations' => $results]);
    }

    /**
     * Return employees who are members of a specific organisation.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/org/{orgUuid}/members')]
    public function members(string $orgUuid): DataResponse {
        $qb = $this->db->getQueryBuilder();
        $qb->select(['ui.uuid', 'ui.personname', 'ui.username', 'ui.email', 'ui.phone', 'ui.location', 'uo.role'])
            ->from('opencase_userorgs', 'uo')
            ->innerJoin('uo', 'opencase_userinfo', 'ui',
                $qb->expr()->eq('ui.uuid', 'uo.user_uuid'))
            ->where($qb->expr()->eq('uo.org_uuid', $qb->createNamedParameter($orgUuid)))
            ->orderBy('ui.personname', 'ASC');

        $result = $qb->executeQuery();
        $members = [];
        while ($row = $result->fetch()) {
            $members[] = [
                'uuid'       => $row['uuid'],
                'personname' => $row['personname'],
                'username'   => $row['username'],
                'email'      => $row['email'],
                'phone'      => $row['phone'],
                'location'   => $row['location'],
                'role'       => $row['role'],
            ];
        }
        $result->closeCursor();

        return new DataResponse(['members' => $members]);
    }

    /**
     * @param string[] $orgUuids
     * @return array<string, int>
     */
    private function getMemberCounts(array $orgUuids): array {
        if (empty($orgUuids)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('org_uuid')
            ->addSelect($qb->createFunction('COUNT(*) AS cnt'))
            ->from('opencase_userorgs')
            ->where($qb->expr()->in('org_uuid',
                $qb->createNamedParameter($orgUuids, IQueryBuilder::PARAM_STR_ARRAY)))
            ->groupBy('org_uuid');

        $result = $qb->executeQuery();
        $counts = [];
        while ($row = $result->fetch()) {
            $counts[$row['org_uuid']] = (int)$row['cnt'];
        }
        $result->closeCursor();
        return $counts;
    }
}
