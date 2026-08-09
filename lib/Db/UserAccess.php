<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Maps a user to an access profile with a read or write level.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getAccessProfileId()
 * @method void setAccessProfileId(int $accessProfileId)
 * @method string getAccessLevel()
 * @method void setAccessLevel(string $accessLevel)
 * @method \DateTime getGrantedAt()
 * @method void setGrantedAt(\DateTime $grantedAt)
 * @method string|null getGrantedBy()
 * @method void setGrantedBy(?string $grantedBy)
 * @method string getGrantSource()
 * @method void setGrantSource(string $grantSource)
 */
class UserAccess extends Entity {

    protected string $userId = '';
    protected int $accessProfileId = 0;
    protected string $accessLevel = 'read';
    protected ?\DateTime $grantedAt = null;
    protected ?string $grantedBy = null;
    protected string $grantSource = 'manual';

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('userId', 'string');
        $this->addType('accessProfileId', 'integer');
        $this->addType('accessLevel', 'string');
        $this->addType('grantedAt', 'datetime');
        $this->addType('grantedBy', 'string');
        $this->addType('grantSource', 'string');
    }
}
