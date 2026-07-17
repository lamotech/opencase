<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getDpShipmentId()
 * @method void setDpShipmentId(int $dpShipmentId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getCprCvr()
 * @method void setCprCvr(?string $cprCvr)
 * @method string|null getReceiverName()
 * @method void setReceiverName(?string $receiverName)
 * @method bool getDpSubscription()
 * @method void setDpSubscription(bool $dpSubscription)
 * @method string|null getStorageFilename()
 * @method void setStorageFilename(?string $storageFilename)
 * @method string|null getMessageId()
 * @method void setMessageId(?string $messageId)
 * @method \DateTime|null getMessageCreatedAt()
 * @method void setMessageCreatedAt(?\DateTime $messageCreatedAt)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getMessageAdvisId()
 * @method void setMessageAdvisId(?string $messageAdvisId)
 * @method string|null getMessageAdvisTekst()
 * @method void setMessageAdvisTekst(?string $messageAdvisTekst)
 * @method int|null getDocumentContactId()
 * @method void setDocumentContactId(?int $documentContactId)
 * @method string|null getPnumber()
 * @method void setPnumber(?string $pnumber)
 */
class DpReceiver extends Entity {

    protected int $dpShipmentId = 0;
    protected string $title = '';
    protected ?string $cprCvr = null;
    protected ?string $receiverName = null;
    protected bool $dpSubscription = false;
    protected ?string $storageFilename = null;
    protected ?string $messageId = null;
    protected ?\DateTime $messageCreatedAt = null;
    protected string $status = 'Pending';
    protected ?string $messageAdvisId = null;
    protected ?string $messageAdvisTekst = null;
    protected ?int $documentContactId = null;
    protected ?string $pnumber = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('dpShipmentId', 'integer');
        $this->addType('title', 'string');
        $this->addType('cprCvr', 'string');
        $this->addType('receiverName', 'string');
        $this->addType('dpSubscription', 'boolean');
        $this->addType('storageFilename', 'string');
        $this->addType('messageId', 'string');
        $this->addType('messageCreatedAt', 'datetime');
        $this->addType('status', 'string');
        $this->addType('messageAdvisId', 'string');
        $this->addType('messageAdvisTekst', 'string');
        $this->addType('documentContactId', 'integer');
        $this->addType('pnumber', 'string');
    }
}
