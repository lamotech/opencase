<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getCaseId()
 * @method void setCaseId(int $caseId)
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method string getType()
 * @method void setType(string $type)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getRequesterName()
 * @method void setRequesterName(?string $v)
 * @method string|null getRequesterEmail()
 * @method void setRequesterEmail(?string $v)
 * @method string|null getRequesterPhone()
 * @method void setRequesterPhone(?string $v)
 * @method string|null getRequesterIdentifier()
 * @method void setRequesterIdentifier(?string $v)
 * @method string getSubject()
 * @method void setSubject(string $v)
 * @method string|null getDescription()
 * @method void setDescription(?string $v)
 * @method string|null getAssignedUser()
 * @method void setAssignedUser(?string $v)
 * @method \DateTime getReceivedAt()
 * @method void setReceivedAt(\DateTime $v)
 * @method \DateTime getDeadlineAt()
 * @method void setDeadlineAt(\DateTime $v)
 * @method \DateTime|null getExtendedDeadlineAt()
 * @method void setExtendedDeadlineAt(?\DateTime $v)
 * @method string|null getExtensionReason()
 * @method void setExtensionReason(?string $v)
 * @method string|null getLegalBasis()
 * @method void setLegalBasis(?string $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $v)
 */
class AccessRequest extends Entity {

    protected int $caseId = 0;
    protected string $uuid = '';
    protected string $type = '';
    protected string $status = 'received';
    protected ?string $requesterName = null;
    protected ?string $requesterEmail = null;
    protected ?string $requesterPhone = null;
    protected ?string $requesterIdentifier = null;
    protected string $subject = '';
    protected ?string $description = null;
    protected ?string $assignedUser = null;
    protected ?\DateTime $receivedAt = null;
    protected ?\DateTime $deadlineAt = null;
    protected ?\DateTime $extendedDeadlineAt = null;
    protected ?string $extensionReason = null;
    protected ?string $legalBasis = null;
    protected string $createdBy = '';
    protected ?\DateTime $createdAt = null;
    protected ?\DateTime $updatedAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('caseId', 'integer');
        $this->addType('receivedAt', 'datetime');
        $this->addType('deadlineAt', 'datetime');
        $this->addType('extendedDeadlineAt', 'datetime');
        $this->addType('createdAt', 'datetime');
        $this->addType('updatedAt', 'datetime');
    }
}
