<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getRequestId()
 * @method void setRequestId(int $v)
 * @method string getSourceType()
 * @method void setSourceType(string $v)
 * @method int getSourceId()
 * @method void setSourceId(int $v)
 * @method string getTitle()
 * @method void setTitle(string $v)
 * @method string|null getMimeType()
 * @method void setMimeType(?string $v)
 * @method string|null getDocumentDate()
 * @method void setDocumentDate(?string $v)
 * @method string getDecision()
 * @method void setDecision(string $v)
 * @method string|null getExclusionReason()
 * @method void setExclusionReason(?string $v)
 * @method string|null getLegalReference()
 * @method void setLegalReference(?string $v)
 * @method int getContainsPersonalData()
 * @method void setContainsPersonalData(int $v)
 * @method int getContainsSensitiveData()
 * @method void setContainsSensitiveData(int $v)
 * @method int getContainsConfidentialData()
 * @method void setContainsConfidentialData(int $v)
 * @method int getSortOrder()
 * @method void setSortOrder(int $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class AccessRequestItem extends Entity {

    protected int $requestId = 0;
    protected string $sourceType = '';
    protected int $sourceId = 0;
    protected string $title = '';
    protected ?string $mimeType = null;
    protected ?string $documentDate = null;
    protected string $decision = 'pending';
    protected ?string $exclusionReason = null;
    protected ?string $legalReference = null;
    protected int $containsPersonalData = 0;
    protected int $containsSensitiveData = 0;
    protected int $containsConfidentialData = 0;
    protected int $sortOrder = 0;
    protected ?\DateTime $createdAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('requestId', 'integer');
        $this->addType('sourceId', 'integer');
        $this->addType('containsPersonalData', 'integer');
        $this->addType('containsSensitiveData', 'integer');
        $this->addType('containsConfidentialData', 'integer');
        $this->addType('sortOrder', 'integer');
        $this->addType('createdAt', 'datetime');
    }
}
