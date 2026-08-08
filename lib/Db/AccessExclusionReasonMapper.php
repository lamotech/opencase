<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @extends QBMapper<AccessExclusionReason> */
class AccessExclusionReasonMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'opencase_access_exclusion_reasons', AccessExclusionReason::class);
    }

    /** @return AccessExclusionReason[] */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
        return $this->findEntities($qb);
    }
}
