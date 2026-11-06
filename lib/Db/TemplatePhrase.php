<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Represents a row in the opencase_template_phrases table — a phrase
 * (opencase_phrases) attached to a template (opencase_templates).
 *
 * @method int getTemplateId()
 * @method void setTemplateId(int $templateId)
 * @method int getPhraseId()
 * @method void setPhraseId(int $phraseId)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method bool getIsDefault()
 * @method void setIsDefault(bool $isDefault)
 * @method bool getIsRequired()
 * @method void setIsRequired(bool $isRequired)
 */
class TemplatePhrase extends Entity {

    protected int $templateId = 0;
    protected int $phraseId = 0;
    protected int $sortOrder = 0;
    protected bool $isDefault = false;
    protected bool $isRequired = false;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('templateId', 'integer');
        $this->addType('phraseId', 'integer');
        $this->addType('sortOrder', 'integer');
        $this->addType('isDefault', 'boolean');
        $this->addType('isRequired', 'boolean');
    }
}
