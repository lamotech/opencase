<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Read-only mapper for the opencase_documenttype lookup table.
 *
 * Unlike the other lookup mappers this table is not multilingual — the names
 * are customer-maintained — so we return plain arrays keyed the same way the
 * sibling mappers do.
 */
class DocumentTypeMapper {

    public function __construct(private IDBConnection $db) {}

    /**
     * Return all document types ordered by id.
     *
     * Each element: ['id' => int, 'name' => string, 'is_system' => bool, 'expired' => bool]
     *
     * @param bool|null $isSystem Restrict to system (true) or user-selectable (false) types; null returns both.
     *
     * @return array[]
     */
    public function findAll(bool $excludeExpired = false, ?bool $isSystem = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'name', 'is_system', 'expired')
            ->from('opencase_documenttype')
            ->orderBy('id', 'ASC');

        if ($excludeExpired) {
            $qb->andWhere($qb->expr()->eq('expired', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));
        }
        if ($isSystem !== null) {
            $qb->andWhere($qb->expr()->eq('is_system', $qb->createNamedParameter($isSystem, IQueryBuilder::PARAM_BOOL)));
        }

        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            $rows[] = [
                'id'        => (int)$row['id'],
                'name'      => $row['name'],
                'is_system' => (bool)$row['is_system'],
                'expired'   => (bool)$row['expired'],
            ];
        }
        $result->closeCursor();

        return $rows;
    }

    /**
     * Build an id → name map over all types, including system and expired ones,
     * so historical references always resolve to a label.
     *
     * @return array<int, string>
     */
    public function getNameMap(): array {
        return array_column($this->findAll(), 'name', 'id');
    }
}
