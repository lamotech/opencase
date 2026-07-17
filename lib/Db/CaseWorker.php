<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * An additional caseworker linked to a case.
 *
 * @method int       getId()
 * @method int       getCaseId()
 * @method void      setCaseId(int $caseId)
 * @method string    getUserId()
 * @method void      setUserId(string $userId)
 * @method int|null  getPriorCanWrite()
 * @method void      setPriorCanWrite(?int $priorCanWrite)
 */
class CaseWorker extends Entity {

    protected int $caseId = 0;
    protected string $userId = '';
    protected ?int $priorCanWrite = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('caseId', 'integer');
        $this->addType('userId', 'string');
        $this->addType('priorCanWrite', 'integer');
    }
}
