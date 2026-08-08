<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A journal note (journalnotat) attached to a case.
 *
 * @method int          getId()
 * @method int          getCaseId()
 * @method void         setCaseId(int $caseId)
 * @method string       getTitle()
 * @method void         setTitle(string $title)
 * @method string|null  getText()
 * @method void         setText(?string $text)
 * @method \DateTime|null getCreatedAt()
 * @method void         setCreatedAt(?\DateTime $createdAt)
 * @method string|null  getCreatedBy()
 * @method void         setCreatedBy(?string $createdBy)
 * @method \DateTime|null getUpdatedAt()
 * @method void         setUpdatedAt(?\DateTime $updatedAt)
 * @method int          getIsLocked()
 * @method void         setIsLocked(int $isLocked)
 */
class JournalNote extends Entity {

    protected int $caseId = 0;
    protected string $title = '';
    protected ?string $text = null;
    protected ?\DateTime $createdAt = null;
    protected ?string $createdBy = null;
    protected ?\DateTime $updatedAt = null;
    protected int $isLocked = 0;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('caseId', 'integer');
        $this->addType('title', 'string');
        $this->addType('text', 'string');
        $this->addType('createdAt', 'datetime');
        $this->addType('createdBy', 'string');
        $this->addType('updatedAt', 'datetime');
        $this->addType('isLocked', 'integer');
    }
}
