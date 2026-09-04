<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\CaseParticipantMapper;
use OCA\OpenCase\Db\CaseStatusMapper;
use OCA\OpenCase\Db\CaseUserMapper;
use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Db\ParticipantRoleMapper;
use OCA\OpenCase\Db\UserInfoMapper;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\L10N\IFactory;

class EmployeeController extends ApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private IDBConnection $db,
        private OrganisationMapper $organisationMapper,
        private CaseUserMapper $caseUserMapper,
        private CaseParticipantMapper $caseParticipantMapper,
        private CaseStatusMapper $caseStatusMapper,
        private ParticipantRoleMapper $participantRoleMapper,
        private UserInfoMapper $userInfoMapper,
        private IUserManager $userManager,
        private IFactory $l10nFactory,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    private function getUserLanguage(): string {
        if ($this->userId === null) {
            return 'en';
        }
        $user = $this->userManager->get($this->userId);
        return $user ? $this->l10nFactory->getUserLanguage($user) : 'en';
    }

    private function statusCssClass(int $statusId): string {
        return match ($statusId) {
            1 => 'open',
            2 => 'closed',
            3 => 'archived',
            default => 'unknown',
        };
    }

    /**
     * Return all active organisations as a flat list with uuid, name and parent_uuid.
     * The frontend uses this to build the org hierarchy treeview.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/employees/org-tree')]
    public function orgTree(): DataResponse {
        $orgs = $this->organisationMapper->findAllActive();
        $nodes = array_map(fn($o) => [
            'org_uuid'        => $o->getOrgUuid(),
            'org_name'        => $o->getOrgName(),
            'org_parent_uuid' => $o->getOrgParentUuid(),
        ], $orgs);
        return new DataResponse(['orgs' => $nodes]);
    }

    /**
     * Returns privilege groups for the given user from oc_opencase_user_priv_groups,
     * with foelsomhed UUIDs resolved to sensitivity titles and orgenhed UUIDs
     * resolved to org names.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/employees/{uuid}/privileges')]
    public function userPrivileges(string $uuid): DataResponse {
        $userInfo = $this->userInfoMapper->findByUuid($uuid);
        $userId = $userInfo?->getUserId();
        if ($userId === null) {
            return new DataResponse(['error' => 'User not found'], Http::STATUS_NOT_FOUND);
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select(['id', 'privilege_type', 'foelsomhed_raw', 'kle_raw', 'orgenhed_raw'])
            ->from('opencase_user_priv_groups')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('id', 'ASC');

        $result = $qb->executeQuery();
        $rows = [];
        $allSensUuids = [];
        $allOrgUuids  = [];

        while ($row = $result->fetch()) {
            $sensUuids = $row['foelsomhed_raw'] ? array_filter(array_map('trim', explode(',', $row['foelsomhed_raw']))) : [];
            $orgUuids  = $row['orgenhed_raw']   ? array_filter(array_map('trim', explode(',', $row['orgenhed_raw'])))   : [];
            $kle       = $row['kle_raw']         ? array_filter(array_map('trim', explode(',', $row['kle_raw'])))        : [];

            $rows[] = [
                'privilege_type' => $row['privilege_type'],
                'sens_uuids'     => array_values($sensUuids),
                'kle'            => array_values($kle),
                'org_uuids'      => array_values($orgUuids),
            ];

            array_push($allSensUuids, ...$sensUuids);
            array_push($allOrgUuids,  ...$orgUuids);
        }
        $result->closeCursor();

        // Resolve sensitivity UUIDs → titles
        $sensMap = [];
        $uniqueSens = array_values(array_unique($allSensUuids));
        if (!empty($uniqueSens)) {
            $sqb = $this->db->getQueryBuilder();
            $sqb->select(['uuid', 'title'])
                ->from('opencase_sensitivity')
                ->where($sqb->expr()->in('uuid', $sqb->createNamedParameter($uniqueSens, IQueryBuilder::PARAM_STR_ARRAY)));
            $sr = $sqb->executeQuery();
            while ($r = $sr->fetch()) {
                $sensMap[$r['uuid']] = $r['title'];
            }
            $sr->closeCursor();
        }

        // Resolve org UUIDs → org_name
        $orgMap = [];
        $uniqueOrgs = array_values(array_unique($allOrgUuids));
        if (!empty($uniqueOrgs)) {
            $oqb = $this->db->getQueryBuilder();
            $oqb->select(['org_uuid', 'org_name'])
                ->from('opencase_org')
                ->where($oqb->expr()->in('org_uuid', $oqb->createNamedParameter($uniqueOrgs, IQueryBuilder::PARAM_STR_ARRAY)));
            $or = $oqb->executeQuery();
            while ($r = $or->fetch()) {
                $orgMap[$r['org_uuid']] = $r['org_name'];
            }
            $or->closeCursor();
        }

        $privileges = array_map(fn($row) => [
            'privilege_type' => $row['privilege_type'],
            'foelsomhed'     => array_values(array_map(fn($u) => $sensMap[$u] ?? $u, $row['sens_uuids'])),
            'kle'            => $row['kle'],
            'orgenhed'       => array_values(array_map(fn($u) => $orgMap[$u]  ?? $u, $row['org_uuids'])),
        ], $rows);

        return new DataResponse(['privileges' => $privileges]);
    }

    /**
     * Returns cases where the given Nextcloud user has a direct manual access grant.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/employees/{uuid}/grants')]
    public function userGrants(string $uuid): DataResponse {
        $userInfo = $this->userInfoMapper->findByUuid($uuid);
        $userId = $userInfo?->getUserId();
        if ($userId === null) {
            return new DataResponse(['error' => 'User not found'], Http::STATUS_NOT_FOUND);
        }
        $rows   = $this->caseUserMapper->findGrantedCasesByUserId($userId);
        $grants = array_map(fn($r) => [
            'case_id'     => (int)$r['case_id'],
            'case_number' => $r['case_number'],
            'title'       => $r['title'],
            'can_write'   => (bool)(int)$r['can_write'],
            'granted_at'  => $r['granted_at'],
            'expires_at'  => $r['expires_at'],
        ], $rows);
        return new DataResponse(['grants' => $grants]);
    }

    /**
     * Returns cases where the given Nextcloud user is a case participant
     * (i.e. the employee is the subject of an "Employee case" / Personalesag).
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/employees/{username}/cases')]
    public function cases(string $username): DataResponse {
        $rows = $this->caseParticipantMapper->findRawCasesByUserId($username);

        $lang        = $this->getUserLanguage();
        $statusIds   = array_unique(array_column($rows, 'status_id'));
        $statusNames = $this->caseStatusMapper->getNamesByIds(
            array_map('intval', $statusIds),
            $lang
        );

        $orgUuids  = array_unique(array_filter(array_column($rows, 'org_uuid')));
        $orgNames  = $this->organisationMapper->getNamesByUuids($orgUuids);
        $roleNames = $this->participantRoleMapper->getNameMap($lang);
        if (empty($roleNames)) {
            $roleNames = $this->participantRoleMapper->getNameMap('en');
        }

        $cases = array_map(function (array $row) use ($statusNames, $orgNames, $roleNames): array {
            $sid    = (int)$row['status_id'];
            $sEntry = $statusNames[$sid] ?? [];
            $rid    = (int)$row['participantrole_id'];
            return [
                'id'           => (int)$row['id'],
                'case_number'  => $row['case_number'],
                'title'        => $row['title'],
                'organisation' => $orgNames[$row['org_uuid']] ?? '',
                'status_class' => $this->statusCssClass($sid),
                'status_name'  => $sEntry['name'] ?? '',
                'updated_at'   => $row['updated_at'],
                'role_name'    => $roleNames[$rid] ?? '',
            ];
        }, $rows);

        return new DataResponse(['cases' => $cases]);
    }

    /**
     * Search employees in oc_opencase_userinfo, optionally filtered by
     * organisation name or role from oc_opencase_userorgs.
     *
     * Query params:
     *   q            — text search across personname, username, email, phone, location
     *   organisation — substring filter on org_name
     *   role         — substring filter on role
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/employees/search')]
    public function search(): DataResponse {
        $q    = trim($this->request->getParam('q', ''));
        $org  = trim($this->request->getParam('organisation', ''));
        $role = trim($this->request->getParam('role', ''));

        if ($q === '' && $org === '' && $role === '') {
            return new DataResponse(['employees' => []]);
        }

        // Step 1: collect distinct UUIDs matching all search criteria
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('ui.uuid')
            ->from('opencase_userinfo', 'ui');

        if ($org !== '' || $role !== '') {
            $qb->innerJoin('ui', 'opencase_userorgs', 'uo',
                $qb->expr()->eq('uo.user_uuid', 'ui.uuid'));

            if ($org !== '') {
                $qb->innerJoin('uo', 'opencase_org', 'o',
                    $qb->expr()->eq('o.org_uuid', 'uo.org_uuid'));
                $orgLike = '%' . $this->db->escapeLikeParameter($org) . '%';
                $qb->andWhere($qb->expr()->iLike('o.org_name',
                    $qb->createNamedParameter($orgLike)));
            }

            if ($role !== '') {
                $roleLike = '%' . $this->db->escapeLikeParameter($role) . '%';
                $qb->andWhere($qb->expr()->iLike('uo.role',
                    $qb->createNamedParameter($roleLike)));
            }
        }

        if ($q !== '') {
            $like = '%' . $this->db->escapeLikeParameter($q) . '%';
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->iLike('ui.personname', $qb->createNamedParameter($like)),
                $qb->expr()->iLike('ui.username',   $qb->createNamedParameter($like)),
                $qb->expr()->iLike('ui.user_id',    $qb->createNamedParameter($like)),
                $qb->expr()->iLike('ui.email',      $qb->createNamedParameter($like)),
                $qb->expr()->iLike('ui.phone',      $qb->createNamedParameter($like)),
                $qb->expr()->iLike('ui.location',   $qb->createNamedParameter($like))
            ));
        }

        $qb->setMaxResults(50);

        $uuidResult = $qb->executeQuery();
        $uuids = [];
        while ($row = $uuidResult->fetch()) {
            $uuids[] = (string)$row['uuid'];
        }
        $uuidResult->closeCursor();

        if (empty($uuids)) {
            return new DataResponse(['employees' => []]);
        }

        // Step 2: fetch full user details for those UUIDs
        $detailQb = $this->db->getQueryBuilder();
        $detailQb->select(['uuid', 'username', 'personname', 'email', 'phone', 'location', 'user_id'])
            ->from('opencase_userinfo')
            ->where($detailQb->expr()->in('uuid',
                $detailQb->createNamedParameter($uuids, IQueryBuilder::PARAM_STR_ARRAY)));

        $detailResult = $detailQb->executeQuery();
        $users = [];
        while ($row = $detailResult->fetch()) {
            $users[$row['uuid']] = [
                'uuid'       => $row['uuid'],
                'username'   => $row['username'],
                'personname' => $row['personname'],
                'email'      => $row['email'],
                'phone'      => $row['phone'],
                'location'   => $row['location'],
                'user_id'    => $row['user_id'],
                'orgs'       => [],
            ];
        }
        $detailResult->closeCursor();

        // Step 3: fetch all org/role memberships for these users
        $orgQb = $this->db->getQueryBuilder();
        $orgQb->select(['uo.user_uuid', 'uo.role', 'o.org_name'])
            ->from('opencase_userorgs', 'uo')
            ->innerJoin('uo', 'opencase_org', 'o',
                $orgQb->expr()->eq('o.org_uuid', 'uo.org_uuid'))
            ->where($orgQb->expr()->in('uo.user_uuid',
                $orgQb->createNamedParameter($uuids, IQueryBuilder::PARAM_STR_ARRAY)));

        $orgResult = $orgQb->executeQuery();
        while ($row = $orgResult->fetch()) {
            $uuid = (string)$row['user_uuid'];
            if (isset($users[$uuid])) {
                $users[$uuid]['orgs'][] = [
                    'org_name' => $row['org_name'],
                    'role'     => $row['role'],
                ];
            }
        }
        $orgResult->closeCursor();

        return new DataResponse(['employees' => array_values($users)]);
    }
}
