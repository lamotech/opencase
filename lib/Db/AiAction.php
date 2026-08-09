<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method int getPromptId()
 * @method void setPromptId(int $promptId)
 * @method int getSequenceIndex()
 * @method void setSequenceIndex(int $sequenceIndex)
 * @method string getActionType()
 * @method void setActionType(string $actionType)
 * @method string getActionParams()
 * @method void setActionParams(string $actionParams)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class AiAction extends Entity {

    protected int $promptId       = 0;
    protected int $sequenceIndex  = 0;
    protected string $actionType  = '';
    protected string $actionParams = '{}';
    protected ?\DateTime $createdAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('promptId', 'integer');
        $this->addType('sequenceIndex', 'integer');
        $this->addType('actionType', 'string');
        $this->addType('actionParams', 'string');
        $this->addType('createdAt', 'datetime');
    }
}
