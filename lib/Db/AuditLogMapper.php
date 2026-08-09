<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<AuditLogEntry>
 */
class AuditLogMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_audit_log', AuditLogEntry::class);
    }

    /**
     * Return case-level audit entries plus document_created events, newest first.
     *
     * @return AuditLogEntry[]
     */
    public function findByCase(int $caseId, int $limit = 25, int $offset = 0): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('document_id'),
                $qb->expr()->eq('event_type', $qb->createNamedParameter('document_created'))
            ))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);
        return $this->findEntities($qb);
    }

    public function countByCase(int $caseId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('document_id'),
                $qb->expr()->eq('event_type', $qb->createNamedParameter('document_created'))
            ));
        return (int) $qb->executeQuery()->fetchOne();
    }

    /**
     * Return all audit entries for a file, newest first.
     *
     * @return AuditLogEntry[]
     */
    public function findByFile(int $fileId, int $limit = 25, int $offset = 0): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);
        return $this->findEntities($qb);
    }

    public function countByFile(int $fileId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
        return (int) $qb->executeQuery()->fetchOne();
    }

    /**
     * Return all audit entries for a document, newest first.
     *
     * @return AuditLogEntry[]
     */
    public function findByDocument(int $documentId, int $limit = 25, int $offset = 0): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('document_id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);
        return $this->findEntities($qb);
    }

    public function countByDocument(int $documentId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('document_id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)));
        return (int) $qb->executeQuery()->fetchOne();
    }

    /**
     * Return every case-level audit entry (unpaginated), oldest first.
     * Unlike findByCase(), document-scoped entries are excluded entirely —
     * each document gets its own complete log via findAllByDocument().
     * Used by CaseExportService to write the full audit trail on export.
     *
     * @return AuditLogEntry[]
     */
    public function findAllByCase(int $caseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('document_id'))
            ->orderBy('created_at', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Return every audit entry for a document (unpaginated), oldest first.
     * Used by CaseExportService to write the full audit trail on export.
     *
     * @return AuditLogEntry[]
     */
    public function findAllByDocument(int $documentId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('document_id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'ASC');
        return $this->findEntities($qb);
    }
}
