<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for the opencase_files table.
 *
 * Files are the physical attachments belonging to documents.
 * A document can have multiple files (e.g. main document + attachments,
 * or multiple versions).
 *
 * The mapper handles two key concerns:
 *   1. Virtual filename resolution for OpenCaseStorage
 *   2. Per-user access filtering (respecting document-level overrides)
 *
 * Expected scale: ~15 million files.
 *
 * @extends QBMapper<FileEntity>
 */
class FileMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_files', FileEntity::class);
    }

    // ---------------------------------------------------------------
    // Single-entity lookups
    // ---------------------------------------------------------------

    public function findById(int $id): ?FileEntity {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    public function findByUuid(string $uuid): ?FileEntity {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Find a file by its UUID-based storage filename.
     */
    public function findByStorageFilename(string $storageFilename): ?FileEntity {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('storage_filename', $qb->createNamedParameter($storageFilename)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    // ---------------------------------------------------------------
    // Virtual filesystem resolution
    //
    // These methods are the core interface used by OpenCaseStorage.
    // ---------------------------------------------------------------

    /**
     * Find all files within a case that the user can access.
     *
     * This joins through documents and checks for per-user document
     * access overrides (deny entries). Files whose parent document
     * has an explicit 'deny' override for this user are excluded.
     *
     * The result is a flat list of files — the virtual filesystem
     * shows all files in a case at the same level regardless of
     * which document they belong to.
     *
     * @return FileEntity[]
     */
    public function findByCaseForUser(int $caseId, string $userId): array {
        $qb = $this->db->getQueryBuilder();

        // Left join to document user access to detect deny overrides.
        // Files whose document has no override (NULL) pass through.
        // Files whose document has 'deny' for this user are excluded.
        $qb->select('f.*')
            ->from('opencase_files', 'f')
            ->innerJoin('f', 'opencase_documents', 'd',
                $qb->expr()->eq('d.id', 'f.document_id')
            )
            ->leftJoin('f', 'opencase_doc_user_access', 'dua',
                $qb->expr()->andX(
                    $qb->expr()->eq('dua.document_id', 'f.document_id'),
                    $qb->expr()->eq('dua.user_id', $qb->createNamedParameter($userId))
                )
            )
            ->where($qb->expr()->eq('f.case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('dua.access_level'),             // No override → inherits case access
                $qb->expr()->neq('dua.access_level', $qb->createNamedParameter('deny'))  // Override is not deny
            ))
            // Documents newest-first (d.id is auto-increment, same order as document_number sequence).
            // Within each document, files are sorted by original filename ascending.
            ->orderBy('d.id', 'DESC')
            ->addOrderBy('f.original_filename', 'ASC');

        $result = $qb->executeQuery();
        $entities = [];
        while ($row = $result->fetch()) {
            $entity = FileEntity::fromRow($row);
            $entities[] = $entity;
        }
        $result->closeCursor();

        return $entities;
    }

    /**
     * Find a specific file within a case by its virtual filename.
     *
     * Used by OpenCaseStorage to resolve the deepest path component.
     * Also checks document-level deny overrides.
     */
    public function findByVirtualName(int $caseId, string $virtualFilename, string $userId): ?FileEntity {
        $qb = $this->db->getQueryBuilder();

        $qb->select('f.*')
            ->from('opencase_files', 'f')
            ->leftJoin('f', 'opencase_doc_user_access', 'dua',
                $qb->expr()->andX(
                    $qb->expr()->eq('dua.document_id', 'f.document_id'),
                    $qb->expr()->eq('dua.user_id', $qb->createNamedParameter($userId))
                )
            )
            ->where($qb->expr()->eq('f.case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('f.virtual_filename', $qb->createNamedParameter($virtualFilename)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('dua.access_level'),
                $qb->expr()->neq('dua.access_level', $qb->createNamedParameter('deny'))
            ));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            return null;
        }

        return FileEntity::fromRow($row);
    }

    // ---------------------------------------------------------------
    // Unfiltered case-scoped queries (no user access guard)
    //
    // Used by OpenCaseStorage (shared storage). Access control is
    // enforced by the permission wrapper, not here.
    // ---------------------------------------------------------------

    /**
     * Get all files belonging to a case, regardless of user access.
     *
     * @return FileEntity[]
     */
    public function findByCaseId(int $caseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
            ->orderBy('virtual_filename', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find a file by virtual name within a case, without user access filtering.
     *
     * Used by OpenCaseStorage for path resolution; the permission wrapper
     * enforces access before any data is returned to the user.
     */
    public function findByVirtualNameNoAuth(int $caseId, string $virtualFilename): ?FileEntity {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('virtual_filename', $qb->createNamedParameter($virtualFilename)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return $row !== false ? FileEntity::fromRow($row) : null;
    }

    // ---------------------------------------------------------------
    // Document-scoped queries
    // ---------------------------------------------------------------

    /**
     * Get all files belonging to a document.
     *
     * @return FileEntity[]
     */
    public function findByDocument(int $documentId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('document_id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)))
            ->orderBy('virtual_filename', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Count files in a document.
     */
    public function countByDocument(int $documentId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('document_id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();

        return $count;
    }

    // ---------------------------------------------------------------
    // Metadata updates (called by OpenCaseStorage after file writes)
    // ---------------------------------------------------------------

    /**
     * Update file size and modification time after a write operation.
     * Called by OpenCaseStorage.file_put_contents() and the WOPI save flow.
     */
    public function updateFileStats(int $fileId, int $size, int $mtime): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('size', $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter(
                (new \DateTime())->setTimestamp($mtime),
                IQueryBuilder::PARAM_DATE
            ))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }

    /**
     * Update checksum after computing hash of saved file.
     */
    public function updateChecksum(int $fileId, string $checksum): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('checksum', $qb->createNamedParameter($checksum))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }

    /**
     * Increment the version number of a file (after a new version is saved).
     */
    public function incrementVersion(int $fileId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('version', $qb->createFunction('version + 1'))
            ->set('updated_at', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }

    // ---------------------------------------------------------------
    // Virtual filename collision handling
    // ---------------------------------------------------------------

    /**
     * Check if a virtual filename already exists within a case.
     * Used when adding a new file to ensure unique names in the
     * virtual directory listing.
     */
    public function virtualFilenameExists(int $caseId, string $virtualFilename): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('virtual_filename', $qb->createNamedParameter($virtualFilename)));

        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();

        return $count > 0;
    }

    /**
     * Generate a unique virtual filename within a case.
     *
     * If "Notat.docx" already exists, tries "Notat (2).docx",
     * "Notat (3).docx", etc.
     */
    public function generateUniqueVirtualFilename(int $caseId, string $desiredFilename): string {
        if (!$this->virtualFilenameExists($caseId, $desiredFilename)) {
            return $desiredFilename;
        }

        $pathInfo = pathinfo($desiredFilename);
        $baseName = $pathInfo['filename'];
        $extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';

        $counter = 2;
        do {
            $candidate = $baseName . ' (' . $counter . ')' . $extension;
            $counter++;
        } while ($this->virtualFilenameExists($caseId, $candidate));

        return $candidate;
    }

    // ---------------------------------------------------------------
    // Elasticsearch indexing support
    // ---------------------------------------------------------------

    /**
     * Get file with its parent document and case metadata.
     * Used by the ES indexing service to build the index document.
     *
     * @return array|null Associative array with file, document, and case fields
     */
    public function findWithMetadataForIndexing(int $fileId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(
                'f.id AS file_id',
                'f.document_id',
                'f.case_id',
                'f.original_filename',
                'f.storage_filename',
                'f.virtual_filename',
                'f.mime_type',
                'f.size',
                'f.created_at AS file_created_at',
                'f.updated_at AS file_updated_at',
                'd.title AS document_title',
                'd.document_type',
                'c.case_number',
                'c.title AS case_title',
                'o.org_name AS organisation',
                'c.year',
                'c.casetype_id',
                'c.access_profile_id',
            )
            ->from('opencase_files', 'f')
            ->innerJoin('f', 'opencase_documents', 'd', $qb->expr()->eq('d.id', 'f.document_id'))
            ->innerJoin('f', 'opencase_cases', 'c', $qb->expr()->eq('c.id', 'f.case_id'))
            ->leftJoin('c', 'opencase_org', 'o', $qb->expr()->eq('o.org_uuid', 'c.org_uuid'))
            ->where($qb->expr()->eq('f.id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return $row ?: null;
    }

    /**
     * Get all file IDs, paginated (used for full bulk re-indexing).
     *
     * @return int[]
     */
    public function getAllFileIds(int $limit, int $offset): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->orderBy('id', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['id'];
        }
        $result->closeCursor();

        return $ids;
    }

    /**
     * Get all file IDs for a case (used for bulk re-indexing).
     *
     * @return int[]
     */
    public function getFileIdsByCase(int $caseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['id'];
        }
        $result->closeCursor();

        return $ids;
    }
}
