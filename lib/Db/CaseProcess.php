<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Represents a row in the opencase_case_process table — a process started
 * on a case, i.e. one Flowable process instance running a published
 * version (opencase_process_version) of a process.
 *
 * @method int getCaseId()
 * @method void setCaseId(int $caseId)
 * @method int getProcessDefId()
 * @method void setProcessDefId(int $processDefId)
 * @method int getProcessVersionId()
 * @method void setProcessVersionId(int $processVersionId)
 * @method string getFlowableInstanceId()
 * @method void setFlowableInstanceId(string $flowableInstanceId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method \DateTime|null getStartedAt()
 * @method void setStartedAt(?\DateTime $startedAt)
 * @method string|null getStartedBy()
 * @method void setStartedBy(?string $startedBy)
 * @method \DateTime|null getEndedAt()
 * @method void setEndedAt(?\DateTime $endedAt)
 * @method string|null getEndedBy()
 * @method void setEndedBy(?string $endedBy)
 * @method string|null getEndReason()
 * @method void setEndReason(?string $endReason)
 * @method string|null getActiveActivities()
 * @method void setActiveActivities(?string $activeActivities)
 * @method string|null getCompletedActivities()
 * @method void setCompletedActivities(?string $completedActivities)
 * @method \DateTime|null getLastSyncedAt()
 * @method void setLastSyncedAt(?\DateTime $lastSyncedAt)
 * @method string|null getErrorMessage()
 * @method void setErrorMessage(?string $errorMessage)
 * @method string|null getErrorJobId()
 * @method void setErrorJobId(?string $errorJobId)
 */
class CaseProcess extends Entity {

    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected int $caseId = 0;
    protected int $processDefId = 0;
    protected int $processVersionId = 0;
    protected string $flowableInstanceId = '';
    // No default: Entity only inserts fields whose value was changed by a setter
    protected string $status = '';
    protected ?\DateTime $startedAt = null;
    protected ?string $startedBy = null;
    protected ?\DateTime $endedAt = null;
    protected ?string $endedBy = null;
    protected ?string $endReason = null;
    protected ?string $activeActivities = null;
    protected ?string $completedActivities = null;
    protected ?\DateTime $lastSyncedAt = null;
    protected ?string $errorMessage = null;
    protected ?string $errorJobId = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('caseId', 'integer');
        $this->addType('processDefId', 'integer');
        $this->addType('processVersionId', 'integer');
        $this->addType('flowableInstanceId', 'string');
        $this->addType('status', 'string');
        $this->addType('startedAt', 'datetime');
        $this->addType('startedBy', 'string');
        $this->addType('endedAt', 'datetime');
        $this->addType('endedBy', 'string');
        $this->addType('endReason', 'string');
        $this->addType('activeActivities', 'string');
        $this->addType('completedActivities', 'string');
        $this->addType('lastSyncedAt', 'datetime');
        $this->addType('errorMessage', 'string');
        $this->addType('errorJobId', 'string');
    }

    /**
     * @return string[]
     */
    public function getActiveActivityIds(): array {
        return json_decode($this->activeActivities ?? '[]', true) ?: [];
    }

    /**
     * @return string[]
     */
    public function getCompletedActivityIds(): array {
        return json_decode($this->completedActivities ?? '[]', true) ?: [];
    }
}
