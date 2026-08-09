<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method string getUsername()
 * @method void setUsername(string $username)
 * @method string getPersonname()
 * @method void setPersonname(string $personname)
 * @method string getEmail()
 * @method void setEmail(string $email)
 * @method string getPhone()
 * @method void setPhone(string $phone)
 * @method string getLocation()
 * @method void setLocation(string $location)
 * @method string|null getUserId()
 * @method void setUserId(string $userId)
 */
class UserInfo extends Entity {

    protected string $uuid = '';
    protected string $username = '';
    protected string $personname = '';
    protected string $email = '';
    protected string $phone = '';
    protected string $location = '';
    protected ?string $userId = null;

    public function __construct() {
        $this->addType('uuid', 'string');
        $this->addType('username', 'string');
        $this->addType('personname', 'string');
        $this->addType('email', 'string');
        $this->addType('phone', 'string');
        $this->addType('location', 'string');
        $this->addType('userId', 'string');
    }
}
