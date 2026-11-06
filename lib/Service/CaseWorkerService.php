<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\CaseWorker;
use OCA\OpenCase\Db\CaseWorkerMapper;
use OCP\IUserManager;

/**
 * Adding caseworkers to a case: used by the caseworker API and by
 * processes (ProcessWorker, "Tildel sag").
 */
class CaseWorkerService {

    public function __construct(
        private CaseWorkerMapper $caseworkerMapper,
        private PermissionService $permissionService,
        private AuditService $auditService,
        private IUserManager $userManager,
    ) {
    }

    /**
     * Add a caseworker with write access. The user's access before is
     * remembered, so removing them restores it. The caller checks that the
     * user isn't the responsible or already a caseworker.
     */
    public function add(int $caseId, string $targetUserId, string $actingUserId): void {
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
            $this->permissionService->grantUserAccess($caseId, $targetUserId, true, $actingUserId);
        }

        $this->auditService->logCaseworkerAdded(
            $caseId,
            $actingUserId,
            $targetUserId,
            $this->userManager->get($targetUserId)?->getDisplayName() ?? $targetUserId,
        );
    }
}
