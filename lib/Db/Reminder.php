<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int             getId()
 * @method string          getEntity()
 * @method void            setEntity(string $entity)
 * @method int             getEntityKey()
 * @method void            setEntityKey(int $entityKey)
 * @method string          getTitle()
 * @method void            setTitle(string $title)
 * @method \DateTime|null  getDeadline()
 * @method void            setDeadline(?\DateTime $deadline)
 * @method string          getStatus()
 * @method void            setStatus(string $status)
 * @method string|null     getResponsibleUserId()
 * @method void            setResponsibleUserId(?string $responsibleUserId)
 * @method \DateTime|null  getCreatedAt()
 * @method void            setCreatedAt(?\DateTime $createdAt)
 * @method string          getCreatedBy()
 * @method void            setCreatedBy(string $createdBy)
 */
class Reminder extends Entity {

    protected string $entity = '';
    protected int $entityKey = 0;
    protected string $title = '';
    protected ?\DateTime $deadline = null;
    protected string $status = '';
    protected ?string $responsibleUserId = null;
    protected ?\DateTime $createdAt = null;
    protected string $createdBy = '';

    public function __construct() {
        $this->addType('id',                  'integer');
        $this->addType('entity',              'string');
        $this->addType('entityKey',           'integer');
        $this->addType('title',               'string');
        $this->addType('deadline',            'datetime');
        $this->addType('status',              'string');
        $this->addType('responsibleUserId',   'string');
        $this->addType('createdAt',           'datetime');
        $this->addType('createdBy',           'string');
    }
}
