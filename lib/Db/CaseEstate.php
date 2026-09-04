<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * An estate linked to a case.
 *
 * @method int  getCaseId()
 * @method void setCaseId(int $caseId)
 * @method int  getEstateId()
 * @method void setEstateId(int $estateId)
 * @method int|null getRoleId()
 * @method void setRoleId(?int $roleId)
 */
class CaseEstate extends Entity {

    protected int $caseId = 0;
    protected int $estateId = 0;
    protected ?int $roleId = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('caseId', 'integer');
        $this->addType('estateId', 'integer');
        $this->addType('roleId', 'integer');
    }
}
