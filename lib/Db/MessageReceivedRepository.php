<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\IDBConnection;

/**
 * Persistence for inbound messages received via the public API.
 *
 * Each row represents one successfully parsed and stored event.
 * The status lifecycle is managed by downstream processing jobs:
 *   Received → Processing → Processed | Failed
 */
class MessageReceivedRepository {

    public function __construct(
        private IDBConnection $db,
    ) {}

    public function insert(string $cpr, string $personhaendelse): int {
        $now = new \DateTime();
        $qb = $this->db->getQueryBuilder();
        $qb->insert('opencase_messagesreceived')
            ->values([
                'cpr'               => $qb->createNamedParameter($cpr),
                'personhaendelse'   => $qb->createNamedParameter($personhaendelse),
                'received_at'       => $qb->createNamedParameter($now, 'datetime'),
                'status'            => $qb->createNamedParameter('Received'),
                'status_updated_at' => $qb->createNamedParameter($now, 'datetime'),
            ])
            ->executeStatement();
        return (int)$this->db->lastInsertId('oc_opencase_messagesreceived');
    }

    public function updateStatus(int $id, string $status): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('opencase_messagesreceived')
            ->set('status', $qb->createNamedParameter($status))
            ->set('status_updated_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->executeStatement();
    }

    public function findByStatus(string $status): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('opencase_messagesreceived')
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

    public function findAll(int $limit = 100, int $offset = 0): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('opencase_messagesreceived')
            ->orderBy('received_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);
        return $qb->executeQuery()->fetchAll();
    }
}
