<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Db\UserInfoMapper;
use OCA\OpenCase\Db\UserOrgMapper;
use OCA\OpenCase\Db\UserRoleMapper;
use OCA\OpenCase\Db\RoleMapper;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Current-user information endpoints.
 *
 * Routes:
 *   GET /api/v1/me/roles — list the OpenCase roles assigned to the current user
 */
class MeController extends OCSController {

    public function __construct(
        string $appName,
        IRequest $request,
        private ?string $userId,
        private UserRoleMapper $userRoleMapper,
        private RoleMapper $roleMapper,
        private UserInfoMapper $userInfoMapper,
        private UserOrgMapper $userOrgMapper,
        private OrganisationMapper $organisationMapper,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Return the OpenCase role names assigned to the current user.
     *
     * Response: { "roles": ["User", "Administrator"] }
     */
    #[NoAdminRequired]
    #[CORS]
    #[ApiRoute(verb: 'GET', url: '/api/v1/me/roles')]
    public function roles(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['roles' => []]);
        }

        $userRoles = $this->userRoleMapper->findByUser($this->userId);
        $roleIds   = array_map(fn($ur) => $ur->getRoleId(), $userRoles);

        // Batch lookup: fetch all roles then filter by assigned IDs.
        $allRoles = $this->roleMapper->findAll();
        $roleIdToName = [];
        foreach ($allRoles as $role) {
            $roleIdToName[$role->getId()] = $role->getName();
        }

        $names = array_values(array_filter(
            array_map(fn($id) => $roleIdToName[$id] ?? null, $roleIds),
        ));

        return new DataResponse(['roles' => $names]);
    }

    /**
     * Return the name of the current user's primary organisation.
     *
     * Priority: user's first org in oc_opencase_userorgs (looked up via user_id) → top-level org → null.
     *
     * Response: { "org_name": "Korsbaek Kommune" }
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/me/primary-org')]
    public function primaryOrg(): DataResponse {
        $orgName = null;

        if ($this->userId !== null) {
            $userInfo = $this->userInfoMapper->findByUserId($this->userId);
            if ($userInfo !== null) {
                $orgUuid = $this->userOrgMapper->findFirstOrgUuidForUser($userInfo->getUuid());
                if ($orgUuid !== null) {
                    $org = $this->organisationMapper->findByUuid($orgUuid);
                    $orgName = $org?->getOrgName();
                }
            }
        }

        if ($orgName === null) {
            $topLevel = $this->organisationMapper->findTopLevel();
            $orgName  = $topLevel?->getOrgName();
        }

        return new DataResponse(['org_name' => $orgName]);
    }
}
