<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<FileVersionEntity>
 */
class FileVersionMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_fileversions', FileVersionEntity::class);
    }

    /**
     * Return all versions for a file, newest first.
     *
     * @return FileVersionEntity[]
     */
    public function findByFileId(int $fileId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->orderBy('timestamp', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Find a single version by its uuid.
     */
    public function findByUuid(string $uuid): ?FileVersionEntity {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));
        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return null;
        }
    }

    /**
     * Find a single version by its primary key.
     */
    public function findById(int $id): ?FileVersionEntity {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return null;
        }
    }

    /**
     * Delete all version records for a file (call when the file itself is deleted).
     */
    public function deleteByFileId(int $fileId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
