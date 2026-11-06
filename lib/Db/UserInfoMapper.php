<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;

/**
 * @extends QBMapper<UserInfo>
 */
class UserInfoMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_userinfo', UserInfo::class);
    }

    public function findByUuid(string $uuid): ?UserInfo {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return null;
        }
    }

    public function findByUsername(string $username): ?UserInfo {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('username', $qb->createNamedParameter($username)))
            ->setMaxResults(1);
        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return null;
        }
    }

    public function findByUserId(string $userId): ?UserInfo {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->setMaxResults(1);
        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * User ids of the profiles whose OpenCase username contains $term.
     *
     * @return list<string>
     */
    public function findUserIdsByUsernameLike(string $term, int $limit = 500): array {
        return $this->findUserIdsWhereLike('username', $term, $limit);
    }

    /**
     * User ids of the profiles whose person name contains $term.
     *
     * @return list<string>
     */
    public function findUserIdsByPersonnameLike(string $term, int $limit = 500): array {
        return $this->findUserIdsWhereLike('personname', $term, $limit);
    }

    /**
     * @param string $column caller-supplied constant — never user input
     * @return list<string>
     */
    private function findUserIdsWhereLike(string $column, string $term, int $limit): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('user_id')
            ->from($this->getTableName())
            ->where($qb->expr()->iLike(
                $column,
                $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($term) . '%')
            ))
            ->andWhere($qb->expr()->isNotNull('user_id'))
            ->setMaxResults($limit);

        $userIds = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $userId = (string)($row['user_id'] ?? '');
            if ($userId !== '') {
                $userIds[] = $userId;
            }
        }
        return $userIds;
    }

    /**
     * The user's row, created from the Nextcloud account when missing (users
     * not provisioned from Serviceplatformen, e.g. Microsoft Entra ID logins).
     * An existing row is never changed.
     */
    public function ensureForUser(IUser $user): UserInfo {
        $existing = $this->findByUserId($user->getUID());
        if ($existing !== null) {
            return $existing;
        }
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        $this->upsert($uuid, $user->getUID(), $user->getDisplayName(), $user->getEMailAddress() ?? '', '', '', $user->getUID());
        return $this->findByUuid($uuid) ?? throw new \RuntimeException("Could not create user info for {$user->getUID()}");
    }

    public function upsert(string $uuid, string $username, string $personname, string $email, string $phone, string $location, string $userId): void {
        $existing = $this->findByUuid($uuid);

        if ($existing !== null) {
            $qb = $this->db->getQueryBuilder();
            $qb->update($this->getTableName())
                ->set('username', $qb->createNamedParameter($username))
                ->set('personname', $qb->createNamedParameter($personname))
                ->set('email', $qb->createNamedParameter($email))
                ->set('phone', $qb->createNamedParameter($phone))
                ->set('location', $qb->createNamedParameter($location))
                ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));
            $qb->executeStatement();
        } else {
            $qb = $this->db->getQueryBuilder();
            $qb->insert($this->getTableName())
                ->values([
                    'uuid'       => $qb->createNamedParameter($uuid),
                    'username'   => $qb->createNamedParameter($username),
                    'personname' => $qb->createNamedParameter($personname),
                    'email'      => $qb->createNamedParameter($email),
                    'phone'      => $qb->createNamedParameter($phone),
                    'location'   => $qb->createNamedParameter($location),
                    'user_id'    => $qb->createNamedParameter($userId),
                ]);
            $qb->executeStatement();
        }
    }
}
