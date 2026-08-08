<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Read-only mapper for the opencase_contacttype lookup table.
 *
 * The table has a composite primary key (id, language) so we bypass QBMapper
 * and return plain arrays — this table is never written to outside migrations.
 */
class ContactTypeMapper {

    public const UNKNOWN  = 0;
    public const EMPLOYEE = 1;
    public const CITIZEN  = 2;
    public const COMPANY  = 3;

    public function __construct(private IDBConnection $db) {}

    /**
     * Derive the contact type from a CPR/CVR number:
     *   10 digits -> Citizen
     *   8 digits  -> Company
     *   otherwise -> Employee
     */
    public static function deriveFromCprCvr(?string $cprCvr): int {
        $digits = preg_replace('/\D/', '', (string)$cprCvr);

        return match (strlen($digits)) {
            10 => self::CITIZEN,
            8 => self::COMPANY,
            default => self::EMPLOYEE,
        };
    }

    /**
     * Return all contact types for the given language as [['id' => int, 'name' => string], ...].
     *
     * @return array[]
     */
    public function findByLanguage(string $language, bool $excludeExpired = false): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'name', 'expired')
            ->from('opencase_contacttype')
            ->where($qb->expr()->eq('language', $qb->createNamedParameter($language)))
            ->orderBy('id', 'ASC');

        if ($excludeExpired) {
            $qb->andWhere($qb->expr()->eq('expired', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));
        }

        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            $rows[] = ['id' => (int)$row['id'], 'name' => $row['name'], 'expired' => (bool)$row['expired']];
        }
        $result->closeCursor();

        return $rows;
    }

    /**
     * Build an id → name map for the given language.
     *
     * @return array<int, string>
     */
    public function getNameMap(string $language): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'name')
            ->from('opencase_contacttype')
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
