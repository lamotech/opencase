<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A case type (with per-language display names).
 *
 * The primary key is composite: (id, language).
 * The same numeric id maps to one row per language.
 *
 * @method int getId()
 * @method string getLanguage()
 * @method string getName()
 * @method int getExpired()
 * @method string getPrimaryParticipant()
 */
class CaseType extends Entity {

    protected string $language = '';
    protected string $name = '';
    protected int $expired = 0;
    protected string $primaryParticipant = 'None';

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('language', 'string');
        $this->addType('name', 'string');
        $this->addType('expired', 'integer');
        $this->addType('primaryParticipant', 'string');
    }
}
