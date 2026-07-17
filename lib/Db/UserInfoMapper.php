<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

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
