<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Represents a row in the opencase_customfield_value table — the value
 * of one custom field on one case. Exactly one of value_text, value_bool,
 * value_date is populated, depending on the field's field_type.
 *
 * @method int getCaseId()
 * @method void setCaseId(int $caseId)
 * @method int getCustomfieldId()
 * @method void setCustomfieldId(int $customfieldId)
 * @method string|null getValueText()
 * @method void setValueText(?string $valueText)
 * @method bool|null getValueBool()
 * @method void setValueBool(?bool $valueBool)
 * @method \DateTime|null getValueDate()
 * @method void setValueDate(?\DateTime $valueDate)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?\DateTime $updatedAt)
 * @method string|null getUpdatedBy()
 * @method void setUpdatedBy(?string $updatedBy)
 */
class CustomFieldValue extends Entity {

    protected int $caseId = 0;
    protected int $customfieldId = 0;
    protected ?string $valueText = null;
    protected ?bool $valueBool = null;
    protected ?\DateTime $valueDate = null;
    protected ?\DateTime $updatedAt = null;
    protected ?string $updatedBy = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('caseId', 'integer');
        $this->addType('customfieldId', 'integer');
        $this->addType('valueText', 'string');
        $this->addType('valueBool', 'boolean');
        $this->addType('valueDate', 'datetime');
        $this->addType('updatedAt', 'datetime');
        $this->addType('updatedBy', 'string');
    }

    /**
     * The raw scalar value regardless of which typed column holds it.
     *
     * @return string|bool|null
     */
    public function getValue() {
        if ($this->valueBool !== null) {
            return $this->valueBool;
        }
        if ($this->valueDate !== null) {
            return $this->valueDate->format('Y-m-d');
        }
        return $this->valueText;
    }
}
