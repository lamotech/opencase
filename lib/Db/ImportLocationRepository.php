<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class ImportLocationRepository {

    public function __construct(
        private IDBConnection $db,
    ) {}

    /**
     * Return all non-expired import locations of the given type.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findActiveByType(string $type): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('opencase_importlocations')
            ->where($qb->expr()->eq('type', $qb->createNamedParameter($type)))
            ->andWhere($qb->expr()->eq('expired', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
            ->orderBy('id', 'ASC');

        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            $rows[] = $row;
        }
        $result->closeCursor();
        return $rows;
    }

    /**
     * Return all import locations of the given type, expired or not —
     * used by the admin settings page, which needs to show (and re-enable)
     * expired locations too.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllByType(string $type): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('opencase_importlocations')
            ->where($qb->expr()->eq('type', $qb->createNamedParameter($type)))
            ->orderBy('id', 'ASC');

        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            $rows[] = $row;
        }
        $result->closeCursor();
        return $rows;
    }

    public function insertFolder(string $folderPath, string $fileExtensionFilter): int {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('opencase_importlocations')
            ->values([
                'type'                   => $qb->createNamedParameter('folder'),
                'expired'                => $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
                'folderpath'             => $qb->createNamedParameter($folderPath),
                'file_extension_filter'  => $qb->createNamedParameter($fileExtensionFilter),
            ])
            ->executeStatement();
        return (int)$this->db->lastInsertId('oc_opencase_importlocations');
    }

    public function insertMailbox(
        string $server,
        string $port,
        string $user,
        string $password,
        bool $useSsl,
        string $fileExtensionFilter,
    ): int {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('opencase_importlocations')
            ->values([
                'type'                   => $qb->createNamedParameter('mailbox'),
                'expired'                => $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
                'mailbox_server'         => $qb->createNamedParameter($server),
                'mailbox_port'           => $qb->createNamedParameter($port),
                'mailbox_user'           => $qb->createNamedParameter($user),
                'mailbox_password'       => $qb->createNamedParameter($password),
                'mailbox_use_ssl'        => $qb->createNamedParameter($useSsl, IQueryBuilder::PARAM_BOOL),
                'file_extension_filter'  => $qb->createNamedParameter($fileExtensionFilter),
            ])
            ->executeStatement();
        return (int)$this->db->lastInsertId('oc_opencase_importlocations');
    }

    public function updateMailbox(
        int $id,
        string $server,
        string $port,
        string $user,
        ?string $password,
        bool $useSsl,
        string $fileExtensionFilter,
    ): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('opencase_importlocations')
            ->set('mailbox_server', $qb->createNamedParameter($server))
            ->set('mailbox_port', $qb->createNamedParameter($port))
            ->set('mailbox_user', $qb->createNamedParameter($user))
            ->set('mailbox_use_ssl', $qb->createNamedParameter($useSsl, IQueryBuilder::PARAM_BOOL))
            ->set('file_extension_filter', $qb->createNamedParameter($fileExtensionFilter));

        // Leave the stored password untouched when the edit form's password
        // field was left blank (the admin isn't shown the current password).
        if ($password !== null && $password !== '') {
            $qb->set('mailbox_password', $qb->createNamedParameter($password));
        }

        $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    public function setExpired(int $id, bool $expired): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('opencase_importlocations')
            ->set('expired', $qb->createNamedParameter($expired, IQueryBuilder::PARAM_BOOL))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    public function delete(int $id): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('opencase_importlocations')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
