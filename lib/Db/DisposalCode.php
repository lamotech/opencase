<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A disposal code (kassationskode), e.g. B (bevaring) or K10 (kassation
 * after 10 years). `disposalYears` is null for codes never disposed of.
 *
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method string getCode()
 * @method void setCode(string $code)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method int|null getDisposalYears()
 * @method void setDisposalYears(?int $disposalYears)
 * @method bool getExpired()
 * @method void setExpired(bool $expired)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?\DateTime $updatedAt)
 * @method string|null getUpdatedBy()
 * @method void setUpdatedBy(?string $updatedBy)
 */
class DisposalCode extends Entity {

    protected string $uuid = '';
    protected string $code = '';
    protected string $name = '';
    protected ?string $description = null;
    protected ?int $disposalYears = null;
    protected bool $expired = false;
    protected ?\DateTime $updatedAt = null;
    protected ?string $updatedBy = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('uuid', 'string');
        $this->addType('code', 'string');
        $this->addType('name', 'string');
        $this->addType('description', 'string');
        $this->addType('disposalYears', 'integer');
        $this->addType('expired', 'boolean');
        $this->addType('updatedAt', 'datetime');
        $this->addType('updatedBy', 'string');
    }
}
