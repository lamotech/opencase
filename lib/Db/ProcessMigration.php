<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Represents a row in the opencase_process_migration table — moving the
 * running processes of a process to a newer version (ProcessMigrationJob).
 *
 * @method int getProcessDefId()
 * @method void setProcessDefId(int $processDefId)
 * @method int getToVersionId()
 * @method void setToVersionId(int $toVersionId)
 * @method string|null getMappings()
 * @method void setMappings(?string $mappings)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int getTotal()
 * @method void setTotal(int $total)
 * @method int getMigrated()
 * @method void setMigrated(int $migrated)
 * @method int getFailed()
 * @method void setFailed(int $failed)
 * @method string|null getErrors()
 * @method void setErrors(?string $errors)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method \DateTime|null getFinishedAt()
 * @method void setFinishedAt(?\DateTime $finishedAt)
 */
class ProcessMigration extends Entity {

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';

    protected int $processDefId = 0;
    protected int $toVersionId = 0;
    protected ?string $mappings = null;
    // No default: Entity only inserts fields whose value was changed by a setter
    protected string $status = '';
    protected int $total = -1;
    protected int $migrated = -1;
    protected int $failed = -1;
    protected ?string $errors = null;
    protected ?\DateTime $createdAt = null;
    protected ?string $createdBy = null;
    protected ?\DateTime $finishedAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('processDefId', 'integer');
        $this->addType('toVersionId', 'integer');
        $this->addType('mappings', 'string');
        $this->addType('status', 'string');
        $this->addType('total', 'integer');
        $this->addType('migrated', 'integer');
        $this->addType('failed', 'integer');
        $this->addType('errors', 'string');
        $this->addType('createdAt', 'datetime');
        $this->addType('createdBy', 'string');
        $this->addType('finishedAt', 'datetime');
    }

    /**
     * @return array<string, string>
     */
    public function getMappingList(): array {
        return json_decode($this->mappings ?? '{}', true) ?: [];
    }

    /**
     * @return array{case_process_id: int, case_id: int, case_number: string, error: string}[]
     */
    public function getErrorList(): array {
        return json_decode($this->errors ?? '[]', true) ?: [];
    }
}
