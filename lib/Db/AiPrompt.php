<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getScope()
 * @method void setScope(string $scope)
 * @method string getPrompt()
 * @method void setPrompt(string $prompt)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime|null getLastUsedAt()
 * @method void setLastUsedAt(?\DateTime $lastUsedAt)
 */
class AiPrompt extends Entity {

    protected string $title     = '';
    protected string $scope     = 'all';
    protected string $prompt    = '';
    protected string $createdBy = '';
    protected ?\DateTime $createdAt  = null;
    protected ?\DateTime $lastUsedAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('title', 'string');
        $this->addType('scope', 'string');
        $this->addType('prompt', 'string');
        $this->addType('createdBy', 'string');
        $this->addType('createdAt', 'datetime');
        $this->addType('lastUsedAt', 'datetime');
    }
}
