<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Represents a row in the opencase_process_scope table — one restriction
 * (casetype, organisation, or KLE pattern) on which cases a process
 * (opencase_process_def) can be started on.
 *
 * @method int getProcessDefId()
 * @method void setProcessDefId(int $processDefId)
 * @method string getScopeType()
 * @method void setScopeType(string $scopeType)
 * @method string getScopeValue()
 * @method void setScopeValue(string $scopeValue)
 */
class ProcessScope extends Entity {

    protected int $processDefId = 0;
    protected string $scopeType = '';
    protected string $scopeValue = '';

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('processDefId', 'integer');
        $this->addType('scopeType', 'string');
        $this->addType('scopeValue', 'string');
    }
}
