<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Represents a unique (org_uuid, class_subject_uuid, sensitivity_uuid) tuple.
 *
 * All three fields are FKs into their respective lookup tables. Use
 * OrganisationMapper, ClassificationSubjectMapper, and SensitivityMapper
 * to resolve UUIDs to human-readable values.
 *
 * @method string getOrgUuid()
 * @method void setOrgUuid(string $orgUuid)
 * @method string getClassSubjectUuid()
 * @method void setClassSubjectUuid(string $classSubjectUuid)
 * @method string getSensitivityUuid()
 * @method void setSensitivityUuid(string $sensitivityUuid)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class AccessProfile extends Entity {

    protected string $orgUuid = '';
    protected string $classSubjectUuid = '';
    protected string $sensitivityUuid = '';
    protected ?\DateTime $createdAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('orgUuid', 'string');
        $this->addType('classSubjectUuid', 'string');
        $this->addType('sensitivityUuid', 'string');
        $this->addType('createdAt', 'datetime');
    }
}
