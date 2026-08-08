<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for the opencase_cprevents table.
 *
 * @extends QBMapper<CprEvent>
 */
class CprEventMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_cprevents', CprEvent::class);
    }

    /**
     * Return the enabled CPR event matching the given code, or null if the
     * code is unknown or disabled.
     */
    public function findEnabledByCode(string $code): ?CprEvent {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('code', $qb->createNamedParameter($code)))
            ->andWhere($qb->expr()->eq('enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }
}
