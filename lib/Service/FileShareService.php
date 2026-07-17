<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\FileMapper;
use OCA\OpenCase\Db\FileShareEntity;
use OCA\OpenCase\Db\FileShareMapper;
use OCP\Constants;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;

/**
 * Business logic for file-level shares.
 *
 * A file share allows a specific NC user to access a single OpenCase file
 * without being granted access to the case. The share is stored in
 * opencase_file_shares and enforced at runtime by OpenCasePermissionWrapper
 * and OpenCaseCacheWrapper.
 */
class FileShareService {

    public function __construct(
        private FileShareMapper $shareMapper,
        private FileMapper $fileMapper,
        private DocumentMapper $documentMapper,
        private PermissionService $permissionService,
        private AuditService $auditService,
        private IUserManager $userManager,
        private INotificationManager $notificationManager,
    ) {
    }

    /**
     * Create or update a file share.
     *
     * @throws \InvalidArgumentException if validation fails
     * @throws \RuntimeException if the file or recipient does not exist
     */
    public function createShare(
        int $fileId,
        string $sharedWithUserId,
        bool $canWrite,
        string $byUserId,
        ?\DateTime $expiresAt = null,
        ?string $note = null,
    ): FileShareEntity {
        $file = $this->fileMapper->findById($fileId);
        if ($file === null) {
            throw new \RuntimeException("File {$fileId} not found");
        }

        if ($this->userManager->get($sharedWithUserId) === null) {
            throw new \InvalidArgumentException("User '{$sharedWithUserId}' does not exist");
        }

        if ($sharedWithUserId === $byUserId) {
            throw new \InvalidArgumentException('Cannot share a file with yourself');
        }

        // Verify the sharer has at least read access to the file.
        if (!$this->permissionService->userHasReadAccessToCase($byUserId, $file->getCaseId())) {
            throw new \RuntimeException('Insufficient permissions to share this file');
        }

        $perms = $canWrite
            ? (Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE)
            : Constants::PERMISSION_READ;

        // Upsert: delete existing share for this file+user and re-insert.
        $this->shareMapper->deleteByFileAndUser($fileId, $sharedWithUserId);

        $share = new FileShareEntity();
        $share->setFileId($fileId);
        $share->setOwnerId($byUserId);
        $share->setSharedWith($sharedWithUserId);
        $share->setPermissions($perms);
        $share->setCreatedAt(new \DateTime());
        $share->setExpiresAt($expiresAt);
        $share->setNote($note);

        $share = $this->shareMapper->insert($share);

        $this->auditService->logFileShared(
            $file->getCaseId(),
            $file->getDocumentId(),
            $fileId,
            $byUserId,
            $sharedWithUserId,
            $canWrite,
            $file->getVirtualFilename(),
            $expiresAt,
        );

        $this->sendShareNotification($fileId, $sharedWithUserId, $byUserId, $file->getOriginalFilename());

        return $share;
    }

    /**
     * Revoke a file share.
     *
     * @throws \RuntimeException if the file does not exist or caller lacks access
     */
    public function revokeShare(int $fileId, string $sharedWithUserId, string $byUserId): void {
        $file = $this->fileMapper->findById($fileId);
        if ($file === null) {
            throw new \RuntimeException("File {$fileId} not found");
        }

        if (!$this->permissionService->userHasReadAccessToCase($byUserId, $file->getCaseId())) {
            throw new \RuntimeException('Insufficient permissions to revoke this share');
        }

        $this->shareMapper->deleteByFileAndUser($fileId, $sharedWithUserId);

        $this->auditService->logFileShareRevoked(
            $file->getCaseId(),
            $file->getDocumentId(),
            $fileId,
            $byUserId,
            $sharedWithUserId,
            $file->getVirtualFilename(),
        );
    }

    /**
     * Return all active shares for a file.
     *
     * @return FileShareEntity[]
     */
    public function getActiveShares(int $fileId): array {
        return $this->shareMapper->findActiveByFileId($fileId);
    }

