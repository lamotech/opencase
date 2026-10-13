<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A directed relation between two documents (from → to), typed by
 * opencase_documentrelationtype.
 *
 * @method int            getId()
 * @method int            getDocumentFromId()
 * @method void           setDocumentFromId(int $documentFromId)
 * @method int            getDocumentToId()
 * @method void           setDocumentToId(int $documentToId)
 * @method int            getDocumentrelationtypeId()
 * @method void           setDocumentrelationtypeId(int $documentrelationtypeId)
 * @method \DateTime|null getCreatedAt()
 * @method void           setCreatedAt(?\DateTime $createdAt)
 * @method string|null    getCreatedBy()
 * @method void           setCreatedBy(?string $createdBy)
 */
class DocumentRelation extends Entity {

    protected int $documentFromId = 0;
    protected int $documentToId = 0;
    protected int $documentrelationtypeId = 0;
    protected ?\DateTime $createdAt = null;
    protected ?string $createdBy = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('documentFromId', 'integer');
        $this->addType('documentToId', 'integer');
        $this->addType('documentrelationtypeId', 'integer');
        $this->addType('createdAt', 'datetime');
        $this->addType('createdBy', 'string');
    }
}
