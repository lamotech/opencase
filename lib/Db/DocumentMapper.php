<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserSession;

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

    public function __construct(
        IDBConnection $db,
        private IUserSession $userSession,
    ) {
        parent::__construct($db, 'opencase_documents', Document::class);
    }

    /**
     * @param Document $entity
     * @return Document
     */
    public function insert(Entity $entity): Entity {
        if ($entity->getUpdatedBy() === null || $entity->getUpdatedBy() === '') {
            $entity->setUpdatedBy($entity->getCreatedBy());
        }
        return parent::insert($entity);
    }

    /**
     * Stamps updated_by with the logged-in user. Without a user session
     * (background jobs, occ) the previous value is kept, falling back to
     * created_by if it is empty.
     *
     * @param Document $entity
     * @return Document
     */
    public function update(Entity $entity): Entity {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid !== null && $uid !== '') {
            $entity->setUpdatedBy($uid);
        } elseif ($entity->getUpdatedBy() === null || $entity->getUpdatedBy() === '') {
            $entity->setUpdatedBy($entity->getCreatedBy());
        }
        return parent::update($entity);
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

    public function findByUuid(string $uuid): ?Document {
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

    public function findByDocumentNumber(string $documentNumber): ?Document {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('document_number', $qb->createNamedParameter($documentNumber)));

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
     * Soft-deleted documents are left out unless $includeDeleted is set.
     *
     * @return Document[]
     */
    public function findByCase(int $caseId, bool $includeDeleted = false): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'DESC');

        if (!$includeDeleted) {
            $qb->andWhere($this->notDeleted($qb));
        }

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
            ->andWhere($this->notDeleted($qb))
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
            ->andWhere($this->notDeleted($qb))
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
            ->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($this->notDeleted($qb));

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
            ))
            ->andWhere($this->notDeleted($qb));

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
     * Search documents by title, scoped to every case the user can reach.
     *
     * @return array[]  rows with keys: id, title, document_type, case_id, case_number, case_title
     */
    public function searchByTitle(string $query, string $userId, int $limit, int $offset, ?string $documentType = null): array {
        if (trim($query) === '') {
            return [];
        }

        $qb        = $this->db->getQueryBuilder();
        $likeParam = $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($query) . '%');
        $accessExpr = $qb->expr()->orX(...$this->buildAccessOr($qb, $userId));

        $qb->select('d.id', 'd.title', 'd.document_type', 'd.case_id')
            ->selectAlias('c.case_number', 'case_number')
            ->selectAlias('c.title', 'case_title')
            ->selectAlias('c.year', 'case_year')
            ->selectAlias('c.org_uuid', 'org_uuid')
            ->selectAlias('c.casetype_id', 'casetype_id')
            ->from('opencase_documents', 'd')
            ->innerJoin('d', 'opencase_cases', 'c', $qb->expr()->eq('c.id', 'd.case_id'))
            ->where($accessExpr)
            ->andWhere($qb->expr()->eq('c.is_inbox', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere($this->notDeleted($qb, 'd'))
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
            ->andWhere($this->notDeleted($qb, 'd'))
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
            ))
            ->andWhere($this->notDeleted($qb));

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
            ->andWhere($this->notDeleted($qb))
            ->orderBy('updated_at', 'DESC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    /**
     * Field-based document search across all accessible cases.
     *
     * All filter parameters are optional.
     *
     * @param string|null $title matches against document title or document number (ILIKE)
     * @return array[]    raw rows with keys: id, case_id, case_number, case_title,
     *                    document_number, title, document_type, status, document_date,
     *                    received_date, created_at, updated_at, org_name
     */
    public function findByFields(
        string $userId,
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
        $qb         = $this->db->getQueryBuilder();
        $accessExpr = $qb->expr()->orX(...$this->buildAccessOr($qb, $userId));

        $qb->select('d.id', 'd.uuid', 'd.title', 'd.document_type', 'd.status', 'd.document_date',
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
            ->andWhere($this->notDeleted($qb, 'd'))
            ->orderBy('d.updated_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($title !== null && trim($title) !== '') {
            $likeParam = $qb->createNamedParameter('%' . $this->db->escapeLikeParameter(trim($title)) . '%');
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->iLike('d.title', $likeParam),
                $qb->expr()->iLike('d.document_number', $likeParam),
            ));
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
     */
    public function countByFields(
        string $userId,
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
        $qb         = $this->db->getQueryBuilder();
        $accessExpr = $qb->expr()->orX(...$this->buildAccessOr($qb, $userId));

        $qb->select($qb->func()->count('d.id', 'cnt'))
            ->from('opencase_documents', 'd')
            ->innerJoin('d', 'opencase_cases', 'c', $qb->expr()->eq('c.id', 'd.case_id'))
            ->leftJoin('c', 'opencase_org', 'o', $qb->expr()->eq('o.org_uuid', 'c.org_uuid'))
            ->where($accessExpr)
            ->andWhere($qb->expr()->eq('c.is_inbox', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere($this->notDeleted($qb, 'd'));

        if ($title !== null && trim($title) !== '') {
            $likeParam = $qb->createNamedParameter('%' . $this->db->escapeLikeParameter(trim($title)) . '%');
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->iLike('d.title', $likeParam),
                $qb->expr()->iLike('d.document_number', $likeParam),
            ));
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

    // ---------------------------------------------------------------
    // Access scoping
    // ---------------------------------------------------------------

    /**
     * Build the OR conditions covering every path by which a user can reach a
     * document, via its parent case.
     *
     * These previously arrived as two pre-resolved ID lists inlined into
     * IN (…) clauses: every access profile the user held, and every case they
     * could reach directly. Both were unbounded — at 1000 organisations and
     * 2000 KLE subjects the profile list runs to six figures, and the direct
     * case list is every case the user is responsible for. Building them cost
     * two extra round trips (one of which scanned opencase_cases), the SQL
     * text grew into the megabytes, and the optimiser gave up on the index.
     *
     * Resolving all three paths as correlated subqueries keeps the work in the
     * database. Each is served by an existing unique index:
     *   - oc_ua_user_profile  (user_id, access_profile_id)
     *   - oc_uniq_case_user   (case_id, user_id)
     * and responsible_user_id is indexed directly.
     *
     * Named parameters are registered on the OUTER builder so they bind to the
     * statement that executes; only the SQL text comes from the sub-builders.
     *
     * Requires the outer query to alias opencase_cases as 'c'.
     *
     * @return \OCP\DB\QueryBuilder\ICompositeExpression[]|string[]
     */
    private function buildAccessOr(IQueryBuilder $qb, string $userId): array {
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        // Profile-based access
        $profiles = $this->db->getQueryBuilder();
        $profiles->select($profiles->expr()->literal(1))
            ->from('opencase_user_access', 'ua')
            ->where($profiles->expr()->eq('ua.access_profile_id', 'c.access_profile_id'))
            ->andWhere($profiles->expr()->eq('ua.user_id', $qb->createNamedParameter($userId)));

        // Active direct grant on the case
        $grants = $this->db->getQueryBuilder();
        $grants->select($grants->expr()->literal(1))
            ->from('opencase_case_users', 'cu')
            ->where($grants->expr()->eq('cu.case_id', 'c.id'))
            ->andWhere($grants->expr()->eq('cu.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($grants->expr()->orX(
                $grants->expr()->isNull('cu.expires_at'),
                $grants->expr()->gt('cu.expires_at', $qb->createNamedParameter($now)),
            ));

        return [
            // Responsible user — indexed column
            $qb->expr()->eq('c.responsible_user_id', $qb->createNamedParameter($userId)),
            'EXISTS (' . $grants->getSQL() . ')',
            'EXISTS (' . $profiles->getSQL() . ')',
        ];
    }

    // ---------------------------------------------------------------
    // Soft delete
    // ---------------------------------------------------------------

    /**
     * Of the given document IDs, those that still exist and are not
     * soft-deleted.
     *
     * @param int[] $documentIds
     * @return int[]
     */
    public function filterLiveIds(array $documentIds): array {
        if ($documentIds === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->in('id', $qb->createNamedParameter(array_values($documentIds), IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($this->notDeleted($qb));

        $result = $qb->executeQuery();
        $ids = [];
        while (($id = $result->fetchOne()) !== false) {
            $ids[] = (int)$id;
        }
        $result->closeCursor();

        return $ids;
    }

    /**
     * Set (or clear) updated_in_sapa directly, without going through update(),
     * so updated_by/updated_at are left alone.
     */
    public function setUpdatedInSapa(string $uuid, ?\DateTime $time): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('updated_in_sapa', $qb->createNamedParameter($time, IQueryBuilder::PARAM_DATE))
            ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)))
            ->executeStatement();
    }

    /**
     * Flag a document as deleted (is_deleted=1, deleted_date=now).
     */
    public function markDeleted(int $documentId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('is_deleted', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
            ->set('deleted_date', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }

    /**
     * Clear the soft-delete flag (is_deleted=0, deleted_date=NULL).
     */
    public function markRestored(int $documentId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('is_deleted', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
            ->set('deleted_date', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }

    /**
     * WHERE clause matching documents that are not soft-deleted.
     */
    public function notDeleted(IQueryBuilder $qb, string $alias = ''): \OCP\DB\QueryBuilder\ICompositeExpression {
        $column = ($alias !== '' ? $alias . '.' : '') . 'is_deleted';
        return $qb->expr()->orX(
            $qb->expr()->isNull($column),
            $qb->expr()->eq($column, $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
        );
    }
}
