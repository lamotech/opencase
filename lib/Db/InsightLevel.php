<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * An insight level (indsigtsgrad) with per-language display names and descriptions.
 *
 * The primary key is composite: (id, language).
 * The same numeric id maps to one row per language.
 *
 * @method int getId()
 * @method string getLanguage()
 * @method string getName()
 * @method string|null getDescription()
 * @method int getExpired()
 */
class InsightLevel extends Entity {

    protected string $language = '';
    protected string $name = '';
    protected ?string $description = null;
    protected int $expired = 0;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('language', 'string');
        $this->addType('name', 'string');
        $this->addType('description', 'string');
        $this->addType('expired', 'integer');
    }
}
