<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A direct per-user access grant for a case.
 *
 * Used for:
 *   - Temporary access (expires_at IS NOT NULL)
 *   - Additional responsible users / collaborators beyond the primary responsible_user_id
 *
 * @method int      getCaseId()
 * @method void     setCaseId(int $caseId)
 * @method string   getUserId()
 * @method void     setUserId(string $userId)
 * @method bool     getCanWrite()
 * @method void     setCanWrite(bool $canWrite)
 * @method string   getGrantedBy()
 * @method void     setGrantedBy(string $grantedBy)
 * @method \DateTime getGrantedAt()
 * @method void     setGrantedAt(\DateTime $grantedAt)
 * @method \DateTime|null getExpiresAt()
 * @method void     setExpiresAt(?\DateTime $expiresAt)
 */
class CaseUserEntity extends Entity {

    protected int $caseId = 0;
    protected string $userId = '';
    protected bool $canWrite = false;
    protected string $grantedBy = '';
    protected ?\DateTime $grantedAt = null;
    protected ?\DateTime $expiresAt = null;

    public function __construct() {
        $this->addType('id',        'integer');
        $this->addType('caseId',    'integer');
        $this->addType('userId',    'string');
        $this->addType('canWrite',  'boolean');
        $this->addType('grantedBy', 'string');
        $this->addType('grantedAt', 'datetime');
        $this->addType('expiresAt', 'datetime');
    }

    /**
     * Returns true if this grant has passed its expiry date.
     * Permanent grants (expiresAt === null) never expire.
     */
    public function isExpired(): bool {
        return $this->expiresAt !== null && $this->expiresAt <= new \DateTime();
    }
}
