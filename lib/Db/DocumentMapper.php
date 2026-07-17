<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for the opencase_documents table.
 *
 * Documents belong to a case and serve as metadata containers for files.
 * A document might have multiple file versions or multiple related files.
 *
 * Expected scale: ~10 million documents.
 *
 * @extends QBMapper<Document>
 */
class DocumentMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_documents', Document::class);
    }

    // ---------------------------------------------------------------
    // Single-entity lookups
    // ---------------------------------------------------------------

    public function findById(int $id): ?Document {
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

    // ---------------------------------------------------------------
    // Case-scoped queries
    // ---------------------------------------------------------------

    /**
     * Get all documents belonging to a case.
     *
     * @return Document[]
     */
    public function findByCase(int $caseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Get documents for a case filtered by status.
     *
     * @return Document[]
     */
    public function findByCaseAndStatus(int $caseId, int $status): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Get documents for a case filtered by type.
     *
     * @return Document[]
     */
    public function findByCaseAndType(int $caseId, string $documentType): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('document_type', $qb->createNamedParameter($documentType)))
            ->orderBy('created_at', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Count documents in a case (for UI display without loading all entities).
     */
    public function countByCase(int $caseId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();

        return $count;
    }

    // ---------------------------------------------------------------
    // Queries used by other services
    // ---------------------------------------------------------------

    /**
     * Get the IDs of all documents in a set of cases.
     * Used by the permission service for batch filtering.
     *
     * @param int[] $caseIds
     * @return int[]
     */
    public function getDocumentIdsByCases(array $caseIds): array {
        if (empty($caseIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->in(
                'case_id',
                $qb->createNamedParameter($caseIds, IQueryBuilder::PARAM_INT_ARRAY)
            ));

        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['id'];
        }
        $result->closeCursor();

        return $ids;
    }

    /**
     * Get recently updated documents across accessible cases.
     * Used for "recent documents" UI widget.
     *
     * @param int[] $accessibleCaseIds
     * @return Document[]
     */
    /**
     * Search documents by title, filtered to the user's access profiles.
     *
     * @param int[] $accessProfileIds
     * @return array[]  rows with keys: id, title, document_type, case_id, case_number, case_title
     */
    public function searchByTitle(string $query, array $accessProfileIds, int $limit, int $offset, array $directCaseIds = [], ?string $documentType = null): array {
        if ((empty($accessProfileIds) && empty($directCaseIds)) || trim($query) === '') {
            return [];
        }

        $qb        = $this->db->getQueryBuilder();
        $likeParam = $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($query) . '%');

        $accessOr = [];
        if (!empty($accessProfileIds)) {
            $accessOr[] = $qb->expr()->in(
                'c.access_profile_id',
                $qb->createNamedParameter($accessProfileIds, IQueryBuilder::PARAM_INT_ARRAY)
            );
        }
        if (!empty($directCaseIds)) {
            $accessOr[] = $qb->expr()->in(
                'd.case_id',
                $qb->createNamedParameter($directCaseIds, IQueryBuilder::PARAM_INT_ARRAY)
            );
        }
        $accessExpr = count($accessOr) === 1 ? $accessOr[0] : $qb->expr()->orX(...$accessOr);

        $qb->select('d.id', 'd.title', 'd.document_type', 'd.case_id')
            ->selectAlias('c.case_number', 'case_number')
            ->selectAlias('c.title', 'case_title')
            ->selectAlias('c.year', 'case_year')
            ->selectAlias('c.org_uuid', 'org_uuid')
            ->from('opencase_documents', 'd')
            ->innerJoin('d', 'opencase_cases', 'c', $qb->expr()->eq('c.id', 'd.case_id'))
            ->where($accessExpr)
            ->andWhere($qb->expr()->eq('c.is_inbox', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->iLike('d.title', $likeParam))
            ->orderBy('d.updated_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($documentType !== null && $documentType !== '') {
            $qb->andWhere($qb->expr()->eq('d.document_type', $qb->createNamedParameter($documentType)));
        }

        $result = $qb->executeQuery();
        $rows   = [];
        while ($row = $result->fetch()) {
            $rows[] = $row;
        }
        $result->closeCursor();

        return $rows;
    }

    /**
     * List documents from inbox cases, joined with case and org info.
     *
     * @param int[] $inboxCaseIds  IDs of inbox cases the user can access
     * @return array[]  raw rows with keys: id, case_id, case_title, org_name, title,
     *                  document_type, document_number, received_date, created_at
     */
    public function findFromInboxCases(array $inboxCaseIds, int $limit = 50, int $offset = 0): array {
        if (empty($inboxCaseIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('d.*')
            ->selectAlias('c.title', 'case_title')
            ->selectAlias('o.org_name', 'org_name')
            ->from($this->getTableName(), 'd')
            ->innerJoin('d', 'opencase_cases', 'c', $qb->expr()->eq('c.id', 'd.case_id'))
            ->leftJoin('c', 'opencase_org', 'o', $qb->expr()->eq('o.org_uuid', 'c.org_uuid'))
            ->where($qb->expr()->in(
                'd.case_id',
                $qb->createNamedParameter($inboxCaseIds, IQueryBuilder::PARAM_INT_ARRAY)
            ))
            ->orderBy('d.created_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $result = $qb->executeQuery();
        $rows   = [];
        while ($row = $result->fetch()) {
            $rows[] = $row;
        }
        $result->closeCursor();
        return $rows;
    }

    /**
     * Count documents across the given inbox case IDs.
     *
     * @param int[] $inboxCaseIds
     */
    public function countFromInboxCases(array $inboxCaseIds): int {
        if (empty($inboxCaseIds)) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->in(
                'case_id',
                $qb->createNamedParameter($inboxCaseIds, IQueryBuilder::PARAM_INT_ARRAY)
            ));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();
        return (int)($row['cnt'] ?? 0);
    }

    public function findRecentlyUpdated(array $accessibleCaseIds, int $limit = 20): array {
        if (empty($accessibleCaseIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in(
                'case_id',
                $qb->createNamedParameter($accessibleCaseIds, IQueryBuilder::PARAM_INT_ARRAY)
            ))
            ->orderBy('updated_at', 'DESC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    /**
     * Field-based document search across all accessible cases.
     *
     * All filter parameters are optional; at least one of accessProfileIds
     * or directCaseIds must be non-empty (enforced by the access model).
     *
     * @param int[]       $accessProfileIds
     * @param int[]       $directCaseIds
     * @return array[]    raw rows with keys: id, case_id, case_number, case_title,
     *                    document_number, title, document_type, status, document_date,
     *                    received_date, created_at, updated_at, org_name
     */
    public function findByFields(
        array $accessProfileIds,
        array $directCaseIds,
        ?string $title = null,
        ?string $documentType = null,
        ?int $status = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $orgName = null,
        int $limit = 50,
        int $offset = 0,
        ?int $documentCategoryId = null,
        ?int $insightLevelId = null,
        ?string $createdBy = null,
    ): array {
        if (empty($accessProfileIds) && empty($directCaseIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();

        $accessOr = [];
        if (!empty($accessProfileIds)) {
            $accessOr[] = $qb->expr()->in(
                'c.access_profile_id',
                $qb->createNamedParameter($accessProfileIds, IQueryBuilder::PARAM_INT_ARRAY)
            );
        }
        if (!empty($directCaseIds)) {
            $accessOr[] = $qb->expr()->in(
                'd.case_id',
                $qb->createNamedParameter($directCaseIds, IQueryBuilder::PARAM_INT_ARRAY)
            );
        }
        $accessExpr = count($accessOr) === 1 ? $accessOr[0] : $qb->expr()->orX(...$accessOr);

        $qb->select('d.id', 'd.title', 'd.document_type', 'd.status', 'd.document_date',
                    'd.received_date', 'd.created_at', 'd.updated_at', 'd.created_by',
                    'd.case_id', 'd.document_number')
            ->selectAlias('c.case_number', 'case_number')
            ->selectAlias('c.title', 'case_title')
            ->selectAlias('c.year', 'case_year')
            ->selectAlias('o.org_name', 'org_name')
            ->from('opencase_documents', 'd')
            ->innerJoin('d', 'opencase_cases', 'c', $qb->expr()->eq('c.id', 'd.case_id'))
            ->leftJoin('c', 'opencase_org', 'o', $qb->expr()->eq('o.org_uuid', 'c.org_uuid'))
            ->where($accessExpr)
            ->andWhere($qb->expr()->eq('c.is_inbox', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->orderBy('d.updated_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($title !== null && trim($title) !== '') {
            $likeParam = $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($title) . '%');
            $qb->andWhere($qb->expr()->iLike('d.title', $likeParam));
        }
        if ($documentType !== null && $documentType !== '') {
            $qb->andWhere($qb->expr()->eq('d.document_type', $qb->createNamedParameter($documentType)));
        }
        if ($status !== null) {
            $qb->andWhere($qb->expr()->eq('d.status', $qb->createNamedParameter($status, IQueryBuilder::PARAM_INT)));
        }
        if ($dateFrom !== null && $dateFrom !== '') {
            $qb->andWhere($qb->expr()->gte('d.document_date', $qb->createNamedParameter($dateFrom)));
        }
        if ($dateTo !== null && $dateTo !== '') {
            $qb->andWhere($qb->expr()->lte('d.document_date', $qb->createNamedParameter($dateTo)));
        }
        if ($orgName !== null && $orgName !== '') {
            $qb->andWhere($qb->expr()->eq('o.org_name', $qb->createNamedParameter($orgName)));
        }
        if ($documentCategoryId !== null) {
            $qb->andWhere($qb->expr()->eq('d.document_category_id', $qb->createNamedParameter($documentCategoryId, IQueryBuilder::PARAM_INT)));
        }
        if ($insightLevelId !== null) {
            $qb->andWhere($qb->expr()->eq('d.insight_level_id', $qb->createNamedParameter($insightLevelId, IQueryBuilder::PARAM_INT)));
        }
        if ($createdBy !== null && $createdBy !== '') {
            $qb->andWhere($qb->expr()->eq('d.created_by', $qb->createNamedParameter($createdBy)));
        }

        $result = $qb->executeQuery();
        $rows   = [];
        while ($row = $result->fetch()) {
            $rows[] = $row;
        }
        $result->closeCursor();
        return $rows;
    }

    /**
     * Count documents matching the same field-based filters as findByFields.
     *
     * @param int[] $accessProfileIds
     * @param int[] $directCaseIds
     */
    public function countByFields(
        array $accessProfileIds,
        array $directCaseIds,
        ?string $title = null,
        ?string $documentType = null,
        ?int $status = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $orgName = null,
        ?int $documentCategoryId = null,
        ?int $insightLevelId = null,
        ?string $createdBy = null,
    ): int {
        if (empty($accessProfileIds) && empty($directCaseIds)) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();

        $accessOr = [];
        if (!empty($accessProfileIds)) {
            $accessOr[] = $qb->expr()->in(
                'c.access_profile_id',
                $qb->createNamedParameter($accessProfileIds, IQueryBuilder::PARAM_INT_ARRAY)
            );
        }
        if (!empty($directCaseIds)) {
            $accessOr[] = $qb->expr()->in(
                'd.case_id',
                $qb->createNamedParameter($directCaseIds, IQueryBuilder::PARAM_INT_ARRAY)
            );
        }
        $accessExpr = count($accessOr) === 1 ? $accessOr[0] : $qb->expr()->orX(...$accessOr);

        $qb->select($qb->func()->count('d.id', 'cnt'))
            ->from('opencase_documents', 'd')
            ->innerJoin('d', 'opencase_cases', 'c', $qb->expr()->eq('c.id', 'd.case_id'))
            ->leftJoin('c', 'opencase_org', 'o', $qb->expr()->eq('o.org_uuid', 'c.org_uuid'))
            ->where($accessExpr)
            ->andWhere($qb->expr()->eq('c.is_inbox', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

        if ($title !== null && trim($title) !== '') {
            $likeParam = $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($title) . '%');
            $qb->andWhere($qb->expr()->iLike('d.title', $likeParam));
        }
        if ($documentType !== null && $documentType !== '') {
            $qb->andWhere($qb->expr()->eq('d.document_type', $qb->createNamedParameter($documentType)));
        }
        if ($status !== null) {
            $qb->andWhere($qb->expr()->eq('d.status', $qb->createNamedParameter($status, IQueryBuilder::PARAM_INT)));
        }
        if ($dateFrom !== null && $dateFrom !== '') {
            $qb->andWhere($qb->expr()->gte('d.document_date', $qb->createNamedParameter($dateFrom)));
        }
        if ($dateTo !== null && $dateTo !== '') {
            $qb->andWhere($qb->expr()->lte('d.document_date', $qb->createNamedParameter($dateTo)));
        }
        if ($orgName !== null && $orgName !== '') {
            $qb->andWhere($qb->expr()->eq('o.org_name', $qb->createNamedParameter($orgName)));
        }
        if ($documentCategoryId !== null) {
            $qb->andWhere($qb->expr()->eq('d.document_category_id', $qb->createNamedParameter($documentCategoryId, IQueryBuilder::PARAM_INT)));
        }
        if ($insightLevelId !== null) {
            $qb->andWhere($qb->expr()->eq('d.insight_level_id', $qb->createNamedParameter($insightLevelId, IQueryBuilder::PARAM_INT)));
        }
        if ($createdBy !== null && $createdBy !== '') {
            $qb->andWhere($qb->expr()->eq('d.created_by', $qb->createNamedParameter($createdBy)));
        }

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();
        return (int)($row['cnt'] ?? 0);
    }
}
