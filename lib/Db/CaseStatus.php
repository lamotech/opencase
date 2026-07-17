<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A case status (with per-language display names).
 *
 * The primary key is composite: (id, language).
 * The same numeric id maps to one row per language.
 *
 * @method int getId()
 * @method string getLanguage()
 * @method string getName()
 * @method int getIsClosed()
 * @method int getExpired()
 */
class CaseStatus extends Entity {

    protected string $language = '';
    protected string $name = '';
    protected int $isClosed = 0;
    protected int $expired = 0;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('language', 'string');
        $this->addType('name', 'string');
        $this->addType('isClosed', 'integer');
        $this->addType('expired', 'integer');
    }
}
