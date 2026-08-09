<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A single ExportClosedCasesJob run record.
 *
 * @method \DateTime getSyncTime()
 * @method void setSyncTime(\DateTime $syncTime)
 * @method int getCount()
 * @method void setCount(int $count)
 * @method int getExported()
 * @method void setExported(int $exported)
 * @method int getFailed()
 * @method void setFailed(int $failed)
 */
class ExportLogEntry extends Entity {

    protected ?\DateTime $syncTime = null;
    protected int $count = 0;
    protected int $exported = 0;
    protected int $failed = 0;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('syncTime', 'datetime');
        $this->addType('count', 'integer');
        $this->addType('exported', 'integer');
        $this->addType('failed', 'integer');
    }
}
