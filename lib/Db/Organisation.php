<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Represents a row in the opencase_org table.
 *
 * The primary key is org_uuid (not the usual auto-increment id).
 * NC's Entity base class is used for property mapping only; the inherited
 * $id field is unused for this entity.
 *
 * @method string getOrgUuid()
 * @method void setOrgUuid(string $orgUuid)
 * @method string getOrgName()
 * @method void setOrgName(string $orgName)
 * @method string getOrgParentUuid()
 * @method void setOrgParentUuid(string $orgParentUuid)
 * @method int getActive()
 * @method void setActive(int $active)
 * @method string|null getEmail()
 * @method void setEmail(?string $email)
 * @method string|null getPhone()
 * @method void setPhone(?string $phone)
 * @method string|null getLocation()
 * @method void setLocation(?string $location)
 * @method string|null getNcGroupId()
 * @method void setNcGroupId(?string $ncGroupId)
 * @method int|null getGroupfolderId()
 * @method void setGroupfolderId(?int $groupfolderId)
 */
class Organisation extends Entity {

    protected string $orgUuid = '';
    protected string $orgName = '';
    protected string $orgParentUuid = '';
    protected int $active = 1;
    protected ?string $email = null;
    protected ?string $phone = null;
    protected ?string $location = null;
    protected ?string $ncGroupId = null;
    protected ?int $groupfolderId = null;

    public function __construct() {
        $this->addType('orgUuid', 'string');
        $this->addType('orgName', 'string');
        $this->addType('orgParentUuid', 'string');
        $this->addType('active', 'integer');
        $this->addType('email', 'string');
        $this->addType('phone', 'string');
        $this->addType('location', 'string');
        $this->addType('ncGroupId', 'string');
        $this->addType('groupfolderId', 'integer');
    }
}
