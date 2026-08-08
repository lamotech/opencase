<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\UserInfoMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The admin "Transaktionslog" view.
 *
 * Two tables feed it:
 *
 *   opencase_transaction_log — search/lookup transactions (search_case,
 *       show_address_protection, …) written by this service's log().
 *   opencase_audit_log       — the case/document audit trail (case_viewed,
 *       workflow_step_approved, …) written by AuditService.
 *
 * search() normalises both into one row shape so an administrator can answer
 * "what did user X do" in a single query, with `source` telling the rows
 * apart. Audit rows keep their case/document/file references. The user search
 * term is resolved to account uids first — see resolveUserIds().
 */
class TransactionLogService {

    private const MAX_RESULTS = 1000;

    // Upper bound on how many users one name search may expand to before the
    // resulting IN () clause gets unreasonable.
    private const MAX_USER_MATCHES = 500;

    public function __construct(
        private IDBConnection $db,
        private UserInfoMapper $userInfoMapper,
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

    /**
     * Search both logs, newest first.
     *
     * @param string|null $username free-text user search — see resolveUserIds()
     *                              for how it is matched.
     * @param string|null $source 'transaction' or 'audit' to restrict to one
     *                            log; null/'' searches both.
     * @return list<array<string,mixed>>
     */
    public function search(?string $username, ?string $from, ?string $to, ?string $type, ?string $source = null): array {
        $userIds = ($username !== null && $username !== '')
            ? $this->resolveUserIds($username)
            : null;

        $rows = [];

        if ($source !== 'audit') {
            $rows = array_merge($rows, $this->searchTransactions($username, $userIds, $from, $to, $type));
        }
        if ($source !== 'transaction') {
            $rows = array_merge($rows, $this->searchAuditLog($username, $userIds, $from, $to, $type));
        }

        // Both queries are already sorted, but the merge interleaves them.
        usort($rows, static fn ($a, $b) => $b['transaction_time'] <=> $a['transaction_time']);

        return array_slice($rows, 0, self::MAX_RESULTS);
    }

    /**
     * @param list<string>|null $userIds
     * @return list<array<string,mixed>>
     */
    private function searchTransactions(?string $username, ?array $userIds, ?string $from, ?string $to, ?string $type): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'user_id', 'transaction_time', 'transaction_type', 'details')
            ->from('opencase_transaction_log')
            ->orderBy('transaction_time', 'DESC')
            ->setMaxResults(self::MAX_RESULTS);

        $this->applyFilters($qb, 'user_id', 'transaction_time', 'transaction_type', $username, $userIds, $from, $to, $type);

