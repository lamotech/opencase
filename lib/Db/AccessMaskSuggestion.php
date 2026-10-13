<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getRequestItemId()
 * @method void setRequestItemId(int $v)
 * @method int getFileId()
 * @method void setFileId(int $v)
 * @method int getPage()
 * @method void setPage(int $v)
 * @method string getCategory()
 * @method void setCategory(string $v)
 * @method string getText()
 * @method void setText(string $v)
 * @method string|null getContext()
 * @method void setContext(?string $v)
 * @method string getRects()
 * @method void setRects(string $v)
 * @method string|null getLabel()
 * @method void setLabel(?string $v)
 * @method string|null getLegalReference()
 * @method void setLegalReference(?string $v)
 * @method string getSource()
 * @method void setSource(string $v)
 * @method string getStatus()
 * @method void setStatus(string $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?\DateTime $v)
 */
class AccessMaskSuggestion extends Entity {

    protected int $requestItemId = 0;
    protected int $fileId = 0;
    protected int $page = 0;
    protected string $category = '';
    protected string $text = '';
    protected ?string $context = null;
    protected string $rects = '[]';
    protected ?string $label = null;
    protected ?string $legalReference = null;
    protected string $source = 'rule';
    protected string $status = 'suggested';
    protected string $createdBy = '';
    protected ?\DateTime $createdAt = null;
    protected ?\DateTime $updatedAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('requestItemId', 'integer');
        $this->addType('fileId', 'integer');
        $this->addType('page', 'integer');
        $this->addType('createdAt', 'datetime');
        $this->addType('updatedAt', 'datetime');
    }
}
