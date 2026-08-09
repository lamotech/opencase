<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<DocWorkflowStepEntity>
 */
class DocWorkflowStepMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_doc_workflow_steps', DocWorkflowStepEntity::class);
    }

    public function findById(int $id): ?DocWorkflowStepEntity {
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
     * @return DocWorkflowStepEntity[]
     */
    public function findByWorkflow(int $workflowId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('workflow_id', $qb->createNamedParameter($workflowId, IQueryBuilder::PARAM_INT)))
            ->orderBy('sort_order', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Return all pending steps assigned to $userId in active workflows,
     * enriched with workflow type and document title.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findPendingByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(
            's.id AS step_id',
            's.workflow_id',
            's.sort_order',
            's.deadline AS step_deadline',
            'w.type AS workflow_type',
            'w.document_id',
            'd.title AS document_title',
        )
            ->from($this->getTableName(), 's')
            ->innerJoin('s', 'opencase_doc_workflows', 'w', $qb->expr()->eq('w.id', 's.workflow_id'))
            ->innerJoin('s', 'opencase_documents', 'd', $qb->expr()->eq('d.id', 'w.document_id'))
            ->where($qb->expr()->eq('s.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('s.status', $qb->createNamedParameter('pending')))
            ->andWhere($qb->expr()->eq('w.status', $qb->createNamedParameter('active')))
            ->orderBy('s.deadline', 'ASC');

        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            $rows[] = $row;
        }
        $result->closeCursor();
        return $rows;
    }

    public function findCurrentPendingStep(int $workflowId): ?DocWorkflowStepEntity {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('workflow_id', $qb->createNamedParameter($workflowId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
            ->orderBy('sort_order', 'ASC')
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }
}
