<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A single entry in the case audit log.
 *
 * @method int getCaseId()
 * @method void setCaseId(int $caseId)
 * @method int|null getDocumentId()
 * @method void setDocumentId(?int $documentId)
 * @method int|null getFileId()
 * @method void setFileId(?int $fileId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getEventType()
 * @method void setEventType(string $eventType)
 * @method string|null getDetails()
 * @method void setDetails(?string $details)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class AuditLogEntry extends Entity {

    protected int $caseId = 0;
    protected ?int $documentId = null;
    protected ?int $fileId = null;
    protected string $userId = '';
    protected string $eventType = '';
    protected ?string $details = null;
    protected ?\DateTime $createdAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('caseId', 'integer');
        $this->addType('documentId', 'integer');
        $this->addType('fileId', 'integer');
        $this->addType('userId', 'string');
        $this->addType('eventType', 'string');
        $this->addType('details', 'string');
        $this->addType('createdAt', 'datetime');
    }

    /**
     * Decode the JSON details field into an array.
     */
    public function getDetailsArray(): array {
        if ($this->details === null || $this->details === '') {
            return [];
        }
        return json_decode($this->details, true) ?? [];
    }
}
