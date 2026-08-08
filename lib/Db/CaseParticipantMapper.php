<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for the opencase_caseparticipants table.
 *
 * @extends QBMapper<CaseParticipant>
 */
class CaseParticipantMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_caseparticipants', CaseParticipant::class);
    }

    public function findById(int $id): ?CaseParticipant {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Return true if a participant with the same case, role, and CVR already exists.
     */
    public function existsByCaseRoleAndCvr(int $caseId, int $participantroleId, string $cprCvr, ?string $pnumber = null): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('case_id',             $qb->createNamedParameter($caseId,           IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('participantrole_id', $qb->createNamedParameter($participantroleId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('cpr_cvr',            $qb->createNamedParameter($cprCvr)));

        if ($pnumber !== null && $pnumber !== '') {
            $qb->andWhere($qb->expr()->eq('pnumber', $qb->createNamedParameter($pnumber)));
        } else {
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('pnumber'),
                $qb->expr()->eq('pnumber', $qb->createNamedParameter(''))
            ));
        }

        $result = $qb->executeQuery();
        $count  = (int)$result->fetchOne();
        $result->closeCursor();

        return $count > 0;
    }

    /**
     * Return all participants for a given case, ordered by role then id.
     *
     * @return CaseParticipant[]
     */
    public function findByCase(int $caseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
            ->orderBy('participantrole_id', 'ASC')
            ->addOrderBy('id', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Return raw case rows for all non-inbox cases where the given CPR/CVR is a participant,
     * including the participant role ID (one row per role if the citizen holds multiple roles).
     *
     * @return array<int, array{id: int, case_number: string, title: string, status_id: int, org_uuid: string, updated_at: string, participantrole_id: int}>
     */
    public function findRawCasesByCpr(string $cprCvr, ?string $pnumber = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(['c.id', 'c.case_number', 'c.title', 'c.status_id', 'c.org_uuid', 'c.updated_at', 'cp.participantrole_id'])
            ->from($this->getTableName(), 'cp')
            ->innerJoin('cp', 'opencase_cases', 'c', $qb->expr()->eq('c.id', 'cp.case_id'))
            ->where($qb->expr()->eq('cp.cpr_cvr', $qb->createNamedParameter($cprCvr)))
            ->andWhere($qb->expr()->eq('c.is_inbox', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

        if ($pnumber !== null && $pnumber !== '') {
            $qb->andWhere($qb->expr()->eq('cp.pnumber', $qb->createNamedParameter($pnumber)));
        }

        $qb->orderBy('c.updated_at', 'DESC')
            ->addOrderBy('cp.participantrole_id', 'ASC');

        $result = $qb->executeQuery();
        $rows   = [];
        while ($row = $result->fetch()) {
            $rows[] = $row;
        }
        $result->closeCursor();
        return $rows;
    }

    /**
     * Return raw case rows for all non-inbox cases where the given Nextcloud
     * user (by uid) is a participant, including the participant role ID.
     *
     * @return array<int, array{id: int, case_number: string, title: string, status_id: int, org_uuid: string, updated_at: string, participantrole_id: int}>
     */
    public function findRawCasesByUserId(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(['c.id', 'c.case_number', 'c.title', 'c.status_id', 'c.org_uuid', 'c.updated_at', 'cp.participantrole_id'])
            ->from($this->getTableName(), 'cp')
            ->innerJoin('cp', 'opencase_cases', 'c', $qb->expr()->eq('c.id', 'cp.case_id'))
            ->where($qb->expr()->eq('cp.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('c.is_inbox', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

        $qb->orderBy('c.updated_at', 'DESC')
            ->addOrderBy('cp.participantrole_id', 'ASC');

        $result = $qb->executeQuery();
        $rows   = [];
        while ($row = $result->fetch()) {
            $rows[] = $row;
        }
        $result->closeCursor();
        return $rows;
    }

    /**
     * Raw rows for participants matching the given CPR/CVR on cases whose
     * status is not closed. Grouped to collapse duplicates from joining the
     * multi-language opencase_casestatus table.
     *
     * @return array<int, array{id: int, case_id: int, name: ?string, responsible_user_id: ?string}>
     */
    public function findActiveByCprCvr(string $cprCvr): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(['p.id', 'p.case_id', 'p.name', 'c.responsible_user_id'])
            ->from($this->getTableName(), 'p')
            ->innerJoin('p', 'opencase_cases', 'c', $qb->expr()->eq('c.id', 'p.case_id'))
            ->innerJoin('c', 'opencase_casestatus', 's', $qb->expr()->andX(
                $qb->expr()->eq('s.id', 'c.status_id'),
                $qb->expr()->eq('s.is_closed', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ))
            ->where($qb->expr()->eq('p.cpr_cvr', $qb->createNamedParameter($cprCvr)))
            ->groupBy(['p.id', 'p.case_id', 'p.name', 'c.responsible_user_id']);

        $result = $qb->executeQuery();
        $rows   = [];
        while ($row = $result->fetch()) {
            $rows[] = $row;
        }
        $result->closeCursor();
        return $rows;
    }

    /**
     * Raw rows matching a CPR/CVR (dash-insensitive exact match) or free-text
     * name/address fields. Used as the local-DB fallback citizen/company
     * search when enterprise_version=0 (no Datafordeler access) — the caller
     * is responsible for filtering the results down to the right cpr_cvr
     * length (10 digits for a citizen CPR, 8 for a company CVR), since this
     * table holds both.
     *
     * @return array<int, array{cpr_cvr: ?string, name: ?string, streetname: ?string, housenumber: ?string, floor: ?string, door: ?string, zipcode: ?string, zipdistrict: ?string, phone: ?string, email: ?string, has_address_protection: int, pnumber: ?string}>
     */
    public function searchByFields(
        string $cpr,
        string $firstname,
        string $lastname,
        string $streetname,
        string $housenumber,
        string $zipcode,
        string $zipdistrict,
    ): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(['cpr_cvr', 'name', 'streetname', 'housenumber', 'floor', 'door', 'zipcode', 'zipdistrict', 'phone', 'email', 'has_address_protection', 'pnumber'])
            ->from($this->getTableName())
            ->where($qb->expr()->isNotNull('cpr_cvr'))
            ->andWhere($qb->expr()->neq('cpr_cvr', $qb->createNamedParameter('')));

        if ($cpr !== '') {
            $qb->andWhere($qb->expr()->eq(
                $qb->createFunction("REPLACE(cpr_cvr, '-', '')"),
                $qb->createNamedParameter(preg_replace('/\D/', '', $cpr)),
            ));
        } else {
            if ($firstname !== '') {
                $qb->andWhere($qb->expr()->iLike('name', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($firstname) . '%')));
            }
            if ($lastname !== '') {
                $qb->andWhere($qb->expr()->iLike('name', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($lastname) . '%')));
            }
            if ($streetname !== '') {
                $qb->andWhere($qb->expr()->iLike('streetname', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($streetname) . '%')));
            }
            if ($housenumber !== '') {
                $qb->andWhere($qb->expr()->eq('housenumber', $qb->createNamedParameter($housenumber)));
            }
            if ($zipcode !== '') {
                $qb->andWhere($qb->expr()->eq('zipcode', $qb->createNamedParameter($zipcode)));
            }
            if ($zipdistrict !== '') {
                $qb->andWhere($qb->expr()->iLike('zipdistrict', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($zipdistrict) . '%')));
            }
        }

        $qb->setMaxResults(100);

        $result = $qb->executeQuery();
        $rows   = [];
        while ($row = $result->fetch()) {
            $rows[] = $row;
        }
        $result->closeCursor();
        return $rows;
    }

    /**
     * Update the address/name fields for a participant after a CPR lookup.
     */
    public function updateAddressAndName(
        int $id,
        string $name,
        string $streetname,
        string $housenumber,
        ?string $floor,
        ?string $door,
        string $zipcode,
        string $zipdistrict,
    ): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('name',        $qb->createNamedParameter($name))
            ->set('streetname',  $qb->createNamedParameter($streetname))
            ->set('housenumber', $qb->createNamedParameter($housenumber))
            ->set('floor',       $qb->createNamedParameter($floor))
            ->set('door',        $qb->createNamedParameter($door))
            ->set('zipcode',     $qb->createNamedParameter($zipcode))
            ->set('zipdistrict', $qb->createNamedParameter($zipdistrict))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }
}
