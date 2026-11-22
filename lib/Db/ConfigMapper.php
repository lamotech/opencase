<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCA\OpenCase\Service\SecretCrypto;
use OCP\IDBConnection;

/**
 * Simple key-value mapper for the opencase_config table.
 *
 * Does not extend the NC Entity/Mapper base because the table has no
 * auto-increment id column — the config_key IS the primary key.
 *
 * Secret keys (SecretCrypto::CONFIG_KEYS) are encrypted on write, decrypted by
 * get(), and masked as '' by getAll()/getAllWithMeta().
 */
class ConfigMapper {

    public function __construct(
        private IDBConnection $db,
        private SecretCrypto $secretCrypto,
    ) {
    }

    /**
     * Get a config value by key. Returns $default if the key does not exist.
     */
    public function get(string $key, string $default = ''): string {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('config_value')
            ->from('opencase_config')
            ->where($qb->expr()->eq('config_key', $qb->createNamedParameter($key)))
            ->executeQuery();

        $value = $result->fetchOne();
        $result->closeCursor();

        if ($value === false) {
            return $default;
        }
        return SecretCrypto::isSecretConfigKey($key)
            ? (string)$this->secretCrypto->decrypt((string)$value)
            : (string)$value;
    }

    /**
     * Insert or update a config value.
     */
    public function set(string $key, string $value): void {
        if (SecretCrypto::isSecretConfigKey($key)) {
            $value = (string)$this->secretCrypto->encrypt($value);
        }

        $qb = $this->db->getQueryBuilder();
        $updated = $qb->update('opencase_config')
            ->set('config_value', $qb->createNamedParameter($value))
            ->where($qb->expr()->eq('config_key', $qb->createNamedParameter($key)))
            ->executeStatement();

        if ($updated === 0) {
            $qb2 = $this->db->getQueryBuilder();
            $qb2->insert('opencase_config')
                ->values([
                    'config_key'   => $qb2->createNamedParameter($key),
                    'config_value' => $qb2->createNamedParameter($value),
                ])
                ->executeStatement();
        }
    }

    /**
     * Return all config entries as an associative array. Secret values are ''.
     *
     * @return array<string, string>
     */
    public function getAll(): array {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('config_key', 'config_value')
            ->from('opencase_config')
            ->executeQuery();

        $config = [];
        while ($row = $result->fetch()) {
            $config[$row['config_key']] = SecretCrypto::isSecretConfigKey($row['config_key'])
                ? ''
                : (string)$row['config_value'];
        }
        $result->closeCursor();

        return $config;
    }

    /**
     * Return all config entries including human-readable metadata. Secret
     * values are '' with secret = true and isSet telling whether one is stored.
     *
     * @return list<array{key: string, value: string, name: string|null, description: string|null, secret: bool, isSet: bool}>
     */
    public function getAllWithMeta(): array {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('config_key', 'config_value', 'name', 'description')
            ->from('opencase_config')
            ->orderBy('config_key')
            ->executeQuery();

        $entries = [];
        while ($row = $result->fetch()) {
            $secret = SecretCrypto::isSecretConfigKey($row['config_key']);
            $entries[] = [
                'key'         => $row['config_key'],
                'value'       => $secret ? '' : (string)$row['config_value'],
                'name'        => $row['name'] ?? null,
                'description' => $row['description'] ?? null,
                'secret'      => $secret,
                'isSet'       => (string)$row['config_value'] !== '',
            ];
        }
        $result->closeCursor();

        return $entries;
    }
}
