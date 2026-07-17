<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A note attached to a document.
 *
 * @method int          getId()
 * @method int          getDocumentId()
 * @method void         setDocumentId(int $documentId)
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
 */
class DocumentNote extends Entity {

    protected int $documentId = 0;
    protected string $title = '';
    protected ?string $text = null;
    protected ?\DateTime $createdAt = null;
    protected ?string $createdBy = null;
    protected ?\DateTime $updatedAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('documentId', 'integer');
        $this->addType('title', 'string');
        $this->addType('text', 'string');
        $this->addType('createdAt', 'datetime');
        $this->addType('createdBy', 'string');
        $this->addType('updatedAt', 'datetime');
    }
}
