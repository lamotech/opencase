<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\CaseWorker;
use OCA\OpenCase\Db\CaseWorkerMapper;
use OCA\OpenCase\Service\AuditService;
use OCA\OpenCase\Service\PermissionService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserManager;

/**
 * REST API controller for case caseworkers.
 *
 * Routes:
 *   GET    /api/v1/users/search                               — Search OpenCase users
 *   GET    /api/v1/cases/{caseId}/caseworkers                 — List caseworkers (responsible + others)
 *   POST   /api/v1/cases/{caseId}/caseworkers                 — Add a caseworker
 *   DELETE /api/v1/cases/{caseId}/caseworkers/{targetUserId}  — Remove a caseworker
 */
class CaseWorkerController extends ApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private CaseWorkerMapper $caseworkerMapper,
        private CaseMapper $caseMapper,
        private IUserManager $userManager,
        private IDBConnection $db,
        private PermissionService $permissionService,
        private AuditService $auditService,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    private function serializeWorker(string $userId, string $role): array {
        $user = $this->userManager->get($userId);
        return [
            'user_id'      => $userId,
            'display_name' => $user?->getDisplayName() ?? $userId,
            'role'         => $role,
        ];
    }

    /**
     * Search OpenCase users by UID, display name, or preferences-stored display name.
     *
     * Uses a direct DB query so results are independent of Nextcloud sharing
     * settings and user-backend search limitations. Only users that have been
     * assigned an OpenCase role are returned.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/users/search')]
    public function searchUsers(): DataResponse {
        $query = trim($this->request->getParam('q', ''));
        if (strlen($query) < 2) {
            return new DataResponse(['users' => []]);
        }

        // Step 1: collect all UIDs that have an OpenCase role
        $roleQb = $this->db->getQueryBuilder();
        $roleQb->select('user_id')->from('opencase_user_roles');
        $roleResult = $roleQb->executeQuery();
        $uids = [];
        while ($row = $roleResult->fetch()) {
            $uids[] = (string)$row['user_id'];
        }
        $roleResult->closeCursor();

        if (empty($uids)) {
            return new DataResponse(['users' => []]);
        }

        // Step 2: filter those users by UID, displayname, or preferences-stored displayname
        $like = '%' . $this->db->escapeLikeParameter($query) . '%';

        $qb = $this->db->getQueryBuilder();
        $qb->select(['u.uid', 'u.displayname', 'p.configvalue AS pref_displayname'])
            ->from('users', 'u')
            ->leftJoin('u', 'preferences', 'p', $qb->expr()->andX(
                $qb->expr()->eq('p.userid', 'u.uid'),
                $qb->expr()->eq('p.appid', $qb->createNamedParameter('settings')),
                $qb->expr()->eq('p.configkey', $qb->createNamedParameter('displayname'))
            ))
            ->where($qb->expr()->in('u.uid', $qb->createNamedParameter($uids, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->iLike('u.uid', $qb->createNamedParameter($like)),
                $qb->expr()->iLike('u.displayname', $qb->createNamedParameter($like)),
                $qb->expr()->iLike('p.configvalue', $qb->createNamedParameter($like))
            ))
            ->setMaxResults(20);

        $result = $qb->executeQuery();
        $users = [];
        $seen = [];
        while ($row = $result->fetch()) {
            $uid = (string)$row['uid'];
            if (isset($seen[$uid])) {
                continue;
            }
            $seen[$uid] = true;
            $displayName = $row['pref_displayname'] ?: ($row['displayname'] ?: null);
            $users[] = [
                'id'    => $uid,
                'label' => $displayName ?: $uid,
            ];
        }
        $result->closeCursor();

        return new DataResponse(['users' => $users]);
    }

    /**
     * List all caseworkers for a case (responsible + others).
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/cases/{caseId}/caseworkers')]
    public function indexByCase(int $caseId): DataResponse {
        $case = $this->caseMapper->findById($caseId);
        if ($case === null) {
            return new DataResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
        }

        $caseworkers = [];

        $responsibleId = $case->getResponsibleUserId();
        if ($responsibleId !== null && $responsibleId !== '') {
            $caseworkers[] = $this->serializeWorker($responsibleId, 'responsible');
        }

        foreach ($this->caseworkerMapper->findByCase($caseId) as $cw) {
            $caseworkers[] = $this->serializeWorker($cw->getUserId(), 'other');
        }

        return new DataResponse(['caseworkers' => $caseworkers]);
    }

    /**
     * Add a caseworker to a case.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/cases/{caseId}/caseworkers')]
    public function create(int $caseId): DataResponse {
        $params = $this->request->getParams();
        $targetUserId = trim($params['user_id'] ?? '');

        if ($targetUserId === '') {
            return new DataResponse(['error' => "Field 'user_id' is required"], Http::STATUS_BAD_REQUEST);
        }

        if ($this->userManager->get($targetUserId) === null) {
            return new DataResponse(['error' => 'User not found'], Http::STATUS_NOT_FOUND);
        }

        $case = $this->caseMapper->findById($caseId);
        if ($case === null) {
            return new DataResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
        }

        if ($case->getResponsibleUserId() === $targetUserId) {
            return new DataResponse(['error' => 'User is already the responsible caseworker'], Http::STATUS_CONFLICT);
        }

        if ($this->caseworkerMapper->existsByCaseAndUser($caseId, $targetUserId)) {
            return new DataResponse(['error' => 'User is already a caseworker on this case'], Http::STATUS_CONFLICT);
        }

        // Snapshot existing access so we can restore it on removal
        $existingGrant = $this->permissionService->findGrant($caseId, $targetUserId);
        $priorCanWrite = $existingGrant !== null ? ($existingGrant->getCanWrite() ? 1 : 0) : null;

        $entity = new CaseWorker();
        $entity->setCaseId($caseId);
        $entity->setUserId($targetUserId);
        $entity->setPriorCanWrite($priorCanWrite);
        $this->caseworkerMapper->insert($entity);

        // Grant write access (no-op if they already have write)
        if ($existingGrant === null || !$existingGrant->getCanWrite()) {
            $this->permissionService->grantUserAccess($caseId, $targetUserId, true, $this->userId ?? '');
        }

        $serialized = $this->serializeWorker($targetUserId, 'other');
        $this->auditService->logCaseworkerAdded(
            $caseId,
            $this->userId ?? '',
            $targetUserId,
            $serialized['display_name'],
        );

        return new DataResponse(['caseworker' => $serialized], Http::STATUS_CREATED);
    }

    /**
     * Remove a caseworker from a case. The responsible caseworker cannot be removed.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/cases/{caseId}/caseworkers/{targetUserId}')]
    public function destroy(int $caseId, string $targetUserId): DataResponse {
        $case = $this->caseMapper->findById($caseId);
        if ($case === null) {
            return new DataResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
        }

        if ($case->getResponsibleUserId() === $targetUserId) {
            return new DataResponse(['error' => 'The responsible caseworker cannot be removed'], Http::STATUS_FORBIDDEN);
        }

        $cw = $this->caseworkerMapper->findByCaseAndUser($caseId, $targetUserId);
        if ($cw === null) {
            return new DataResponse(['error' => 'Caseworker not found'], Http::STATUS_NOT_FOUND);
        }

        $serialized  = $this->serializeWorker($targetUserId, 'other');
        $priorCanWrite = $cw->getPriorCanWrite();
        $this->caseworkerMapper->delete($cw);

        // Restore the user's original access level
        if ($priorCanWrite === null) {
            // Had no access before — remove entirely
            $this->permissionService->revokeUserAccess($caseId, $targetUserId);
        } elseif ($priorCanWrite === 0) {
            // Had read access before — downgrade back to read
            $this->permissionService->grantUserAccess($caseId, $targetUserId, false, $this->userId ?? '');
        }
        // $priorCanWrite === 1 means they already had write access — nothing to change

        $this->auditService->logCaseworkerRemoved(
            $caseId,
            $this->userId ?? '',
            $targetUserId,
            $serialized['display_name'],
        );

        return new DataResponse([], Http::STATUS_NO_CONTENT);
    }
}
