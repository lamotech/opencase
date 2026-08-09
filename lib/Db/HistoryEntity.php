<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method \DateTime getAccessedAt()
 * @method void setAccessedAt(\DateTime $accessedAt)
 * @method string getEntity()
 * @method void setEntity(string $entity)
 * @method int getEntityKey()
 * @method void setEntityKey(int $entityKey)
 */
class HistoryEntity extends Entity {

    protected string $userId = '';
    protected ?\DateTime $accessedAt = null;
    protected string $entity = '';
    protected int $entityKey = 0;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('userId', 'string');
        $this->addType('accessedAt', 'datetime');
        $this->addType('entity', 'string');
        $this->addType('entityKey', 'integer');
    }
}
