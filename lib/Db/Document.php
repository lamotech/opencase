<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A document belonging to a case.
 * Documents are the metadata container; files are the actual attachments.
 *
 * @method int getCaseId()
 * @method void setCaseId(int $caseId)
 * @method string|null getDocumentNumber()
 * @method void setDocumentNumber(?string $documentNumber)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getDocumentType()
 * @method void setDocumentType(?string $documentType)
 * @method int getStatus()
 * @method void setStatus(int $status)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method int|null getInsightLevelId()
 * @method void setInsightLevelId(?int $insightLevelId)
 * @method string|null getDocumentDate()
 * @method void setDocumentDate(?string $documentDate)
 * @method string|null getReceivedDate()
 * @method void setReceivedDate(?string $receivedDate)
 * @method string|null getRegisteredDate()
 * @method void setRegisteredDate(?string $registeredDate)
 * @method int|null getDocumentCategoryId()
 * @method void setDocumentCategoryId(?int $documentCategoryId)
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 */
class Document extends Entity {

    protected int $caseId = 0;
    protected ?string $documentNumber = null;
    protected string $title = '';
    protected ?string $documentType = null;
    protected int $status = 1;
    protected ?\DateTime $createdAt = null;
    protected ?\DateTime $updatedAt = null;
    protected string $createdBy = '';
    protected ?int $insightLevelId = null;
    protected ?string $documentDate = null;
    protected ?string $receivedDate = null;
    protected ?string $registeredDate = null;
    protected ?int $documentCategoryId = null;
    protected ?string $uuid = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('caseId', 'integer');
        $this->addType('documentNumber', 'string');
        $this->addType('title', 'string');
        $this->addType('documentType', 'string');
        $this->addType('status', 'integer');
        $this->addType('createdAt', 'datetime');
        $this->addType('updatedAt', 'datetime');
        $this->addType('createdBy', 'string');
        $this->addType('insightLevelId', 'integer');
        $this->addType('documentDate', 'string');
        $this->addType('receivedDate', 'string');
        $this->addType('registeredDate', 'string');
        $this->addType('documentCategoryId', 'integer');
        $this->addType('uuid', 'string');
    }
}
