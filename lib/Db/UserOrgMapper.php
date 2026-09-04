<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class UserOrgMapper {

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
     * Sync the org rows for a user: insert new, update changed, delete removed.
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

        // Fetch existing rows
        $qb = $this->db->getQueryBuilder();
        $qb->select('org_uuid', 'role')
            ->from('opencase_userorgs')
            ->where($qb->expr()->eq('user_uuid', $qb->createNamedParameter($userUuid)));
        $result = $qb->executeQuery();

        $existing = [];
        while ($row = $result->fetch()) {
            $existing[$row['org_uuid']] = $row['role'];
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
                ->andWhere($qb->expr()->in('org_uuid', $qb->createNamedParameter($toDelete, IQueryBuilder::PARAM_STR_ARRAY)));
            $qb->executeStatement();
        }

        // Insert new or update changed rows
        $userId = $this->userInfoMapper->findByUuid($userUuid)?->getUserId();
        foreach ($incoming as $orgUuid => $role) {
            if (!isset($existing[$orgUuid])) {
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
}
