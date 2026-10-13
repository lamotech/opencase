<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Represents a row in the opencase_casedocindex_def table — a rule for
 * which cases are transferred to SagDokumentIndeks (SAPA). A case is
 * transferred when it matches at least one active rule; a rule with no
 * scope rows matches every case.
 *
 * @method string getName()
 * @method void setName(string $name)
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
class CaseDocIndexDef extends Entity {

    protected string $name = '';
    protected int $sortOrder = 0;
    protected bool $active = true;
    protected ?\DateTime $createdAt = null;
    protected ?string $createdBy = null;
    protected ?\DateTime $updatedAt = null;
    protected ?string $updatedBy = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('name', 'string');
        $this->addType('sortOrder', 'integer');
        $this->addType('active', 'boolean');
        $this->addType('createdAt', 'datetime');
        $this->addType('createdBy', 'string');
        $this->addType('updatedAt', 'datetime');
        $this->addType('updatedBy', 'string');
    }
}
