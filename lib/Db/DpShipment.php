<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getDocumentId()
 * @method void setDocumentId(int $documentId)
 * @method bool getCanBeReplied()
 * @method void setCanBeReplied(bool $canBeReplied)
 * @method string|null getCaseUuid()
 * @method void setCaseUuid(?string $caseUuid)
 * @method string|null getDocumentUuid()
 * @method void setDocumentUuid(?string $documentUuid)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?\DateTime $updatedAt)
 * @method string getStatus()
 * @method void setStatus(string $status)
 */
class DpShipment extends Entity {

    protected int $documentId = 0;
    protected bool $canBeReplied = true;
    protected ?string $caseUuid = null;
    protected ?string $documentUuid = null;
    protected ?\DateTime $createdAt = null;
    protected ?string $createdBy = null;
    protected ?\DateTime $updatedAt = null;
    protected string $status = 'Pending';

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('documentId', 'integer');
        $this->addType('canBeReplied', 'boolean');
        $this->addType('caseUuid', 'string');
        $this->addType('documentUuid', 'string');
        $this->addType('createdAt', 'datetime');
        $this->addType('createdBy', 'string');
        $this->addType('updatedAt', 'datetime');
        $this->addType('status', 'string');
    }
}
