<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A single user step inside a document workflow.
 *
 * @method int      getId()
 * @method int      getWorkflowId()
 * @method void     setWorkflowId(int $workflowId)
 * @method string   getUserId()
 * @method void     setUserId(string $userId)
 * @method int      getSortOrder()
 * @method void     setSortOrder(int $sortOrder)
 * @method string   getStatus()
 * @method void     setStatus(string $status)
 * @method ?string  getComment()
 * @method void     setComment(?string $comment)
 * @method ?\DateTime getDeadline()
 * @method void     setDeadline(?\DateTime $deadline)
 * @method ?\DateTime getActedAt()
 * @method void     setActedAt(?\DateTime $actedAt)
 */
class DocWorkflowStepEntity extends Entity {

    protected int $workflowId = 0;
    protected string $userId = '';
    protected int $sortOrder = 0;
    protected string $status = '';
    protected ?string $comment = null;
    protected ?\DateTime $deadline = null;
    protected ?\DateTime $actedAt = null;

    public function __construct() {
        $this->addType('workflowId', 'integer');
        $this->addType('userId',     'string');
        $this->addType('sortOrder',  'integer');
        $this->addType('status',     'string');
        $this->addType('comment',    'string');
        $this->addType('deadline',   'datetime');
        $this->addType('actedAt',    'datetime');
    }

    public function isPending(): bool {
        return $this->status === 'pending';
    }
}
