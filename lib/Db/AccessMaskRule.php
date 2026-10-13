<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getLabel()
 * @method void setLabel(string $v)
 * @method string getCategory()
 * @method void setCategory(string $v)
 * @method string getKind()
 * @method void setKind(string $v)
 * @method string getPattern()
 * @method void setPattern(string $v)
 * @method string|null getValidator()
 * @method void setValidator(?string $v)
 * @method string|null getLegalReference()
 * @method void setLegalReference(?string $v)
 * @method bool getEnabled()
 * @method void setEnabled(bool $v)
 * @method int getSortOrder()
 * @method void setSortOrder(int $v)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?\DateTime $v)
 * @method string|null getUpdatedBy()
 * @method void setUpdatedBy(?string $v)
 */
class AccessMaskRule extends Entity {

    protected string $label = '';
    protected string $category = 'custom';
    protected string $kind = 'regex';
    protected string $pattern = '';
    protected ?string $validator = null;
    protected ?string $legalReference = null;
    protected bool $enabled = true;
    protected int $sortOrder = 0;
    protected ?\DateTime $updatedAt = null;
    protected ?string $updatedBy = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('enabled', 'boolean');
        $this->addType('sortOrder', 'integer');
        $this->addType('updatedAt', 'datetime');
    }
}
