<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class DpReceivementRepository {

    public function __construct(
        private IDBConnection $db,
    ) {}

    /**
     * Return all receivements with the given status, ordered by id ASC.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByStatus(string $status): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('opencase_dp_receivements')
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
        $qb->update('opencase_dp_receivements')
            ->set('status', $qb->createNamedParameter($status))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    public function insert(
        string $transaktionstid,
        string $transaktionsid,
        string $storageFilename,
        ?string $messageUuid,
        ?string $senderIdtype,
        ?string $senderId,
    ): int {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('opencase_dp_receivements')
            ->values([
                'transaktionstid'  => $qb->createNamedParameter($transaktionstid),
                'transaktionsid'   => $qb->createNamedParameter($transaktionsid),
                'storage_filename' => $qb->createNamedParameter($storageFilename),
                'message_uuid'     => $qb->createNamedParameter($messageUuid),
                'sender_idtype'    => $qb->createNamedParameter($senderIdtype),
                'sender_id'        => $qb->createNamedParameter($senderId),
                'status'           => $qb->createNamedParameter('Pending'),
                'received_at'      => $qb->createNamedParameter(new \DateTime(), 'datetime'),
            ])
            ->executeStatement();
        return (int)$this->db->lastInsertId('oc_opencase_dp_receivements');
    }
}
