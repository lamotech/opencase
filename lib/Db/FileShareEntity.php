<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A share of a single OpenCase file with a specific Nextcloud user.
 *
 * @method int     getId()
 * @method int     getFileId()
 * @method void    setFileId(int $fileId)
 * @method string  getOwnerId()
 * @method void    setOwnerId(string $ownerId)
 * @method string  getSharedWith()
 * @method void    setSharedWith(string $sharedWith)
 * @method int     getPermissions()
 * @method void    setPermissions(int $permissions)
 * @method ?\DateTime getCreatedAt()
 * @method void    setCreatedAt(\DateTime $createdAt)
 * @method ?\DateTime getExpiresAt()
 * @method void    setExpiresAt(?\DateTime $expiresAt)
 * @method ?string getNote()
 * @method void    setNote(?string $note)
 * @method ?int    getDocumentId()
 * @method void    setDocumentId(?int $documentId)
 */
class FileShareEntity extends Entity {

    protected int $fileId = 0;
    protected string $ownerId = '';
    protected string $sharedWith = '';
    protected int $permissions = 1;
    protected ?\DateTime $createdAt = null;
    protected ?\DateTime $expiresAt = null;
    protected ?string $note = null;
    protected ?int $documentId = null;

    public function __construct() {
        $this->addType('fileId',      'integer');
        $this->addType('ownerId',     'string');
        $this->addType('sharedWith',  'string');
        $this->addType('permissions', 'integer');
        $this->addType('createdAt',   'datetime');
        $this->addType('expiresAt',   'datetime');
        $this->addType('note',        'string');
        $this->addType('documentId',  'integer');
    }

    public function isExpired(): bool {
        return $this->expiresAt !== null && $this->expiresAt < new \DateTime();
    }

    public function canWrite(): bool {
        return ($this->permissions & \OCP\Constants::PERMISSION_UPDATE) !== 0;
    }
}
