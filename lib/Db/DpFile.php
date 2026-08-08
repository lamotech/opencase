<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getDpShipmentId()
 * @method void setDpShipmentId(int $dpShipmentId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getFileName()
 * @method void setFileName(string $fileName)
 * @method string|null getStorageFilename()
 * @method void setStorageFilename(?string $storageFilename)
 * @method bool getIsMain()
 * @method void setIsMain(bool $isMain)
 */
class DpFile extends Entity {

    protected int $dpShipmentId = 0;
    protected int $fileId = 0;
    protected string $fileName = '';
    protected ?string $storageFilename = null;
    protected bool $isMain = false;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('dpShipmentId', 'integer');
        $this->addType('fileId', 'integer');
        $this->addType('fileName', 'string');
        $this->addType('storageFilename', 'string');
        $this->addType('isMain', 'boolean');
    }
}
