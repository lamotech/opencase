<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A deletion deadline (slettefrist), e.g. P365D (1 år). `code` is an
 * ISO 8601 duration and `deletionDays` the same period in days.
 *
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method string getCode()
 * @method void setCode(string $code)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method int|null getDeletionDays()
 * @method void setDeletionDays(?int $deletionDays)
 * @method bool getExpired()
 * @method void setExpired(bool $expired)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?\DateTime $updatedAt)
 * @method string|null getUpdatedBy()
 * @method void setUpdatedBy(?string $updatedBy)
 */
class DeletionDeadline extends Entity {

    protected string $uuid = '';
    protected string $code = '';
    protected string $name = '';
    protected ?string $description = null;
    protected ?int $deletionDays = null;
    protected bool $expired = false;
    protected ?\DateTime $updatedAt = null;
    protected ?string $updatedBy = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('uuid', 'string');
        $this->addType('code', 'string');
        $this->addType('name', 'string');
        $this->addType('description', 'string');
        $this->addType('deletionDays', 'integer');
        $this->addType('expired', 'boolean');
        $this->addType('updatedAt', 'datetime');
        $this->addType('updatedBy', 'string');
    }
}
