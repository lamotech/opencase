<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A saved bulk-update job: a search criteria set plus the field updates
 * to apply to every case or document matching those criteria.
 *
 * @method string      getTitle()
 * @method void        setTitle(string $title)
 * @method string      getEntity()
 * @method void        setEntity(string $entity)
 * @method \DateTime   getCreatedAt()
 * @method void        setCreatedAt(\DateTime $createdAt)
 * @method string      getCreatedBy()
 * @method void        setCreatedBy(string $createdBy)
 * @method int         getTotalCount()
 * @method void        setTotalCount(int $totalCount)
 * @method string      getStatus()
 * @method void        setStatus(string $status)
 * @method string      getCriterias()
 * @method void        setCriterias(string $criterias)
 * @method string      getUpdates()
 * @method void        setUpdates(string $updates)
 */
class BulkUpdate extends Entity {

    protected string $title = '';
    protected string $entity = '';
    protected ?\DateTime $createdAt = null;
    protected string $createdBy = '';
    protected int $totalCount = 0;
    protected string $status = 'created';
    protected string $criterias = '';
    protected string $updates = '';

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('title', 'string');
        $this->addType('entity', 'string');
        $this->addType('createdAt', 'datetime');
        $this->addType('createdBy', 'string');
        $this->addType('totalCount', 'integer');
        $this->addType('status', 'string');
        $this->addType('criterias', 'string');
        $this->addType('updates', 'string');
    }
}
