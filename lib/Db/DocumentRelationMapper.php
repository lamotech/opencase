<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for the opencase_documentrelation table.
 *
 * @extends QBMapper<DocumentRelation>
 */
class DocumentRelationMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_documentrelation', DocumentRelation::class);
    }

    public function findById(int $id): ?DocumentRelation {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * All relations the document takes part in, in either direction.
     *
     * @return DocumentRelation[]
     */
    public function findByDocument(int $documentId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName(), 'r')
            ->where($qb->expr()->orX(
                $qb->expr()->eq('document_from_id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)),
                $qb->expr()->eq('document_to_id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)),
            ))
            ->andWhere($this->documentNotDeleted($qb, 'r.document_from_id'))
            ->andWhere($this->documentNotDeleted($qb, 'r.document_to_id'))
            ->orderBy('created_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Ids of documents the given document points to (it is the from-side)
     * with the given type — e.g. its attachments for type 1 (Bilag).
     *
     * @return int[]
     */
    public function findTargetDocumentIds(int $documentId, int $typeId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('document_to_id')
            ->from($this->getTableName(), 'r')
            ->where($qb->expr()->eq('document_from_id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('documentrelationtype_id', $qb->createNamedParameter($typeId, IQueryBuilder::PARAM_INT)))
            ->andWhere($this->documentNotDeleted($qb, 'r.document_to_id'))
            ->orderBy('id', 'ASC');

        $result = $qb->executeQuery();
        $ids = [];
        while (($id = $result->fetchOne()) !== false) {
            $ids[] = (int)$id;
        }
        $result->closeCursor();

        return $ids;
    }

    /**
     * Ids of documents already related to the given document with the given
     * type, in either direction.
     *
     * @return int[]
     */
    public function findRelatedDocumentIds(int $documentId, int $typeId): array {
        $ids = [];
        foreach ($this->findByDocument($documentId) as $r) {
            if ($r->getDocumentrelationtypeId() !== $typeId) {
                continue;
            }
            $ids[] = $r->getDocumentFromId() === $documentId ? $r->getDocumentToId() : $r->getDocumentFromId();
        }
        return $ids;
    }

    /**
     * WHERE clause leaving out relations where the document referenced by
     * $column (qualified with the outer alias) is soft-deleted.
     */
    private function documentNotDeleted(IQueryBuilder $qb, string $column): string {
        $sub = $this->db->getQueryBuilder();
        $sub->select($sub->expr()->literal(1))
            ->from('opencase_documents', 'dd')
            ->where($sub->expr()->eq('dd.id', $column))
            ->andWhere($sub->expr()->eq('dd.is_deleted', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

        return 'NOT EXISTS (' . $sub->getSQL() . ')';
    }

    /**
     * Delete every relation the document takes part in, in either direction
     * (used when it is hard-deleted).
     */
    public function deleteByDocument(int $documentId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->orX(
                $qb->expr()->eq('document_from_id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)),
                $qb->expr()->eq('document_to_id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)),
            ));
        $qb->executeStatement();
    }
}
