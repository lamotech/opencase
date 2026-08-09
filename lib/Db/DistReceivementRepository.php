<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class DistReceivementRepository {

    public function __construct(
        private IDBConnection $db,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByStatus(string $status): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('opencase_dist_receivements')
            ->where($qb->expr()->eq('status', $qb->createNamedParameter($status)))
            ->orderBy('id', 'ASC');

        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            $rows[] = $row;
        }
        $result->closeCursor();
        return $rows;
    }

    public function updateStatus(int $id, string $status): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('opencase_dist_receivements')
            ->set('status', $qb->createNamedParameter($status))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    public function insert(
        string $transaktionsId,
        string $storageFilename,
        ?string $objekttype,
        ?string $afsendendeMyndighed,
    ): int {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('opencase_dist_receivements')
            ->values([
                'transaktions_id'      => $qb->createNamedParameter($transaktionsId),
                'storage_filename'     => $qb->createNamedParameter($storageFilename),
                'objekttype'           => $qb->createNamedParameter($objekttype),
                'afsendende_myndighed' => $qb->createNamedParameter($afsendendeMyndighed),
                'status'               => $qb->createNamedParameter('Pending'),
            ])
            ->executeStatement();
        return (int)$this->db->lastInsertId('oc_opencase_dist_receivements');
    }
}
