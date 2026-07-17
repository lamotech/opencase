<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper for the opencase_export_log table — one row per
 * ExportClosedCasesJob run, written by CaseExportService::exportPendingCases().
 *
 * @extends QBMapper<ExportLogEntry>
 */
class ExportLogMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_export_log', ExportLogEntry::class);
    }

    /**
     * Return the most recent export runs, newest first.
     *
     * @return ExportLogEntry[]
     */
    public function findRecent(int $limit = 5): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('sync_time', 'DESC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }
}
