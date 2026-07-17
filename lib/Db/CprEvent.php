<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A CPR (Det Centrale Personregister) event type reference row.
 *
 * @method string getCode()
 * @method void   setCode(string $code)
 * @method string getDescription()
 * @method void   setDescription(string $description)
 * @method bool   getEnabled()
 * @method void   setEnabled(bool $enabled)
 * @method bool   getLogOnCase()
 * @method void   setLogOnCase(bool $logOnCase)
 * @method bool   getLogOnDocument()
 * @method void   setLogOnDocument(bool $logOnDocument)
 * @method bool   getSendNotification()
 * @method void   setSendNotification(bool $sendNotification)
 */
class CprEvent extends Entity {

    protected string $code = '';
    protected string $description = '';
    protected bool $enabled = true;
    protected bool $logOnCase = true;
    protected bool $logOnDocument = true;
    protected bool $sendNotification = true;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('code', 'string');
        $this->addType('description', 'string');
        $this->addType('enabled', 'boolean');
        $this->addType('logOnCase', 'boolean');
        $this->addType('logOnDocument', 'boolean');
        $this->addType('sendNotification', 'boolean');
    }
}
