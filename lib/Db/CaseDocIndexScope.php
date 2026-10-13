<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Represents a row in the opencase_casedocindex_scope table — one
 * restriction (casetype, organisation, or KLE pattern) on a SAPA transfer
 * rule (opencase_casedocindex_def).
 *
 * @method int getCasedocindexDefId()
 * @method void setCasedocindexDefId(int $casedocindexDefId)
 * @method string getScopeType()
 * @method void setScopeType(string $scopeType)
 * @method string getScopeValue()
 * @method void setScopeValue(string $scopeValue)
 */
class CaseDocIndexScope extends Entity {

    protected int $casedocindexDefId = 0;
    protected string $scopeType = '';
    protected string $scopeValue = '';

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('casedocindexDefId', 'integer');
        $this->addType('scopeType', 'string');
        $this->addType('scopeValue', 'string');
    }
}
