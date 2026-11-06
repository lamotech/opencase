<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Represents a row in the opencase_phrases table — a formatted text fragment
 * in the Brevdesigner phrase library. The content is a document file stored
 * at {storage_base_dir}/_phrases/{storage_filename}, or — while it is edited
 * in Nextcloud Office — the live copy nc_file_id in nc_owner's home.
 * Without a file, the phrase text is the rich text in content (HTML).
 *
 * @method int|null getCategoryId()
 * @method void setCategoryId(?int $categoryId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getContent()
 * @method void setContent(?string $content)
 * @method string|null getOriginalFilename()
 * @method void setOriginalFilename(?string $originalFilename)
 * @method string|null getStorageFilename()
 * @method void setStorageFilename(?string $storageFilename)
 * @method string|null getMimeType()
 * @method void setMimeType(?string $mimeType)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method int|null getNcFileId()
 * @method void setNcFileId(?int $ncFileId)
 * @method string|null getNcOwner()
 * @method void setNcOwner(?string $ncOwner)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?\DateTime $updatedAt)
 * @method string|null getUpdatedBy()
 * @method void setUpdatedBy(?string $updatedBy)
 */
class Phrase extends Entity {

    public const STATUSES = ['draft', 'active', 'archived'];

    protected ?int $categoryId = null;
    protected string $title = '';
    protected ?string $description = null;
    protected ?string $content = null;
    protected ?string $originalFilename = null;
    protected ?string $storageFilename = null;
    protected ?string $mimeType = null;
    protected int $size = 0;
    protected ?int $ncFileId = null;
    protected ?string $ncOwner = null;
    protected string $status = 'draft';
    protected ?\DateTime $createdAt = null;
    protected ?string $createdBy = null;
    protected ?\DateTime $updatedAt = null;
    protected ?string $updatedBy = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('categoryId', 'integer');
        $this->addType('title', 'string');
        $this->addType('description', 'string');
        $this->addType('content', 'string');
        $this->addType('originalFilename', 'string');
        $this->addType('storageFilename', 'string');
        $this->addType('mimeType', 'string');
        $this->addType('size', 'integer');
        $this->addType('ncFileId', 'integer');
        $this->addType('ncOwner', 'string');
        $this->addType('status', 'string');
        $this->addType('createdAt', 'datetime');
        $this->addType('createdBy', 'string');
        $this->addType('updatedAt', 'datetime');
        $this->addType('updatedBy', 'string');
    }

    public function hasFile(): bool {
        return $this->storageFilename !== null && $this->storageFilename !== '';
    }

    /**
     * Whether the phrase has text to insert into a letter: a file, or else
     * rich text (content).
     */
    public function hasContent(): bool {
        return $this->hasFile() || \OCA\OpenCase\Service\PhraseHtml::hasText($this->content);
    }
}