        $result = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $result[] = [
                'id'               => (int)$row['id'],
                'source'           => 'transaction',
                'user_id'          => $row['user_id'],
                'transaction_time' => $row['transaction_time'],
                'transaction_type' => $row['transaction_type'],
                'details'          => $row['details'],
                'case_id'          => null,
                'document_id'      => null,
                'file_id'          => null,
            ];
        }
        return $result;
    }

    /**
     * @param list<string>|null $userIds
     * @return list<array<string,mixed>>
     */
    private function searchAuditLog(?string $username, ?array $userIds, ?string $from, ?string $to, ?string $type): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'user_id', 'created_at', 'event_type', 'details', 'case_id', 'document_id', 'file_id')
            ->from('opencase_audit_log')
            ->orderBy('created_at', 'DESC')
            ->setMaxResults(self::MAX_RESULTS);

        $this->applyFilters($qb, 'user_id', 'created_at', 'event_type', $username, $userIds, $from, $to, $type);

        $result = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            // '[]' is what an eventless details payload is stored as — show it
            // as empty rather than as noise in the details column.
            $details = $row['details'];
            if ($details === '[]' || $details === '{}') {
                $details = null;
            }

            $result[] = [
                'id'               => (int)$row['id'],
                'source'           => 'audit',
                'user_id'          => $row['user_id'],
                'transaction_time' => $this->formatTimestamp($row['created_at']),
                'transaction_type' => $row['event_type'],
                'details'          => $details,
                'case_id'          => $row['case_id'] !== null ? (int)$row['case_id'] : null,
                'document_id'      => $row['document_id'] !== null ? (int)$row['document_id'] : null,
                'file_id'          => $row['file_id'] !== null ? (int)$row['file_id'] : null,
            ];
        }
        return $result;
    }

    /**
     * The type values actually present in the two logs, so the admin filter
     * only offers types that can return something.
     *
     * @return array{transaction: list<string>, audit: list<string>}
     */
    public function availableTypes(): array {
        return [
            'transaction' => $this->distinctValues('opencase_transaction_log', 'transaction_type'),
            'audit'       => $this->distinctValues('opencase_audit_log', 'event_type'),
        ];
    }

    /** @return list<string> */
    private function distinctValues(string $table, string $column): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct($column)
            ->from($table)
            ->orderBy($column, 'ASC');

        $values = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $value = (string)($row[$column] ?? '');
            if ($value !== '') {
                $values[] = $value;
            }
        }
        return $values;
    }

    /**
     * Both logs store user_id — a Nextcloud account uid. Administrators
     * search by whatever name they know, so the term is resolved in the order
     * the spec prescribes, each step only tried when the previous found
     * nothing:
     *
     *   1. users.uid                      — the account name itself
     *   2. opencase_userinfo.username     — the OpenCase profile username
     *   3. opencase_userinfo.personname   — the person's name
     *
     * If none of them match, the term is matched against the log's own
     * user_id (see applyFilters) so principals that have no Nextcloud account
     * — 'system', for instance — remain findable.
     *
     * @return list<string> the user ids to filter on, empty if unresolved
     */
    private function resolveUserIds(string $term): array {
        $uids = $this->findUidsLike($term);
        if ($uids !== []) {
            return $uids;
        }

        $byUsername = $this->userInfoMapper->findUserIdsByUsernameLike($term, self::MAX_USER_MATCHES);
        if ($byUsername !== []) {
            return $byUsername;
        }

        return $this->userInfoMapper->findUserIdsByPersonnameLike($term, self::MAX_USER_MATCHES);
    }

    /** @return list<string> */
    private function findUidsLike(string $term): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('uid')
            ->from('users')
            ->where($qb->expr()->iLike('uid', $qb->createNamedParameter('%' . $this->escapeLike($term) . '%')))
            ->setMaxResults(self::MAX_USER_MATCHES);

        $uids = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $uids[] = (string)$row['uid'];
        }
        return $uids;
    }

    /**
     * @param list<string>|null $userIds resolved accounts, or null when no
     *                                   user search was given
     */
    private function applyFilters(
        $qb,
        string $userColumn,
        string $timeColumn,
        string $typeColumn,
        ?string $username,
        ?array $userIds,
        ?string $from,
        ?string $to,
        ?string $type,
    ): void {
        if ($userIds !== null && $userIds !== []) {
            $qb->andWhere($qb->expr()->in($userColumn, $qb->createNamedParameter($userIds, IQueryBuilder::PARAM_STR_ARRAY)));
        } elseif ($username !== null && $username !== '') {
            $qb->andWhere($qb->expr()->iLike($userColumn, $qb->createNamedParameter('%' . $this->escapeLike($username) . '%')));
        }
        if ($from !== null && $from !== '') {
            $qb->andWhere($qb->expr()->gte($timeColumn, $qb->createNamedParameter($from)));
        }
        if ($to !== null && $to !== '') {
            $qb->andWhere($qb->expr()->lte($timeColumn, $qb->createNamedParameter($to)));
        }
        if ($type !== null && $type !== '') {
            $qb->andWhere($qb->expr()->eq($typeColumn, $qb->createNamedParameter($type)));
        }
    }

    private function escapeLike(string $value): string {
        return $this->db->escapeLikeParameter($value);
    }

    /**
     * opencase_audit_log.created_at is a real timestamp column, so the driver
     * may hand it back as a DateTime; the transaction log's is a string.
     * Normalise both to 'Y-m-d H:i:s' for sorting and display.
     */
    private function formatTimestamp(mixed $value): string {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        return (string)$value;
    }
}
