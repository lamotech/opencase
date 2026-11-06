<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Represents a row in the opencase_process_version table — the BPMN XML of
 * one version of a process (opencase_process_def). Only a draft can be
 * edited; publishing deploys it to Flowable and stores the deployment and
 * process definition ids.
 *
 * @method int getProcessDefId()
 * @method void setProcessDefId(int $processDefId)
 * @method int getVersionNo()
 * @method void setVersionNo(int $versionNo)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getBpmnXml()
 * @method void setBpmnXml(string $bpmnXml)
 * @method string getChecksum()
 * @method void setChecksum(string $checksum)
 * @method string|null getChangeNote()
 * @method void setChangeNote(?string $changeNote)
 * @method string|null getFlowableDeploymentId()
 * @method void setFlowableDeploymentId(?string $flowableDeploymentId)
 * @method string|null getFlowableProcDefId()
 * @method void setFlowableProcDefId(?string $flowableProcDefId)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?\DateTime $updatedAt)
 * @method string|null getUpdatedBy()
 * @method void setUpdatedBy(?string $updatedBy)
 * @method \DateTime|null getPublishedAt()
 * @method void setPublishedAt(?\DateTime $publishedAt)
 * @method string|null getPublishedBy()
 * @method void setPublishedBy(?string $publishedBy)
 */
class ProcessVersion extends Entity {

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_SUPERSEDED = 'superseded';

    protected int $processDefId = 0;
    protected int $versionNo = 0;
    // No default: Entity only inserts fields whose value was changed by a setter
    protected string $status = '';
    protected string $bpmnXml = '';
    protected string $checksum = '';
    protected ?string $changeNote = null;
    protected ?string $flowableDeploymentId = null;
    protected ?string $flowableProcDefId = null;
    protected ?\DateTime $createdAt = null;
    protected ?string $createdBy = null;
    protected ?\DateTime $updatedAt = null;
    protected ?string $updatedBy = null;
    protected ?\DateTime $publishedAt = null;
    protected ?string $publishedBy = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('processDefId', 'integer');
        $this->addType('versionNo', 'integer');
        $this->addType('status', 'string');
        $this->addType('bpmnXml', 'string');
        $this->addType('checksum', 'string');
        $this->addType('changeNote', 'string');
        $this->addType('flowableDeploymentId', 'string');
        $this->addType('flowableProcDefId', 'string');
        $this->addType('createdAt', 'datetime');
        $this->addType('createdBy', 'string');
        $this->addType('updatedAt', 'datetime');
        $this->addType('updatedBy', 'string');
        $this->addType('publishedAt', 'datetime');
        $this->addType('publishedBy', 'string');
    }
}
