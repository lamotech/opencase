<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Represents a row in the opencase_process_job table — an automatic step
 * (BPMN service task) of a process on a case, run by OpenCase.
 *
 * @method string getFlowableJobId()
 * @method void setFlowableJobId(string $flowableJobId)
 * @method int getCaseProcessId()
 * @method void setCaseProcessId(int $caseProcessId)
 * @method int getCaseId()
 * @method void setCaseId(int $caseId)
 * @method string getElementId()
 * @method void setElementId(string $elementId)
 * @method string|null getElementName()
 * @method void setElementName(?string $elementName)
 * @method string getTopic()
 * @method void setTopic(string $topic)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getActedAs()
 * @method void setActedAs(?string $actedAs)
 * @method string|null getResult()
 * @method void setResult(?string $result)
 * @method string|null getError()
 * @method void setError(?string $error)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 * @method \DateTime|null getFinishedAt()
 * @method void setFinishedAt(?\DateTime $finishedAt)
 */
class ProcessJob extends Entity {

    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    /** Handed back to the engine to be offered again later (Vent på inaktivitet) */
    public const STATUS_WAITING = 'waiting';

    protected string $flowableJobId = '';
    protected int $caseProcessId = 0;
    protected int $caseId = 0;
    protected string $elementId = '';
    protected ?string $elementName = null;
    protected string $topic = '';
    // No default: Entity only inserts fields whose value was changed by a setter
    protected string $status = '';
    protected ?string $actedAs = null;
    protected ?string $result = null;
    protected ?string $error = null;
    protected ?\DateTime $createdAt = null;
    protected ?\DateTime $finishedAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('flowableJobId', 'string');
        $this->addType('caseProcessId', 'integer');
        $this->addType('caseId', 'integer');
        $this->addType('elementId', 'string');
        $this->addType('elementName', 'string');
        $this->addType('topic', 'string');
        $this->addType('status', 'string');
        $this->addType('actedAs', 'string');
        $this->addType('result', 'string');
        $this->addType('error', 'string');
        $this->addType('createdAt', 'datetime');
        $this->addType('finishedAt', 'datetime');
    }
}
