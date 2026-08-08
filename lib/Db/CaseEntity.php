<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A case (sag) in the system.
 *
 * Named CaseEntity to avoid collision with PHP reserved word 'case'.
 *
 * @method string getCaseNumber()
 * @method void setCaseNumber(string $caseNumber)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method int getAccessProfileId()
 * @method void setAccessProfileId(int $accessProfileId)
 * @method string getOrgUuid()
 * @method void setOrgUuid(string $orgUuid)
 * @method string getYear()
 * @method void setYear(string $year)
 * @method int getStatusId()
 * @method void setStatusId(int $statusId)
 * @method int|null getCasetypeId()
 * @method void setCasetypeId(?int $casetypeId)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method string|null getResponsibleUserId()
 * @method void setResponsibleUserId(?string $responsibleUserId)
 * @method int|null getInsightLevelId()
 * @method void setInsightLevelId(?int $insightLevelId)
 * @method string|null getClassificationFacetUuid()
 * @method void setClassificationFacetUuid(?string $classificationFacetUuid)
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method bool getIsInbox()
 * @method void setIsInbox(bool $isInbox)
 * @method int|null getParentCaseId()
 * @method void setParentCaseId(?int $parentCaseId)
 * @method \DateTime|null getExportedAt()
 * @method void setExportedAt(?\DateTime $exportedAt)
 * @method string|null getSummary()
 * @method void setSummary(?string $summary)
 */
class CaseEntity extends Entity {

    protected string $caseNumber = '';
    protected string $title = '';
    protected int $accessProfileId = 0;
    protected string $orgUuid = '';
    protected string $year = '';
    protected int $statusId = 1;
    protected ?int $casetypeId = 1;
    protected ?\DateTime $createdAt = null;
    protected ?\DateTime $updatedAt = null;
    protected string $createdBy = '';
    protected ?string $responsibleUserId = null;
    protected ?int $insightLevelId = null;
    protected ?string $classificationFacetUuid = null;
    protected ?string $uuid = null;
    protected bool $isInbox = false;
    protected ?int $parentCaseId = null;
    protected ?\DateTime $exportedAt = null;
    protected ?string $summary = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('caseNumber', 'string');
        $this->addType('title', 'string');
        $this->addType('accessProfileId', 'integer');
        $this->addType('orgUuid', 'string');
        $this->addType('year', 'string');
        $this->addType('statusId', 'integer');
        $this->addType('casetypeId', 'integer');
        $this->addType('createdAt', 'datetime');
        $this->addType('updatedAt', 'datetime');
        $this->addType('createdBy', 'string');
        $this->addType('responsibleUserId', 'string');
        $this->addType('insightLevelId', 'integer');
        $this->addType('classificationFacetUuid', 'string');
        $this->addType('uuid', 'string');
        $this->addType('isInbox', 'boolean');
        $this->addType('parentCaseId', 'integer');
        $this->addType('exportedAt', 'datetime');
        $this->addType('summary', 'string');
    }

    // ---------------------------------------------------------------
    // Virtual filesystem path helpers
    //
    // The virtual path is built from the case's creation date and its
    // case number: {year}/{month}/{day}/{case_number}
    // This makes the path immutable — the creation date never changes.
    // ---------------------------------------------------------------

    /**
     * Returns the 4-digit creation year, e.g. "2026".
     * Returns empty string if created_at is null.
     */
    public function getCreatedYear(): string {
        return $this->createdAt?->format('Y') ?? '';
    }

    /**
     * Returns the zero-padded 2-digit creation month, e.g. "03".
     * Returns empty string if created_at is null.
     */
    public function getCreatedMonth(): string {
        return $this->createdAt?->format('m') ?? '';
    }

    /**
     * Returns the zero-padded 2-digit creation day, e.g. "01".
     * Returns empty string if created_at is null.
     */
    public function getCreatedDay(): string {
        return $this->createdAt?->format('d') ?? '';
    }
}
