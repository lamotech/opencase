<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Represents a row in the opencase_template_scope table — one restriction
 * (an organisation) on a template (opencase_templates).
 *
 * @method int getTemplateId()
 * @method void setTemplateId(int $templateId)
 * @method string getScopeType()
 * @method void setScopeType(string $scopeType)
 * @method string getScopeValue()
 * @method void setScopeValue(string $scopeValue)
 */
class TemplateScope extends Entity {

    protected int $templateId = 0;
    protected string $scopeType = '';
    protected string $scopeValue = '';

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('templateId', 'integer');
        $this->addType('scopeType', 'string');
        $this->addType('scopeValue', 'string');
    }
}
