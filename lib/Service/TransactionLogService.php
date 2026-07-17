<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCP\IDBConnection;

class TransactionLogService {

    public function __construct(
        private IDBConnection $db,
    ) {}

    public function log(string $userId, string $transactionType, array $details = []): void {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('opencase_transaction_log')
                ->values([
                    'user_id'          => $qb->createNamedParameter($userId),
                    'transaction_time' => $qb->createNamedParameter(
                        (new \DateTime())->format('Y-m-d H:i:s')
                    ),
                    'transaction_type' => $qb->createNamedParameter($transactionType),
                    'details'          => $qb->createNamedParameter(
                        !empty($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : null
                    ),
                ])
                ->executeStatement();
        } catch (\Throwable) {
            // fire-and-forget: never abort a business operation because of logging
        }
    }

    /** @return list<array<string,mixed>> */
    public function search(?string $username, ?string $from, ?string $to, ?string $type): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'user_id', 'transaction_time', 'transaction_type', 'details')
            ->from('opencase_transaction_log')
            ->orderBy('transaction_time', 'DESC')
            ->setMaxResults(1000);

        if ($username !== null && $username !== '') {
            $qb->andWhere($qb->expr()->like('user_id', $qb->createNamedParameter('%' . $username . '%')));
        }
        if ($from !== null && $from !== '') {
            $qb->andWhere($qb->expr()->gte('transaction_time', $qb->createNamedParameter($from)));
        }
        if ($to !== null && $to !== '') {
            $qb->andWhere($qb->expr()->lte('transaction_time', $qb->createNamedParameter($to)));
        }
        if ($type !== null && $type !== '') {
            $qb->andWhere($qb->expr()->eq('transaction_type', $qb->createNamedParameter($type)));
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }
}
