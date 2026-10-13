<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One case or document targeted by a bulk-update job, tracked individually
 * so the processing step can retry/report per-item instead of all-or-nothing.
 *
 * @method int       getBulkupdateId()
 * @method void      setBulkupdateId(int $bulkupdateId)
 * @method int       getEntityId()
 * @method void      setEntityId(int $entityId)
 * @method string    getStatus()
 * @method void      setStatus(string $status)
 * @method string|null getErrorMsg()
 * @method void      setErrorMsg(?string $errorMsg)
 * @method \DateTime getUpdatedAt()
 * @method void      setUpdatedAt(\DateTime $updatedAt)
 * @method string|null getOriginalValues()
 * @method void      setOriginalValues(?string $originalValues)
 */
class BulkUpdateItem extends Entity {

    protected int $bulkupdateId = 0;
    protected int $entityId = 0;
    protected string $status = 'pending';
    protected ?string $errorMsg = null;
    protected ?\DateTime $updatedAt = null;
    protected ?string $originalValues = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('bulkupdateId', 'integer');
        $this->addType('entityId', 'integer');
        $this->addType('status', 'string');
        $this->addType('errorMsg', 'string');
        $this->addType('updatedAt', 'datetime');
        $this->addType('originalValues', 'string');
    }
}
