<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * opencase_userorgs: which organisations a user belongs to. A row's source is
 * 'privilege' (SAML token from Serviceplatformen, "Rettigheder", import) or
 * 'group' (member of the organisation's member group — see
 * OrganisationMembershipSyncService). The table is unique on
 * (user_id, org_uuid), so a user has one row per organisation; each source
 * only adds and removes its own rows.
 */
class UserOrgMapper {

    public const SOURCE_PRIVILEGE = 'privilege';
    public const SOURCE_GROUP     = 'group';

    public function __construct(
        private IDBConnection $db,
        private UserInfoMapper $userInfoMapper,
    ) {}

    /**
     * Return the org_uuid to use as default for $userUuid.
     *
     * Priority: first row with role='Sagsbehandler', then first row of any role.
     */
    public function findFirstOrgUuidForUser(string $userUuid): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('org_uuid', 'role')
            ->from('opencase_userorgs')
            ->where($qb->expr()->eq('user_uuid', $qb->createNamedParameter($userUuid)));
        $result = $qb->executeQuery();

        $firstAny = null;
        while ($row = $result->fetch()) {
            if ($row['role'] === 'Sagsbehandler') {
                $result->closeCursor();
                return (string)$row['org_uuid'];
            }
            if ($firstAny === null) {
                $firstAny = (string)$row['org_uuid'];
            }
        }
        $result->closeCursor();
        return $firstAny;
    }

    /**
     * All organisation units the user belongs to.
     *
     * @return string[]
     */
    public function findOrgUuidsForUser(string $userUuid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('org_uuid')
            ->from('opencase_userorgs')
            ->where($qb->expr()->eq('user_uuid', $qb->createNamedParameter($userUuid)));
        $result = $qb->executeQuery();
        $uuids = [];
        while (($uuid = $result->fetchOne()) !== false) {
            $uuids[] = (string)$uuid;
        }
        $result->closeCursor();
        return $uuids;
    }

    /**
     * Sync the user's privilege rows (SAML token / Rettigheder): insert new,
     * update changed, delete removed. Group rows are left alone, except that
     * an organisation the privileges now grant takes over a group row.
     *
     * @param string $userUuid
     * @param array<array{orgUuid: string, role: string}> $organisations
     */
    public function syncForUser(string $userUuid, array $organisations): void {
        // Index incoming by orgUuid for easy lookup
        $incoming = [];
        foreach ($organisations as $org) {
            $incoming[$org->orgUuid] = $org->role;
        }

        // Fetch existing rows, of every source
        $qb = $this->db->getQueryBuilder();
        $qb->select('org_uuid', 'role', 'source')
            ->from('opencase_userorgs')
            ->where($qb->expr()->eq('user_uuid', $qb->createNamedParameter($userUuid)));
        $result = $qb->executeQuery();

        $existing = [];
        $groupRows = [];
        while ($row = $result->fetch()) {
            if ($row['source'] === self::SOURCE_GROUP) {
                $groupRows[$row['org_uuid']] = $row['role'];
            } else {
                $existing[$row['org_uuid']] = $row['role'];
            }
        }
        $result->closeCursor();

        $existingUuids = array_keys($existing);
        $incomingUuids = array_keys($incoming);

        // Delete rows no longer present
        $toDelete = array_diff($existingUuids, $incomingUuids);
        if (!empty($toDelete)) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('opencase_userorgs')
                ->where($qb->expr()->eq('user_uuid', $qb->createNamedParameter($userUuid)))
                ->andWhere($qb->expr()->in('org_uuid', $qb->createNamedParameter($toDelete, IQueryBuilder::PARAM_STR_ARRAY)))
                ->andWhere($qb->expr()->neq('source', $qb->createNamedParameter(self::SOURCE_GROUP)));
            $qb->executeStatement();
        }

        // Insert new or update changed rows
        $userId = $this->userInfoMapper->findByUuid($userUuid)?->getUserId();
        foreach ($incoming as $orgUuid => $role) {
            if (isset($groupRows[$orgUuid])) {
                // One row per user and organisation: the privilege takes it over
                $qb = $this->db->getQueryBuilder();
                $qb->update('opencase_userorgs')
                    ->set('role', $qb->createNamedParameter($role))
                    ->set('source', $qb->createNamedParameter(self::SOURCE_PRIVILEGE))
                    ->where($qb->expr()->eq('user_uuid', $qb->createNamedParameter($userUuid)))
                    ->andWhere($qb->expr()->eq('org_uuid', $qb->createNamedParameter($orgUuid)));
                $qb->executeStatement();
            } elseif (!isset($existing[$orgUuid])) {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('opencase_userorgs')
                    ->values([
                        'user_uuid' => $qb->createNamedParameter($userUuid),
                        'user_id'   => $qb->createNamedParameter($userId),
                        'org_uuid'  => $qb->createNamedParameter($orgUuid),
                        'role'      => $qb->createNamedParameter($role),
                    ]);
                $qb->executeStatement();
            } elseif ($existing[$orgUuid] !== $role) {
                $qb = $this->db->getQueryBuilder();
                $qb->update('opencase_userorgs')
                    ->set('role', $qb->createNamedParameter($role))
                    ->where($qb->expr()->eq('user_uuid', $qb->createNamedParameter($userUuid)))
                    ->andWhere($qb->expr()->eq('org_uuid', $qb->createNamedParameter($orgUuid)));
                $qb->executeStatement();
            }
        }
    }

    /**
     * Organisation uuid => source of the user's rows.
     *
     * @return array<string, string>
     */
    public function findSourcesForUserId(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('org_uuid', 'source')
            ->from('opencase_userorgs')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->executeQuery();
        $sources = [];
        while ($row = $result->fetch()) {
            $sources[(string)$row['org_uuid']] = (string)$row['source'];
        }
        $result->closeCursor();
        return $sources;
    }

    /**
     * Add a group row, unless the user already belongs to the organisation.
     */
    public function addGroupMembership(string $userUuid, string $userId, string $orgUuid, string $role): void {
        if (isset($this->findSourcesForUserId($userId)[$orgUuid])) {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->insert('opencase_userorgs')
            ->values([
                'user_uuid' => $qb->createNamedParameter($userUuid),
                'user_id'   => $qb->createNamedParameter($userId),
                'org_uuid'  => $qb->createNamedParameter($orgUuid),
                'role'      => $qb->createNamedParameter($role),
                'source'    => $qb->createNamedParameter(self::SOURCE_GROUP),
            ]);
        $qb->executeStatement();
    }

    /**
     * Remove the user's group rows for the given organisations, or all of
     * them when $orgUuids is null. Privilege rows are kept.
     *
     * @param string[]|null $orgUuids
     */
    public function removeGroupMemberships(string $userId, ?array $orgUuids = null): void {
        if ($orgUuids === []) {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete('opencase_userorgs')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('source', $qb->createNamedParameter(self::SOURCE_GROUP)));
        if ($orgUuids !== null) {
            $qb->andWhere($qb->expr()->in('org_uuid', $qb->createNamedParameter($orgUuids, IQueryBuilder::PARAM_STR_ARRAY)));
        }
        $qb->executeStatement();
    }

    /**
     * User IDs with at least one group row.
     *
     * @return string[]
     */
    public function findUserIdsWithGroupMemberships(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('user_id')
            ->from('opencase_userorgs')
            ->where($qb->expr()->eq('source', $qb->createNamedParameter(self::SOURCE_GROUP)));
        $result = $qb->executeQuery();
        $userIds = [];
        while (($userId = $result->fetchOne()) !== false) {
            $userIds[] = (string)$userId;
        }
        $result->closeCursor();
        return $userIds;
    }
}
