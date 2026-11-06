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
     * Documents that arrived on the case since the given time: created
     * (also by inbound jobs) or moved there from another case. Oldest first.
     *
     * @return int[] document ids
     */
    public function findDocumentArrivals(int $caseId, \DateTime $since): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('document_id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->in('event_type', $qb->createNamedParameter(
                ['document_created', 'document_moved_to_case'],
                IQueryBuilder::PARAM_STR_ARRAY
            )))
            ->andWhere($qb->expr()->isNotNull('document_id'))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC');
        $result = $qb->executeQuery();
        $ids = [];
        while (($row = $result->fetch()) !== false) {
            $ids[] = (int)$row['document_id'];
        }
        $result->closeCursor();
        return array_values(array_unique($ids));
    }

    /**
     * Return case-level audit entries plus document created/deleted/restored events, newest first.
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
                $qb->expr()->in('event_type', $qb->createNamedParameter(
                    ['document_created', 'document_deleted', 'document_restored'],
                    IQueryBuilder::PARAM_STR_ARRAY
                ))
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
                $qb->expr()->in('event_type', $qb->createNamedParameter(
                    ['document_created', 'document_deleted', 'document_restored'],
                    IQueryBuilder::PARAM_STR_ARRAY
                ))
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
