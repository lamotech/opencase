<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getRequestId()
 * @method void setRequestId(int $v)
 * @method string getDecision()
 * @method void setDecision(string $v)
 * @method string getDecisionText()
 * @method void setDecisionText(string $v)
 * @method string|null getComplaintGuidance()
 * @method void setComplaintGuidance(?string $v)
 * @method string|null getApprovedBy()
 * @method void setApprovedBy(?string $v)
 * @method \DateTime|null getApprovedAt()
 * @method void setApprovedAt(?\DateTime $v)
 * @method string|null getSentBy()
 * @method void setSentBy(?string $v)
 * @method \DateTime|null getSentAt()
 * @method void setSentAt(?\DateTime $v)
 * @method string|null getDeliveryMethod()
 * @method void setDeliveryMethod(?string $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $v)
 */
class AccessDecision extends Entity {

    protected int $requestId = 0;
    protected string $decision = '';
    protected string $decisionText = '';
    protected ?string $complaintGuidance = null;
    protected ?string $approvedBy = null;
    protected ?\DateTime $approvedAt = null;
    protected ?string $sentBy = null;
    protected ?\DateTime $sentAt = null;
    protected ?string $deliveryMethod = null;
    protected string $createdBy = '';
    protected ?\DateTime $createdAt = null;
    protected ?\DateTime $updatedAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('requestId', 'integer');
        $this->addType('approvedAt', 'datetime');
        $this->addType('sentAt', 'datetime');
        $this->addType('createdAt', 'datetime');
        $this->addType('updatedAt', 'datetime');
    }
}
