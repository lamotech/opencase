<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Represents a row in the opencase_customfield_def table — an
 * admin-defined custom field that can be attached to cases.
 *
 * @method string getFieldKey()
 * @method void setFieldKey(string $fieldKey)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method string getFieldType()
 * @method void setFieldType(string $fieldType)
 * @method string|null getOptions()
 * @method void setOptions(?string $options)
 * @method bool getRequired()
 * @method void setRequired(bool $required)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method bool getActive()
 * @method void setActive(bool $active)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?\DateTime $updatedAt)
 * @method string|null getUpdatedBy()
 * @method void setUpdatedBy(?string $updatedBy)
 */
class CustomFieldDef extends Entity {

    protected string $fieldKey = '';
    protected string $label = '';
    protected string $fieldType = '';
    protected ?string $options = null;
    protected bool $required = false;
    protected int $sortOrder = 0;
    protected bool $active = true;
    protected ?\DateTime $createdAt = null;
    protected ?string $createdBy = null;
    protected ?\DateTime $updatedAt = null;
    protected ?string $updatedBy = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('fieldKey', 'string');
        $this->addType('label', 'string');
        $this->addType('fieldType', 'string');
        $this->addType('options', 'string');
        $this->addType('required', 'boolean');
        $this->addType('sortOrder', 'integer');
        $this->addType('active', 'boolean');
        $this->addType('createdAt', 'datetime');
        $this->addType('createdBy', 'string');
        $this->addType('updatedAt', 'datetime');
        $this->addType('updatedBy', 'string');
    }

    /**
     * @return string[]
     */
    public function getOptionsArray(): array {
        if ($this->options === null || $this->options === '') {
            return [];
        }
        $decoded = json_decode($this->options, true);
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }
}
