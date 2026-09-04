<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A printable, QR-identified separation sheet.
 *
 * @method string getType()
 * @method void   setType(string $type)
 * @method string|null getName()
 * @method void   setName(?string $name)
 * @method string|null getCaseNumber()
 * @method void   setCaseNumber(?string $caseNumber)
 * @method string|null getTitle()
 * @method void   setTitle(?string $title)
 * @method string|null getOrgUuid()
 * @method void   setOrgUuid(?string $orgUuid)
 * @method string|null getClassSubjectUuid()
 * @method void   setClassSubjectUuid(?string $classSubjectUuid)
 * @method string|null getSensitivityUuid()
 * @method void   setSensitivityUuid(?string $sensitivityUuid)
 * @method string|null getClassificationFacetUuid()
 * @method void   setClassificationFacetUuid(?string $classificationFacetUuid)
 * @method int|null getInsightLevelId()
 * @method void   setInsightLevelId(?int $insightLevelId)
 * @method string|null getResponsibleUserId()
 * @method void   setResponsibleUserId(?string $responsibleUserId)
 */
class SeparationSheet extends Entity {

    protected string $type = '';
    protected ?string $name = null;
    protected ?string $caseNumber = null;
    protected ?string $title = null;
    protected ?string $orgUuid = null;
    protected ?string $classSubjectUuid = null;
    protected ?string $sensitivityUuid = null;
    protected ?string $classificationFacetUuid = null;
    protected ?int $insightLevelId = null;
    protected ?string $responsibleUserId = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('type', 'string');
        $this->addType('name', 'string');
        $this->addType('caseNumber', 'string');
        $this->addType('title', 'string');
        $this->addType('orgUuid', 'string');
        $this->addType('classSubjectUuid', 'string');
        $this->addType('sensitivityUuid', 'string');
        $this->addType('classificationFacetUuid', 'string');
        $this->addType('insightLevelId', 'integer');
        $this->addType('responsibleUserId', 'string');
    }
}
