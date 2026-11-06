<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ProcessVersion>
 */
class ProcessVersionMapper extends QBMapper {

    /** Columns for version lists, without the (large) BPMN XML. */
    private const SUMMARY_COLUMNS = [
        'id', 'process_def_id', 'version_no', 'status', 'checksum', 'change_note',
        'flowable_deployment_id', 'flowable_proc_def_id', 'created_at', 'created_by',
        'updated_at', 'updated_by', 'published_at', 'published_by',
    ];

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_process_version', ProcessVersion::class);
    }

    public function find(int $id): ?ProcessVersion {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    public function findByFlowableProcDefId(string $procDefId): ?ProcessVersion {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('flowable_proc_def_id', $qb->createNamedParameter($procDefId)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * All versions of a process, newest first, without bpmn_xml.
     *
     * @return ProcessVersion[]
     */
    public function findByDefId(int $defId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(self::SUMMARY_COLUMNS)->from($this->getTableName())
            ->where($qb->expr()->eq('process_def_id', $qb->createNamedParameter($defId, IQueryBuilder::PARAM_INT)))
            ->orderBy('version_no', 'DESC');
        return $this->findEntities($qb);
    }

    /**
     * Version summaries (without bpmn_xml) for the given processes, grouped
     * by process_def_id, newest first.
     *
     * @param int[] $defIds
     * @return array<int, ProcessVersion[]>
     */
    public function findGroupedByDefIds(array $defIds): array {
        if (empty($defIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select(self::SUMMARY_COLUMNS)->from($this->getTableName())
            ->where($qb->expr()->in('process_def_id', $qb->createNamedParameter($defIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->orderBy('version_no', 'DESC');
        $grouped = [];
        foreach ($this->findEntities($qb) as $version) {
            $grouped[$version->getProcessDefId()][] = $version;
        }
        return $grouped;
    }

    public function findDraft(int $defId): ?ProcessVersion {
        return $this->findOneByStatus($defId, ProcessVersion::STATUS_DRAFT);
    }

    public function findPublished(int $defId): ?ProcessVersion {
        return $this->findOneByStatus($defId, ProcessVersion::STATUS_PUBLISHED);
    }

    public function maxVersionNo(int $defId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->max('version_no'))->from($this->getTableName())
            ->where($qb->expr()->eq('process_def_id', $qb->createNamedParameter($defId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $max = $result->fetchOne();
        $result->closeCursor();
        return (int)$max;
    }

    public function deleteByDefId(int $defId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('process_def_id', $qb->createNamedParameter($defId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    private function findOneByStatus(int $defId, string $status): ?ProcessVersion {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('process_def_id', $qb->createNamedParameter($defId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)))
            ->orderBy('version_no', 'DESC')
            ->setMaxResults(1);
        $entities = $this->findEntities($qb);
        return $entities[0] ?? null;
    }
}
