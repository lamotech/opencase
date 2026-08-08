<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A document template that can be used when creating new documents.
 *
 * Physical file is stored at {storage_base_dir}/_templates/{storage_filename}.
 *
 * @method int      getId()
 * @method string   getName()
 * @method void     setName(string $name)
 * @method string   getOriginalFilename()
 * @method void     setOriginalFilename(string $originalFilename)
 * @method string   getStorageFilename()
 * @method void     setStorageFilename(string $storageFilename)
 * @method string   getMimeType()
 * @method void     setMimeType(string $mimeType)
 * @method int      getSize()
 * @method void     setSize(int $size)
 * @method string   getUploadedBy()
 * @method void     setUploadedBy(string $uploadedBy)
 * @method ?\DateTime getCreatedAt()
 * @method void     setCreatedAt(\DateTime $createdAt)
 * @method ?int     getNcFileId()
 * @method void     setNcFileId(?int $ncFileId)
 * @method ?string  getNcOwner()
 * @method void     setNcOwner(?string $ncOwner)
 */
class TemplateEntity extends Entity {

    protected string $name = '';
    protected string $originalFilename = '';
    protected string $storageFilename = '';
    protected string $mimeType = '';
    protected int $size = 0;
    protected string $uploadedBy = '';
    protected ?\DateTime $createdAt = null;
    protected ?int $ncFileId = null;
    protected ?string $ncOwner = null;

    public function __construct() {
        $this->addType('name',             'string');
        $this->addType('originalFilename', 'string');
        $this->addType('storageFilename',  'string');
        $this->addType('mimeType',         'string');
        $this->addType('size',             'integer');
        $this->addType('uploadedBy',       'string');
        $this->addType('createdAt',        'datetime');
        $this->addType('ncFileId',         'integer');
        $this->addType('ncOwner',          'string');
    }
}
