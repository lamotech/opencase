<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Represents a row in the opencase_phrases_scope table — one restriction
 * (casetype, organisation, or KLE pattern) on a phrase (opencase_phrases).
 *
 * @method int getPhrasesId()
 * @method void setPhrasesId(int $phrasesId)
 * @method string getScopeType()
 * @method void setScopeType(string $scopeType)
 * @method string getScopeValue()
 * @method void setScopeValue(string $scopeValue)
 */
class PhraseScope extends Entity {

    protected int $phrasesId = 0;
    protected string $scopeType = '';
    protected string $scopeValue = '';

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('phrasesId', 'integer');
        $this->addType('scopeType', 'string');
        $this->addType('scopeValue', 'string');
    }
}
