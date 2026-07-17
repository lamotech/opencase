<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for oc_opencase_casetype.
 *
 * The table has a composite PK (id, language) — one display-name row per
 * case type per language. Numeric IDs are shared across languages.
 *
 * @extends QBMapper<CaseType>
 */
class CaseTypeMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_casetype', CaseType::class);
    }

    /**
     * Return all case types for the given language.
     * Falls back to $fallback if no rows found for $language.
     *
     * @return CaseType[]
     */
    public function findAllForLanguage(string $language, string $fallback = 'en', bool $excludeExpired = false): array {
        $rows = $this->queryForLanguage($language, $excludeExpired);
        if (empty($rows) && $language !== $fallback) {
            $rows = $this->queryForLanguage($fallback, $excludeExpired);
        }
        return $rows;
    }

    /**
     * Return a [caseTypeId => name] map for the given IDs in the given
     * language (falls back to $fallback).
     *
     * @param int[] $ids
     * @return array<int, string>
     */
    public function getNamesByIds(array $ids, string $language, string $fallback = 'en'): array {
        if (empty($ids)) {
            return [];
        }

        $map = $this->fetchNameMap($ids, $language);
        if (empty($map) && $language !== $fallback) {
            $map = $this->fetchNameMap($ids, $fallback);
        }
        return $map;
    }

    // ---------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------

    /** @return CaseType[] */
    private function queryForLanguage(string $language, bool $excludeExpired = false): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'language', 'name', 'expired', 'primary_participant')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('language', $qb->createNamedParameter($language)))
            ->orderBy('id', 'ASC');

        if ($excludeExpired) {
            $qb->andWhere($qb->expr()->eq('expired', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));
        }

        return $this->findEntities($qb);
    }

    /**
     * @param int[] $ids
     * @return array<int, string>
     */
    private function fetchNameMap(array $ids, string $language): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'name')
            ->from($this->getTableName())
            ->where($qb->expr()->in(
                'id',
                $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)
            ))
            ->andWhere($qb->expr()->eq('language', $qb->createNamedParameter($language)));

        $result = $qb->executeQuery();
        $map    = [];
        while ($row = $result->fetch()) {
            $map[(int)$row['id']] = $row['name'];
        }
        $result->closeCursor();
        return $map;
    }
}
