<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method string getName()
 * @method void setName(string $name)
 */
class Role extends Entity {

    protected string $name = '';

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('name', 'string');
    }
}
