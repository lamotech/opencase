<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getRoleId()
 * @method void setRoleId(int $roleId)
 * @method \DateTime getAssignedAt()
 * @method void setAssignedAt(\DateTime $assignedAt)
 * @method string|null getAssignedBy()
 * @method void setAssignedBy(?string $assignedBy)
 */
class UserRole extends Entity {

    protected string $userId = '';
    protected int $roleId = 0;
    protected ?\DateTime $assignedAt = null;
    protected ?string $assignedBy = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('userId', 'string');
        $this->addType('roleId', 'integer');
        $this->addType('assignedAt', 'datetime');
        $this->addType('assignedBy', 'string');
    }
}
