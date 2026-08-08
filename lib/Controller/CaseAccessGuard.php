<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;

/**
 * Case- and document-level access checks for controllers that read or write
 * data hanging off a case.
 *
 * OpenCase resolves access per case (responsible user, direct grant, access
 * profile) and per document (per-user overrides, file shares) in
 * PermissionService. Anything reachable by an id the caller supplies — journal
 * notes, participants, contacts, workflows, access requests — has to be checked
 * against that, or the id is the only thing between a user and every case in
 * the municipality.
 *
 * Each guard returns null when the caller may proceed, or the DataResponse the
 * controller should return instead:
 *
 *     if ($denied = $this->denyUnlessCaseRead($caseId)) {
 *         return $denied;
 *     }
 *
 * Using classes must have a `PermissionService $permissionService` property and
 * a `?string $userId` property.
 */
trait CaseAccessGuard {

    protected function denyUnlessCaseRead(int $caseId): ?DataResponse {
        return $this->guard(
            fn (string $userId): bool => $this->permissionService->userHasReadAccessToCase($userId, $caseId)
        );
    }

    protected function denyUnlessCaseWrite(int $caseId): ?DataResponse {
        return $this->guard(
            fn (string $userId): bool => $this->permissionService->userHasWriteAccessToCase($userId, $caseId)
        );
    }

    protected function denyUnlessDocumentRead(int $documentId): ?DataResponse {
        return $this->guard(
            fn (string $userId): bool => $this->permissionService->userHasReadAccessToDocument($userId, $documentId)
        );
    }

    protected function denyUnlessDocumentWrite(int $documentId): ?DataResponse {
        return $this->guard(
            fn (string $userId): bool => $this->permissionService->userHasWriteAccessToDocument($userId, $documentId)
        );
    }

    /**
     * @param callable(string): bool $check
     */
    private function guard(callable $check): ?DataResponse {
        // An unauthenticated caller should never reach these routes (they are
        // all behind Nextcloud's auth middleware), but treat a missing user as
        // denied rather than passing '' down to the permission checks.
        if ($this->userId === null || $this->userId === '') {
            return $this->accessDenied();
        }

        return $check($this->userId) ? null : $this->accessDenied();
    }

    private function accessDenied(): DataResponse {
        return new DataResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
    }
}
