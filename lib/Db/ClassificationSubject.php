<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Represents a KLE classification subject (emneord).
 *
 * The table uses uuid (VARCHAR 64) as its primary key rather than an
 * auto-increment integer. NC's Entity base class is used for property
 * mapping only; the inherited $id field is unused for this entity.
 *
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method string getCode()
 * @method void setCode(string $code)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method int getActive()
 * @method void setActive(int $active)
 * @method int|null getDisposalcodeId()
 * @method void setDisposalcodeId(?int $disposalcodeId)
 * @method int|null getDeletionDeadlineId()
 * @method void setDeletionDeadlineId(?int $deletionDeadlineId)
 */
class ClassificationSubject extends Entity {

    protected string $uuid = '';
    protected string $code = '';
    protected string $title = '';
    protected ?string $description = null;
    protected int $active = 1;
    protected ?int $disposalcodeId = null;
    protected ?int $deletionDeadlineId = null;

    public function __construct() {
        $this->addType('uuid', 'string');
        $this->addType('code', 'string');
        $this->addType('title', 'string');
        $this->addType('description', 'string');
        $this->addType('active', 'integer');
        $this->addType('disposalcodeId', 'integer');
        $this->addType('deletionDeadlineId', 'integer');
    }
}
