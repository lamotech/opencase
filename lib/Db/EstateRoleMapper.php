<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\IDBConnection;

/**
 * Read-only mapper for the opencase_estateroles lookup table.
 *
 * The table has a composite primary key (id, language) so we bypass QBMapper
 * and return plain arrays — this table is never written to outside migrations.
 */
class EstateRoleMapper {

    public function __construct(private IDBConnection $db) {}

    /**
     * Build an id → name map for the given language.
     *
     * @return array<int, string>
     */
    public function getNameMap(string $language): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'name')
            ->from('opencase_estateroles')
            ->where($qb->expr()->eq('language', $qb->createNamedParameter($language)))
            ->orderBy('id', 'ASC');

        $result = $qb->executeQuery();
        $map = [];
        while ($row = $result->fetch()) {
            $map[(int)$row['id']] = $row['name'];
        }
        $result->closeCursor();

        return $map;
    }
}
