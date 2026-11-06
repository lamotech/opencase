<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Represents a row in the opencase_process_task table — OpenCase's copy of
 * a Flowable user task of a process running on a case (opencase_case_process).
 *
 * @method int getCaseProcessId()
 * @method void setCaseProcessId(int $caseProcessId)
 * @method int getCaseId()
 * @method void setCaseId(int $caseId)
 * @method string getFlowableTaskId()
 * @method void setFlowableTaskId(string $flowableTaskId)
 * @method string getActivityId()
 * @method void setActivityId(string $activityId)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getAssignee()
 * @method void setAssignee(?string $assignee)
 * @method string|null getCandidateGroups()
 * @method void setCandidateGroups(?string $candidateGroups)
 * @method \DateTime|null getDueDate()
 * @method void setDueDate(?\DateTime $dueDate)
 * @method string|null getOutcomes()
 * @method void setOutcomes(?string $outcomes)
 * @method string|null getOutcome()
 * @method void setOutcome(?string $outcome)
 * @method string|null getComment()
 * @method void setComment(?string $comment)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 * @method \DateTime|null getCompletedAt()
 * @method void setCompletedAt(?\DateTime $completedAt)
 * @method string|null getCompletedBy()
 * @method void setCompletedBy(?string $completedBy)
 * @method string|null getFormFields()
 * @method void setFormFields(?string $formFields)
 * @method string|null getFormValues()
 * @method void setFormValues(?string $formValues)
 */
class ProcessTask extends Entity {

    public const STATUS_OPEN = 'open';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected int $caseProcessId = 0;
    protected int $caseId = 0;
    protected string $flowableTaskId = '';
    protected string $activityId = '';
    protected ?string $name = null;
    // No default: Entity only inserts fields whose value was changed by a setter
    protected string $status = '';
    protected ?string $assignee = null;
    protected ?string $candidateGroups = null;
    protected ?\DateTime $dueDate = null;
    protected ?string $outcomes = null;
    protected ?string $outcome = null;
    protected ?string $comment = null;
    protected ?\DateTime $createdAt = null;
    protected ?\DateTime $completedAt = null;
    protected ?string $completedBy = null;
    protected ?string $formFields = null;
    protected ?string $formValues = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('caseProcessId', 'integer');
        $this->addType('caseId', 'integer');
        $this->addType('flowableTaskId', 'string');
        $this->addType('activityId', 'string');
        $this->addType('name', 'string');
        $this->addType('status', 'string');
        $this->addType('assignee', 'string');
        $this->addType('candidateGroups', 'string');
        $this->addType('dueDate', 'datetime');
        $this->addType('outcomes', 'string');
        $this->addType('outcome', 'string');
        $this->addType('comment', 'string');
        $this->addType('createdAt', 'datetime');
        $this->addType('completedAt', 'datetime');
        $this->addType('completedBy', 'string');
        $this->addType('formFields', 'string');
        $this->addType('formValues', 'string');
    }

    /**
     * @return string[]
     */
    public function getCandidateGroupList(): array {
        $groups = array_map('trim', explode(',', $this->candidateGroups ?? ''));
        return array_values(array_filter($groups, fn(string $g) => $g !== ''));
    }

    /**
     * @return array{id: string, name: string, type: string, required: bool, options?: array{id: string, name: string}[]}[]
     */
    public function getFormFieldList(): array {
        return json_decode($this->formFields ?? '[]', true) ?: [];
    }

    /**
     * @return array<string, string>
     */
    public function getFormValueList(): array {
        return json_decode($this->formValues ?? '{}', true) ?: [];
    }

    /**
     * @return array{id: string, name: string}[]
     */
    public function getOutcomeList(): array {
        return json_decode($this->outcomes ?? '[]', true) ?: [];
    }
}
