<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * An access profile. Cases reference one by id, and users are granted it via
 * opencase_user_access. What the profile represents depends on profile_type:
 *
 *   'fk'    — a unique (org_uuid, class_subject_uuid, sensitivity_uuid) tuple.
 *             All three fields are FKs into their respective lookup tables. Use
 *             OrganisationMapper, ClassificationSubjectMapper, and
 *             SensitivityMapper to resolve UUIDs to human-readable values.
 *   'group' — a Nextcloud group (group_id = oc_groups.gid); the three tuple
 *             fields are NULL. group_deleted = 1: the group was deleted while
 *             cases used the profile, so a new group with that id doesn't get
 *             them until an administrator registers it again.
 *
 * @method string getProfileType()
 * @method void setProfileType(string $profileType)
 * @method string|null getGroupId()
 * @method void setGroupId(?string $groupId)
 * @method int getGroupDeleted()
 * @method void setGroupDeleted(int $groupDeleted)
 * @method string|null getOrgUuid()
 * @method void setOrgUuid(?string $orgUuid)
 * @method string|null getClassSubjectUuid()
 * @method void setClassSubjectUuid(?string $classSubjectUuid)
 * @method string|null getSensitivityUuid()
 * @method void setSensitivityUuid(?string $sensitivityUuid)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class AccessProfile extends Entity {

    public const TYPE_FK    = 'fk';
    public const TYPE_GROUP = 'group';

    protected string $profileType = self::TYPE_FK;
    protected ?string $groupId = null;
    protected int $groupDeleted = 0;
    protected ?string $orgUuid = null;
    protected ?string $classSubjectUuid = null;
    protected ?string $sensitivityUuid = null;
    protected ?\DateTime $createdAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('orgUuid', 'string');
        $this->addType('classSubjectUuid', 'string');
        $this->addType('sensitivityUuid', 'string');
        $this->addType('profileType', 'string');
        $this->addType('groupId', 'string');
        $this->addType('groupDeleted', 'integer');
        $this->addType('createdAt', 'datetime');
    }

    public function isGroupDeleted(): bool {
        return $this->groupDeleted === 1;
    }
}
