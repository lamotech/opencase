<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Represents a sensitivity level (følsomhedsniveau).
 *
 * The table uses uuid (VARCHAR 64) as its primary key rather than an
 * auto-increment integer. NC's Entity base class is used for property
 * mapping only; the inherited $id field is unused for this entity.
 *
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method string getKey()
 * @method void setKey(string $key)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method int getActive()
 * @method void setActive(int $active)
 */
class Sensitivity extends Entity {

    protected string $uuid = '';
    protected string $key = '';
    protected string $title = '';
    protected ?string $description = null;
    protected int $active = 1;

    public function __construct() {
        $this->addType('uuid', 'string');
        $this->addType('key', 'string');
        $this->addType('title', 'string');
        $this->addType('description', 'string');
        $this->addType('active', 'integer');
    }
}
