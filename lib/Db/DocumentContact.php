<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A contact (sender or receiver) linked to a document.
 *
 * @method int    getId()
 * @method int    getDocumentId()
 * @method void   setDocumentId(int $documentId)
 * @method int    getContactroleId()
 * @method void   setContactroleId(int $contactroleId)
 * @method string|null getCprCvr()
 * @method void   setCprCvr(?string $cprCvr)
 * @method string|null getName()
 * @method void   setName(?string $name)
 * @method string|null getStreetname()
 * @method void   setStreetname(?string $streetname)
 * @method string|null getHousenumber()
 * @method void   setHousenumber(?string $housenumber)
 * @method string|null getFloor()
 * @method void   setFloor(?string $floor)
 * @method string|null getDoor()
 * @method void   setDoor(?string $door)
 * @method string|null getZipcode()
 * @method void   setZipcode(?string $zipcode)
 * @method string|null getZipdistrict()
 * @method void   setZipdistrict(?string $zipdistrict)
 * @method string|null getPhone()
 * @method void   setPhone(?string $phone)
 * @method string|null getEmail()
 * @method void   setEmail(?string $email)
 * @method \DateTime|null getUpdatedDate()
 * @method void   setUpdatedDate(?\DateTime $updatedDate)
 * @method bool   getHasAddressProtection()
 * @method void   setHasAddressProtection(bool $hasAddressProtection)
 * @method string|null getPnumber()
 * @method void   setPnumber(?string $pnumber)
 */
class DocumentContact extends Entity {

    protected int $documentId = 0;
    protected int $contactroleId = 0;
    protected ?string $cprCvr = null;
    protected ?string $name = null;
    protected ?string $streetname = null;
    protected ?string $housenumber = null;
    protected ?string $floor = null;
    protected ?string $door = null;
    protected ?string $zipcode = null;
    protected ?string $zipdistrict = null;
    protected ?string $phone = null;
    protected ?string $email = null;
    protected ?\DateTime $updatedDate = null;
    protected bool $hasAddressProtection = false;
    protected ?string $pnumber = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('documentId', 'integer');
        $this->addType('contactroleId', 'integer');
        $this->addType('cprCvr', 'string');
        $this->addType('name', 'string');
        $this->addType('streetname', 'string');
        $this->addType('housenumber', 'string');
        $this->addType('floor', 'string');
        $this->addType('door', 'string');
        $this->addType('zipcode', 'string');
        $this->addType('zipdistrict', 'string');
        $this->addType('phone', 'string');
        $this->addType('email', 'string');
        $this->addType('updatedDate', 'datetime');
        $this->addType('hasAddressProtection', 'boolean');
        $this->addType('pnumber', 'string');
    }
}
