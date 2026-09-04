<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for the opencase_caseestates table.
 *
 * @extends QBMapper<CaseEstate>
 */
class CaseEstateMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_caseestates', CaseEstate::class);
    }

    /**
     * Return raw case rows for all non-inbox cases linked (via
     * opencase_estate) to the estate identified by the given BFE-nummer,
     * including the case-estate role ID.
     *
     * @return array<int, array{id: int, case_number: string, title: string, status_id: int, org_uuid: string, updated_at: string, role_id: ?int}>
     */
    public function findRawCasesByBfenummer(int $bfenummer): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(['c.id', 'c.case_number', 'c.title', 'c.status_id', 'c.org_uuid', 'c.updated_at', 'ce.role_id'])
            ->from($this->getTableName(), 'ce')
            ->innerJoin('ce', 'opencase_estate', 'e', $qb->expr()->eq('e.id', 'ce.estate_id'))
            ->innerJoin('ce', 'opencase_cases', 'c', $qb->expr()->eq('c.id', 'ce.case_id'))
            ->where($qb->expr()->eq('e.bfenummer', $qb->createNamedParameter($bfenummer, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('c.is_inbox', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->orderBy('c.updated_at', 'DESC');

        $result = $qb->executeQuery();
        $rows   = [];
        while ($row = $result->fetch()) {
            $rows[] = $row;
        }
        $result->closeCursor();
        return $rows;
    }

    /**
     * Return raw estate rows linked to the given case, with the
     * location address pre-resolved from whichever of apartment/building/
     * aggregated-estate the estate row actually points at.
     *
     * @return array<int, array{estate_id: int, type: string, bfenummer: ?int, role_id: ?int, agg_location_address: ?string, apt_location_address: ?string, bld_location_address: ?string}>
     */
    public function findRawEstatesByCase(int $caseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select([
            'e.id AS estate_id',
            'e.type',
            'e.bfenummer',
            'ce.role_id',
            'agg.location_address AS agg_location_address',
            'apt.location_address AS apt_location_address',
            'bld.location_address AS bld_location_address',
        ])
            ->from($this->getTableName(), 'ce')
            ->innerJoin('ce', 'opencase_estate', 'e', $qb->expr()->eq('e.id', 'ce.estate_id'))
            ->leftJoin('e', 'opencase_aggregated_estates', 'agg', $qb->expr()->eq('agg.id', 'e.aggregated_estates_id'))
            ->leftJoin('e', 'opencase_apartments', 'apt', $qb->expr()->eq('apt.id', 'e.apartment_id'))
            ->leftJoin('e', 'opencase_buildings_land_by_other', 'bld', $qb->expr()->eq('bld.id', 'e.buildings_land_by_other_id'))
            ->where($qb->expr()->eq('ce.case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
            ->orderBy('ce.id', 'ASC');

        $result = $qb->executeQuery();
        $rows   = [];
        while ($row = $result->fetch()) {
            $rows[] = $row;
        }
        $result->closeCursor();
        return $rows;
    }
}
