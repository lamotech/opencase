<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getRequestItemId()
 * @method void setRequestItemId(int $v)
 * @method string getRedactionType()
 * @method void setRedactionType(string $v)
 * @method string|null getOriginalText()
 * @method void setOriginalText(?string $v)
 * @method string getReplacementText()
 * @method void setReplacementText(string $v)
 * @method string|null getReason()
 * @method void setReason(?string $v)
 * @method string|null getLegalReference()
 * @method void setLegalReference(?string $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class AccessRedaction extends Entity {

    protected int $requestItemId = 0;
    protected string $redactionType = '';
    protected ?string $originalText = null;
    protected string $replacementText = '';
    protected ?string $reason = null;
    protected ?string $legalReference = null;
    protected string $createdBy = '';
    protected ?\DateTime $createdAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('requestItemId', 'integer');
        $this->addType('createdAt', 'datetime');
    }
}
