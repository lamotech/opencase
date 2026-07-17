<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for the opencase_documentcontacts table.
 *
 * @extends QBMapper<DocumentContact>
 */
class DocumentContactMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_documentcontacts', DocumentContact::class);
    }

    public function findById(int $id): ?DocumentContact {
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
     * Return true if a contact with the same document, role, and CVR already exists.
     */
    public function existsByDocumentRoleAndCvr(int $documentId, int $contactroleId, string $cprCvr, ?string $pnumber = null): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('document_id',    $qb->createNamedParameter($documentId,    IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('contactrole_id', $qb->createNamedParameter($contactroleId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('cpr_cvr',        $qb->createNamedParameter($cprCvr)));

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
     * Return all contacts for a given document, ordered by role then id.
     *
     * @return DocumentContact[]
     */
    public function findByDocument(int $documentId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('document_id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)))
            ->orderBy('contactrole_id', 'ASC')
            ->addOrderBy('id', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Return raw document rows for all documents where the given CPR/CVR is a contact,
     * including the contact role ID (one row per role if the citizen holds multiple roles).
     *
     * @return array<int, array{id: int, title: string, document_type: string, document_date: string, updated_at: string, case_id: int, case_number: string, org_name: string, contactrole_id: int}>
     */
    public function findRawDocumentsByCpr(string $cprCvr, ?string $pnumber = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(['d.id', 'd.title', 'd.document_type', 'd.document_date', 'd.updated_at', 'd.case_id', 'dc.contactrole_id'])
            ->selectAlias('c.case_number', 'case_number')
            ->selectAlias('o.org_name', 'org_name')
            ->from($this->getTableName(), 'dc')
            ->innerJoin('dc', 'opencase_documents', 'd', $qb->expr()->eq('d.id', 'dc.document_id'))
            ->innerJoin('d', 'opencase_cases', 'c', $qb->expr()->eq('c.id', 'd.case_id'))
            ->leftJoin('c', 'opencase_org', 'o', $qb->expr()->eq('o.org_uuid', 'c.org_uuid'))
            ->where($qb->expr()->eq('dc.cpr_cvr', $qb->createNamedParameter($cprCvr)))
            ->andWhere($qb->expr()->eq('c.is_inbox', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

        if ($pnumber !== null && $pnumber !== '') {
            $qb->andWhere($qb->expr()->eq('dc.pnumber', $qb->createNamedParameter($pnumber)));
        }

        $qb->orderBy('d.updated_at', 'DESC')
            ->addOrderBy('dc.contactrole_id', 'ASC');

        $result = $qb->executeQuery();
        $rows   = [];
        while ($row = $result->fetch()) {
            $rows[] = $row;
        }
        $result->closeCursor();
        return $rows;
    }

    /**
     * Raw rows for contacts matching the given CPR/CVR on documents whose
     * status is not final. Grouped to collapse duplicates from joining the
     * multi-language opencase_documentstatus table.
     *
     * @return array<int, array{id: int, document_id: int, name: ?string, case_id: int, responsible_user_id: ?string}>
     */
    public function findActiveByCprCvr(string $cprCvr): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(['dc.id', 'dc.document_id', 'dc.name', 'd.case_id', 'c.responsible_user_id'])
            ->from($this->getTableName(), 'dc')
            ->innerJoin('dc', 'opencase_documents', 'd', $qb->expr()->eq('d.id', 'dc.document_id'))
            ->innerJoin('d', 'opencase_documentstatus', 's', $qb->expr()->andX(
                $qb->expr()->eq('s.id', 'd.status'),
                $qb->expr()->eq('s.is_final', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ))
            ->innerJoin('d', 'opencase_cases', 'c', $qb->expr()->eq('c.id', 'd.case_id'))
            ->where($qb->expr()->eq('dc.cpr_cvr', $qb->createNamedParameter($cprCvr)))
            ->groupBy(['dc.id', 'dc.document_id', 'dc.name', 'd.case_id', 'c.responsible_user_id']);

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
     * Update the address/name fields for a contact after a CPR lookup.
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