    /**
     * Return all files actively shared with the given user, enriched with file and
     * document metadata. Each entry includes `document_id`, `document_title`, and
     * `document_number` when the share was created as a document-level share.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSharedFilesForUser(string $userId): array {
        $shares   = $this->shareMapper->findActiveByUser($userId);
        $result   = [];
        $docCache = [];

        foreach ($shares as $share) {
            $file = $this->fileMapper->findById($share->getFileId());
            if ($file === null) {
                continue;
            }
            $sharer = $this->userManager->get($share->getOwnerId());

            $documentId  = $share->getDocumentId();
            $docTitle    = null;
            $docNumber   = null;

            if ($documentId !== null) {
                if (!array_key_exists($documentId, $docCache)) {
                    $docCache[$documentId] = $this->documentMapper->findById($documentId);
                }
                $doc = $docCache[$documentId];
                if ($doc !== null) {
                    $docTitle  = $doc->getTitle();
                    $docNumber = $doc->getDocumentNumber();
                }
            }

            $result[] = [
                'share_id'          => $share->getId(),
                'file_id'           => $share->getFileId(),
                'document_id'       => $documentId,
                'document_title'    => $docTitle,
                'document_number'   => $docNumber,
                'original_filename' => $file->getOriginalFilename(),
                'mime_type'         => $file->getMimeType(),
                'size'              => $file->getSize(),
                'updated_at'        => $file->getUpdatedAt()?->format('c'),
                'can_write'         => $share->canWrite(),
                'owner_id'          => $share->getOwnerId(),
                'owner_display'     => $sharer?->getDisplayName() ?? $share->getOwnerId(),
                'expires_at'        => $share->getExpiresAt()?->format('c'),
                'note'              => $share->getNote(),
            ];
        }
        return $result;
    }

    /**
     * Share all files in a document with a user.
     * Each row in opencase_file_shares gets document_id set so it can be
     * managed as a group later.
     *
     * @throws \InvalidArgumentException|\RuntimeException
     * @return FileShareEntity[]
     */
    public function createDocumentShare(
        int $documentId,
        string $sharedWithUserId,
        bool $canWrite,
        string $byUserId,
        ?\DateTime $expiresAt = null,
        ?string $note = null,
    ): array {
        $document = $this->documentMapper->findById($documentId);
        if ($document === null) {
            throw new \RuntimeException("Document {$documentId} not found");
        }

        if ($this->userManager->get($sharedWithUserId) === null) {
            throw new \InvalidArgumentException("User '{$sharedWithUserId}' does not exist");
        }

        if ($sharedWithUserId === $byUserId) {
            throw new \InvalidArgumentException('Cannot share with yourself');
        }

        if (!$this->permissionService->userHasReadAccessToCase($byUserId, $document->getCaseId())) {
            throw new \RuntimeException('Insufficient permissions to share this document');
        }

        $files = $this->fileMapper->findByDocument($documentId);
        if (empty($files)) {
            throw new \RuntimeException('Document has no files to share');
        }

        $perms = $canWrite
            ? (\OCP\Constants::PERMISSION_READ | \OCP\Constants::PERMISSION_UPDATE)
            : \OCP\Constants::PERMISSION_READ;

        $shares = [];
        foreach ($files as $file) {
            $this->shareMapper->deleteByFileAndUser($file->getId(), $sharedWithUserId);

            $share = new FileShareEntity();
            $share->setFileId($file->getId());
            $share->setOwnerId($byUserId);
            $share->setSharedWith($sharedWithUserId);
            $share->setPermissions($perms);
            $share->setCreatedAt(new \DateTime());
            $share->setExpiresAt($expiresAt);
            $share->setNote($note);
            $share->setDocumentId($documentId);

            $shares[] = $this->shareMapper->insert($share);
        }

        $this->sendDocumentShareNotification($documentId, $document->getTitle(), $sharedWithUserId, $byUserId);

        return $shares;
    }

    private function sendDocumentShareNotification(int $documentId, string $documentTitle, string $recipient, string $sharer): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification
                ->setApp('opencase')
                ->setUser($recipient)
                ->setDateTime(new \DateTime())
                ->setObject('document', (string)$documentId)
                ->setSubject('document_shared', [
                    'document_title' => $documentTitle,
                    'document_id'    => $documentId,
                    'sharer'         => $sharer,
                ]);
            $this->notificationManager->notify($notification);
        } catch (\InvalidArgumentException) {
            // swallow — notification is best-effort
        }
    }

    /**
     * Revoke all file shares belonging to a document-level share.
     *
     * @throws \RuntimeException
     */
    public function revokeDocumentShare(int $documentId, string $sharedWithUserId, string $byUserId): void {
        $document = $this->documentMapper->findById($documentId);
        if ($document === null) {
            throw new \RuntimeException("Document {$documentId} not found");
        }

        if (!$this->permissionService->userHasReadAccessToCase($byUserId, $document->getCaseId())) {
            throw new \RuntimeException('Insufficient permissions to revoke this share');
        }

        $this->shareMapper->deleteByDocumentAndUser($documentId, $sharedWithUserId);
    }

    /**
     * Return aggregated document-level shares (one entry per recipient user).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDocumentShares(int $documentId): array {
        $shares = $this->shareMapper->findByDocumentId($documentId);

        $aggregated = [];
        foreach ($shares as $share) {
            $uid = $share->getSharedWith();
            if (!isset($aggregated[$uid])) {
                $user = $this->userManager->get($uid);
                $aggregated[$uid] = [
                    'shared_with'  => $uid,
                    'display_name' => $user?->getDisplayName() ?? $uid,
                    'can_write'    => false,
                    'owner_id'     => $share->getOwnerId(),
                    'created_at'   => $share->getCreatedAt()?->format('c'),
                    'expires_at'   => $share->getExpiresAt()?->format('c'),
                    'note'         => $share->getNote(),
                ];
            }
            if ($share->canWrite()) {
                $aggregated[$uid]['can_write'] = true;
            }
        }

        return array_values($aggregated);
    }

    /**
     * Send a Nextcloud notification to the share recipient.
     */
    private function sendShareNotification(int $fileId, string $recipient, string $sharer, string $filename): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification
                ->setApp('opencase')
                ->setUser($recipient)
                ->setDateTime(new \DateTime())
                ->setObject('file', (string)$fileId)
                ->setSubject('file_shared', [
                    'filename' => $filename,
                    'sharer'   => $sharer,
                ]);
            $this->notificationManager->notify($notification);
        } catch (\InvalidArgumentException) {
            // swallow — notification is best-effort
        }
    }

    /**
     * Serialize a FileShareEntity to an API-safe array.
     *
     * @return array<string, mixed>
     */
    public function toArray(FileShareEntity $share): array {
        $user = $this->userManager->get($share->getSharedWith());
        return [
            'id'           => $share->getId(),
            'file_id'      => $share->getFileId(),
            'owner_id'     => $share->getOwnerId(),
            'shared_with'  => $share->getSharedWith(),
            'display_name' => $user?->getDisplayName() ?? $share->getSharedWith(),
            'can_write'    => $share->canWrite(),
            'permissions'  => $share->getPermissions(),
            'created_at'   => $share->getCreatedAt()?->format('c'),
            'expires_at'   => $share->getExpiresAt()?->format('c'),
            'note'         => $share->getNote(),
        ];
    }
}
