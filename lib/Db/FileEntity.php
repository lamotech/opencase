<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A physical file attached to a document.
 *
 * @method int getDocumentId()
 * @method void setDocumentId(int $documentId)
 * @method int getCaseId()
 * @method void setCaseId(int $caseId)
 * @method string getOriginalFilename()
 * @method void setOriginalFilename(string $originalFilename)
 * @method string getStorageFilename()
 * @method void setStorageFilename(string $storageFilename)
 * @method string getVirtualFilename()
 * @method void setVirtualFilename(string $virtualFilename)
 * @method string getMimeType()
 * @method void setMimeType(string $mimeType)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method int getVersion()
 * @method void setVersion(int $version)
 * @method string|null getChecksum()
 * @method void setChecksum(?string $checksum)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method string|null getLastModifiedBy()
 * @method void setLastModifiedBy(?string $lastModifiedBy)
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 */
class FileEntity extends Entity {

    protected int $documentId = 0;
    protected int $caseId = 0;
    protected string $originalFilename = '';
    protected string $storageFilename = '';
    protected string $virtualFilename = '';
    protected string $mimeType = '';
    protected int $size = 0;
    protected int $version = 1;
    protected ?string $checksum = null;
    protected ?\DateTime $createdAt = null;
    protected ?\DateTime $updatedAt = null;
    protected string $createdBy = '';
    protected ?string $lastModifiedBy = null;
    protected string $uuid = '';

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('documentId', 'integer');
        $this->addType('caseId', 'integer');
        $this->addType('originalFilename', 'string');
        $this->addType('storageFilename', 'string');
        $this->addType('virtualFilename', 'string');
        $this->addType('mimeType', 'string');
        $this->addType('size', 'integer');
        $this->addType('version', 'integer');
        $this->addType('checksum', 'string');
        $this->addType('createdAt', 'datetime');
        $this->addType('updatedAt', 'datetime');
        $this->addType('createdBy', 'string');
        $this->addType('lastModifiedBy', 'string');
        $this->addType('uuid', 'string');
    }
}
