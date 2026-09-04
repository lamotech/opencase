<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class ImportItemRepository {

    public function __construct(
        private IDBConnection $db,
    ) {}

    public function insert(int $importLocationId, string $identification, string $status): int {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('opencase_importitems')
            ->values([
                'importlocations_id' => $qb->createNamedParameter($importLocationId, IQueryBuilder::PARAM_INT),
                'identification'     => $qb->createNamedParameter($identification),
                'status'             => $qb->createNamedParameter($status),
            ])
            ->executeStatement();
        return (int)$this->db->lastInsertId('oc_opencase_importitems');
    }

    public function updateStatus(int $id, string $status): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('opencase_importitems')
            ->set('status', $qb->createNamedParameter($status));

        // Stamp the time the item finished importing whenever it reaches
        // "Processed" — the one place every successful import path sets
        // status, so it can't be missed by a caller forgetting a second call.
        if ($status === 'Processed') {
            $qb->set('imported_at', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE));
        }

        $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    public function setDocumentId(int $id, int $documentId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('opencase_importitems')
            ->set('document_id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    public function setFileStats(int $id, string $xml): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('opencase_importitems')
            ->set('file_stats', $qb->createNamedParameter($xml))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Return import items for a location from the last $days days, ordered
     * newest first. Items that never finished (no imported_at — still
     * "Processing" or "Failed") are always included, since without a
     * timestamp there's no way to know their age, and hiding failures from
     * the log would defeat its purpose.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findRecentByLocation(int $importLocationId, int $days = 30): array {
        $qb        = $this->db->getQueryBuilder();
        $threshold = new \DateTime("-{$days} days");

        $qb->select('*')
            ->from('opencase_importitems')
            ->where($qb->expr()->eq('importlocations_id', $qb->createNamedParameter($importLocationId, IQueryBuilder::PARAM_INT)))
            ->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->isNull('imported_at'),
                    $qb->expr()->gte('imported_at', $qb->createNamedParameter($threshold, IQueryBuilder::PARAM_DATE))
                )
            )
            ->orderBy('id', 'DESC');

        $result = $qb->executeQuery();
        $rows   = [];
        while ($row = $result->fetch()) {
            $rows[] = $row;
        }
        $result->closeCursor();
        return $rows;
    }

    /**
     * Whether an item with this identification has already been recorded for
     * this import location — used to skip mailbox attachments already seen
     * on a previous run (successfully or not), since emails aren't moved out
     * of the mailbox the way files are moved out of the source folder.
     */
    public function existsForLocation(int $importLocationId, string $identification): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('opencase_importitems')
            ->where($qb->expr()->eq('importlocations_id', $qb->createNamedParameter($importLocationId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('identification', $qb->createNamedParameter($identification)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return $row !== false;
    }
}
