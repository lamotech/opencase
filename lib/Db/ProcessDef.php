<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Represents a row in the opencase_process_def table — a BPMN process that
 * can be started on cases matching its scopes (opencase_process_scope).
 * process_key is fixed at creation and is used as the BPMN process id and
 * the Flowable process definition key.
 *
 * @method string getProcessKey()
 * @method void setProcessKey(string $processKey)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method bool getActive()
 * @method void setActive(bool $active)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?\DateTime $updatedAt)
 * @method string|null getUpdatedBy()
 * @method void setUpdatedBy(?string $updatedBy)
 */
class ProcessDef extends Entity {

    protected string $processKey = '';
    protected string $name = '';
    protected ?string $description = null;
    protected bool $active = true;
    protected ?\DateTime $createdAt = null;
    protected ?string $createdBy = null;
    protected ?\DateTime $updatedAt = null;
    protected ?string $updatedBy = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('processKey', 'string');
        $this->addType('name', 'string');
        $this->addType('description', 'string');
        $this->addType('active', 'boolean');
        $this->addType('createdAt', 'datetime');
        $this->addType('createdBy', 'string');
        $this->addType('updatedAt', 'datetime');
        $this->addType('updatedBy', 'string');
    }
}
