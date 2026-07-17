<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\IDBConnection;

/**
 * Simple key-value mapper for the opencase_config table.
 *
 * Does not extend the NC Entity/Mapper base because the table has no
 * auto-increment id column — the config_key IS the primary key.
 */
class ConfigMapper {

    public function __construct(private IDBConnection $db) {
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

        return $value !== false ? (string)$value : $default;
    }

    /**
     * Insert or update a config value.
     */
    public function set(string $key, string $value): void {
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
     * Return all config entries as an associative array.
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
            $config[$row['config_key']] = (string)$row['config_value'];
        }
        $result->closeCursor();

        return $config;
    }

    /**
     * Return all config entries including human-readable metadata.
     *
     * @return list<array{key: string, value: string, name: string|null, description: string|null}>
     */
    public function getAllWithMeta(): array {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('config_key', 'config_value', 'name', 'description')
            ->from('opencase_config')
            ->orderBy('config_key')
            ->executeQuery();

        $entries = [];
        while ($row = $result->fetch()) {
            $entries[] = [
                'key'         => $row['config_key'],
                'value'       => (string)$row['config_value'],
                'name'        => $row['name'] ?? null,
                'description' => $row['description'] ?? null,
            ];
        }
        $result->closeCursor();

        return $entries;
    }
}
