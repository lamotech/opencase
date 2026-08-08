<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method \DateTime getAddedAt()
 * @method void setAddedAt(\DateTime $addedAt)
 * @method string getEntity()
 * @method void setEntity(string $entity)
 * @method int getEntityKey()
 * @method void setEntityKey(int $entityKey)
 */
class FavoriteEntity extends Entity {

    protected string $userId = '';
    protected ?\DateTime $addedAt = null;
    protected string $entity = '';
    protected int $entityKey = 0;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('userId', 'string');
        $this->addType('addedAt', 'datetime');
        $this->addType('entity', 'string');
        $this->addType('entityKey', 'integer');
    }
}
