<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\IDBConnection;

/**
 * Registry of systems authorised to call the OpenCase public API via mTLS.
 *
 * Rows are managed by the `opencase:register-api-client` CLI command.
 * The fingerprint column holds the SHA-256 fingerprint of the client
 * certificate as a lowercase hex string without colons, which is exactly
 * what nginx exposes via $ssl_client_fingerprint.
 */
class ApiClientRepository {

    public function __construct(
        private IDBConnection $db,
    ) {}

    /**
     * Look up an active client by its certificate fingerprint.
     *
     * Returns the row array or null if not found / inactive.
     */
    public function findActiveByFingerprint(string $fingerprint): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('opencase_api_client')
            ->where($qb->expr()->eq('fingerprint', $qb->createNamedParameter(strtolower($fingerprint))))
            ->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1)));
        $row = $qb->executeQuery()->fetch();
        return $row ?: null;
    }

    /**
     * Look up an active client by certificate fingerprint, scoped to a
     * specific valid_for use case (e.g. 'API').
     *
     * Returns the row array or null if not found / inactive / wrong scope.
     */
    public function findActiveByFingerprintAndValidFor(string $fingerprint, string $validFor): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('opencase_api_client')
            ->where($qb->expr()->eq('fingerprint', $qb->createNamedParameter(strtolower($fingerprint))))
            ->andWhere($qb->expr()->eq('valid_for', $qb->createNamedParameter($validFor)))
            ->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1)));
        $row = $qb->executeQuery()->fetch();
        return $row ?: null;
    }

    /**
     * Look up all active clients registered for a given valid_for use case,
     * including their certificate PEM body. Used to build the set of trust
     * anchors accepted when verifying a signed bearer token (e.g. all
     * active 'Adgangsstyring' rows, to support certificate rotation).
     *
     * @return list<array>
     */
    public function findAllActiveByValidFor(string $validFor): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('opencase_api_client')
            ->where($qb->expr()->eq('valid_for', $qb->createNamedParameter($validFor)))
            ->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1)));
        return $qb->executeQuery()->fetchAll();
    }

    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('opencase_api_client')->orderBy('name');
        return $qb->executeQuery()->fetchAll();
    }

    public function insert(
        string $name,
        string $fingerprint,
        ?string $subjectDn = null,
        ?\DateTimeImmutable $expiresAt = null,
        ?string $validFor = null,
        ?string $mappedToUser = null,
        ?string $certificate = null,
    ): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('opencase_api_client')
            ->values([
                'name'           => $qb->createNamedParameter($name),
                'fingerprint'    => $qb->createNamedParameter(strtolower($fingerprint)),
                'subject_dn'     => $qb->createNamedParameter($subjectDn),
                'active'         => $qb->createNamedParameter(1),
                'created_at'     => $qb->createNamedParameter(new \DateTime(), 'datetime'),
                'expires_at'     => $qb->createNamedParameter($expiresAt ? \DateTime::createFromImmutable($expiresAt) : null, 'datetime'),
                'valid_for'      => $qb->createNamedParameter($validFor),
                'mapped_to_user' => $qb->createNamedParameter($mappedToUser),
                'certificate'    => $qb->createNamedParameter($certificate),
            ])
            ->executeStatement();
    }

    public function setActive(int $id, bool $active): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('opencase_api_client')
            ->set('active', $qb->createNamedParameter($active ? 1 : 0))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->executeStatement();
    }

    public function setExpiresAt(int $id, ?\DateTimeImmutable $expiresAt): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('opencase_api_client')
            ->set('expires_at', $qb->createNamedParameter(
                $expiresAt ? \DateTime::createFromImmutable($expiresAt) : null,
                'datetime',
            ))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->executeStatement();
    }

    public function delete(int $id): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('opencase_api_client')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->executeStatement();
    }
}
