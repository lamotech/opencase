<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method string getLabel()
 * @method void setLabel(string $v)
 */
class AccessExclusionReason extends Entity {

    protected string $label = '';

    public function __construct() {
        $this->addType('id', 'integer');
    }
}
