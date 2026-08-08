<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Service\FileShareService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST endpoints for file-level shares.
 *
 * Routes:
 *   GET    /api/v1/files/{fileId}/shares          list active shares for a file
 *   POST   /api/v1/files/{fileId}/shares          create or update a share
 *   DELETE /api/v1/files/{fileId}/shares/{userId} revoke a share
 */
class FileShareController extends OCSController {

    public function __construct(
        string $appName,
        IRequest $request,
        private FileShareService $shareService,
        private IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * List all files actively shared with the current user.
     *
     * @NoAdminRequired
     */
    #[ApiRoute(verb: 'GET', url: '/api/v1/me/shared-files')]
    public function sharedWithMe(): DataResponse {
        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            return new DataResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        return new DataResponse([
            'files' => $this->shareService->getSharedFilesForUser($currentUser->getUID()),
        ]);
    }

    /**
     * List all active shares for a file.
     *
     * @NoAdminRequired
     */
    #[ApiRoute(verb: 'GET', url: '/api/v1/files/{fileId}/shares')]
    public function index(int $fileId): DataResponse {
        try {
            $shares = $this->shareService->getActiveShares($fileId);
            return new DataResponse([
                'shares' => array_map([$this->shareService, 'toArray'], $shares),
            ]);
        } catch (\Throwable $e) {
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Create or update a file share.
     *
     * Body: { "user_id": string, "can_write": bool, "expires_at": string|null, "note": string|null }
     *
     * @NoAdminRequired
     */
    #[ApiRoute(verb: 'POST', url: '/api/v1/files/{fileId}/shares')]
    public function create(int $fileId): DataResponse {
        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            return new DataResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $sharedWith = $this->request->getParam('user_id', '');
        $canWrite   = (bool)$this->request->getParam('can_write', false);
        $expiresStr = $this->request->getParam('expires_at');
        $note       = $this->request->getParam('note');

        if (empty($sharedWith)) {
            return new DataResponse(['message' => 'user_id is required'], Http::STATUS_BAD_REQUEST);
        }

        $expiresAt = null;
        if (!empty($expiresStr)) {
            try {
                $expiresAt = new \DateTime($expiresStr);
            } catch (\Exception) {
                return new DataResponse(['message' => 'Invalid expires_at date'], Http::STATUS_BAD_REQUEST);
            }
        }

        try {
            $share = $this->shareService->createShare(
                $fileId,
                $sharedWith,
                $canWrite,
                $currentUser->getUID(),
                $expiresAt,
                $note ?: null,
            );
            return new DataResponse(['share' => $this->shareService->toArray($share)]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }
    }

    /**
     * Revoke a file share.
     *
     * @NoAdminRequired
     */
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/files/{fileId}/shares/{userId}')]
    public function destroy(int $fileId, string $userId): DataResponse {
        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            return new DataResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->shareService->revokeShare($fileId, $userId, $currentUser->getUID());
            return new DataResponse([]);
        } catch (\RuntimeException $e) {
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }
    }

    /**
     * List aggregated shares for a document.
     *
     * @NoAdminRequired
     */
    #[ApiRoute(verb: 'GET', url: '/api/v1/documents/{documentId}/shares')]
    public function documentShares(int $documentId): DataResponse {
        return new DataResponse([
            'shares' => $this->shareService->getDocumentShares($documentId),
        ]);
    }

    /**
     * Share all files in a document with a user.
     *
     * Body: { "user_id": string, "can_write": bool, "expires_at": string|null, "note": string|null }
     *
     * @NoAdminRequired
     */
    #[ApiRoute(verb: 'POST', url: '/api/v1/documents/{documentId}/shares')]
    public function createDocumentShare(int $documentId): DataResponse {
        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            return new DataResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $sharedWith = $this->request->getParam('user_id', '');
        $canWrite   = (bool)$this->request->getParam('can_write', false);
        $expiresStr = $this->request->getParam('expires_at');
        $note       = $this->request->getParam('note');

        if (empty($sharedWith)) {
            return new DataResponse(['message' => 'user_id is required'], Http::STATUS_BAD_REQUEST);
        }

        $expiresAt = null;
        if (!empty($expiresStr)) {
            try {
                $expiresAt = new \DateTime($expiresStr);
            } catch (\Exception) {
                return new DataResponse(['message' => 'Invalid expires_at date'], Http::STATUS_BAD_REQUEST);
            }
        }

        try {
            $this->shareService->createDocumentShare(
                $documentId,
                $sharedWith,
                $canWrite,
                $currentUser->getUID(),
                $expiresAt,
                $note ?: null,
            );
            return new DataResponse(['shares' => $this->shareService->getDocumentShares($documentId)]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }
    }

    /**
     * Revoke a document-level share (removes all file shares for document+user).
     *
     * @NoAdminRequired
     */
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/documents/{documentId}/shares/{userId}')]
    public function destroyDocumentShare(int $documentId, string $userId): DataResponse {
        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            return new DataResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->shareService->revokeDocumentShare($documentId, $userId, $currentUser->getUID());
            return new DataResponse([]);
        } catch (\RuntimeException $e) {
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }
    }
}
