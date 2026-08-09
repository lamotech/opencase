<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<FileShareEntity>
 */
class FileShareMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_file_shares', FileShareEntity::class);
    }

    /**
     * Find an active (non-expired) share for a specific file+recipient pair.
     * Returns null if not found or expired.
     */
    public function findActiveByFileAndUser(int $fileId, string $userId): ?FileShareEntity {
        $qb  = $this->db->getQueryBuilder();
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('shared_with', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('expires_at'),
                $qb->expr()->gt('expires_at', $qb->createNamedParameter($now)),
            ));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Return all shares for a file (including expired), newest first.
     *
     * @return FileShareEntity[]
     */
    public function findByFileId(int $fileId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Return all active shares for a file (non-expired).
     *
     * @return FileShareEntity[]
     */
    public function findActiveByFileId(int $fileId): array {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $qb  = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('expires_at'),
                $qb->expr()->gt('expires_at', $qb->createNamedParameter($now)),
            ))
            ->orderBy('created_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Return true if the user has at least one active file share.
     * Used by MountProvider to decide whether to mount OpenCase for this user.
     */
    public function userHasAnyActiveShare(string $userId): bool {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $qb  = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('shared_with', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('expires_at'),
                $qb->expr()->gt('expires_at', $qb->createNamedParameter($now)),
            ))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return $row !== false;
    }

    /**
     * Delete a share for a specific file+recipient pair.
     */
    public function deleteByFileAndUser(int $fileId, string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('shared_with', $qb->createNamedParameter($userId)));
        $qb->executeStatement();
    }

    /**
     * Delete all shares for a file (call when the file itself is deleted).
     */
    public function deleteByFileId(int $fileId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Return all active (non-expired) shares for a recipient user, newest first.
     *
     * @return FileShareEntity[]
     */
    public function findActiveByUser(string $userId): array {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $qb  = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('shared_with', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('expires_at'),
                $qb->expr()->gt('expires_at', $qb->createNamedParameter($now)),
            ))
            ->orderBy('created_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Return all shares for a document (by document_id), newest first.
     *
     * @return FileShareEntity[]
     */
    public function findByDocumentId(int $documentId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('document_id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Delete all file-share rows for a given document+recipient pair.
     * Used when revoking a document-level share.
     */
    public function deleteByDocumentAndUser(int $documentId, string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('document_id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('shared_with', $qb->createNamedParameter($userId)));
        $qb->executeStatement();
    }

    /**
     * Delete all shares owned by or shared with a deleted user.
     */
    public function deleteByUser(string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->orX(
                $qb->expr()->eq('owner_id', $qb->createNamedParameter($userId)),
                $qb->expr()->eq('shared_with', $qb->createNamedParameter($userId)),
            ));
        $qb->executeStatement();
    }
}
