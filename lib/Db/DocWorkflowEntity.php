<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A document workflow instance.
 *
 * @method int      getId()
 * @method int      getDocumentId()
 * @method void     setDocumentId(int $documentId)
 * @method string   getType()
 * @method void     setType(string $type)
 * @method string   getStatus()
 * @method void     setStatus(string $status)
 * @method ?\DateTime getDeadline()
 * @method void     setDeadline(?\DateTime $deadline)
 * @method string   getCreatedBy()
 * @method void     setCreatedBy(string $createdBy)
 * @method \DateTime getCreatedAt()
 * @method void     setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void     setUpdatedAt(\DateTime $updatedAt)
 */
class DocWorkflowEntity extends Entity {

    protected int $documentId = 0;
    protected string $type = '';
    protected string $status = '';
    protected ?\DateTime $deadline = null;
    protected string $createdBy = '';
    protected ?\DateTime $createdAt = null;
    protected ?\DateTime $updatedAt = null;

    public function __construct() {
        $this->addType('documentId', 'integer');
        $this->addType('type',       'string');
        $this->addType('status',     'string');
        $this->addType('deadline',   'datetime');
        $this->addType('createdBy',  'string');
        $this->addType('createdAt',  'datetime');
        $this->addType('updatedAt',  'datetime');
    }

    public function isActive(): bool {
        return $this->status === 'active';
    }
}
