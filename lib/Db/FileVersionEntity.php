<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method int getTimestamp()
 * @method void setTimestamp(int $timestamp)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method string getMimeType()
 * @method void setMimeType(string $mimeType)
 * @method ?string getChecksum()
 * @method void setChecksum(?string $checksum)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method ?\DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class FileVersionEntity extends Entity {

    protected int $fileId = 0;
    protected int $timestamp = 0;
    protected int $size = 0;
    protected string $mimeType = '';
    protected ?string $checksum = null;
    protected string $createdBy = '';
    protected ?\DateTime $createdAt = null;

    public function __construct() {
        $this->addType('fileId', 'integer');
        $this->addType('timestamp', 'integer');
        $this->addType('size', 'integer');
        $this->addType('mimeType', 'string');
        $this->addType('checksum', 'string');
        $this->addType('createdBy', 'string');
        $this->addType('createdAt', 'datetime');
    }
}
