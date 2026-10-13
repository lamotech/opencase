<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Represents a row in the opencase_customfield_scope table — one
 * restriction (casetype, organisation, or KLE pattern) that a custom
 * field is limited to. A field with no scope rows applies to every case.
 *
 * @method int getCustomfieldId()
 * @method void setCustomfieldId(int $customfieldId)
 * @method string getScopeType()
 * @method void setScopeType(string $scopeType)
 * @method string getScopeValue()
 * @method void setScopeValue(string $scopeValue)
 */
class CustomFieldScope extends Entity {

    protected int $customfieldId = 0;
    protected string $scopeType = '';
    protected string $scopeValue = '';

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('customfieldId', 'integer');
        $this->addType('scopeType', 'string');
        $this->addType('scopeValue', 'string');
    }
}
