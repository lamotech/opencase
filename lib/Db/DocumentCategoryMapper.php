<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Read-only mapper for the opencase_documentcategory lookup table.
 *
 * The table has a composite primary key (id, language) so we bypass QBMapper
 * and return plain arrays — this table is never written to outside migrations.
 */
class DocumentCategoryMapper {

    public function __construct(private IDBConnection $db) {}

    /**
     * Return all categories for the given language, ordered by id.
     *
     * Each element: ['id' => int, 'name' => string]
     *
     * @return array[]
     */
    public function findByLanguage(string $language, bool $excludeExpired = false): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'name', 'expired')
            ->from('opencase_documentcategory')
            ->where($qb->expr()->eq('language', $qb->createNamedParameter($language)))
            ->orderBy('id', 'ASC');

        if ($excludeExpired) {
            $qb->andWhere($qb->expr()->eq('expired', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));
        }

        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            $rows[] = [
                'id'      => (int)$row['id'],
                'name'    => $row['name'],
                'expired' => (bool)$row['expired'],
            ];
        }
        $result->closeCursor();

        return $rows;
    }

    /**
     * Build an id → name map for the given language, falling back to 'en'.
     *
     * @return array<int, string>
     */
    public function getNameMap(string $language): array {
        $rows = $this->findByLanguage($language);
        if (empty($rows)) {
            $rows = $this->findByLanguage('en');
        }
        return array_column($rows, 'name', 'id');
    }
}
