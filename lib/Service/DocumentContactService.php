<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\ContactRoleMapper;
use OCA\OpenCase\Db\ContactTypeMapper;
use OCA\OpenCase\Db\DocumentContact;
use OCA\OpenCase\Db\DocumentContactMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCP\IUserManager;
use OCP\L10N\IFactory;

/**
 * Senders and recipients of a document (opencase_document_contacts), with
 * audit logging. Callers check access to the document first.
 */
class DocumentContactService {

    public function __construct(
        private DocumentContactMapper $contactMapper,
        private ContactRoleMapper $roleMapper,
        private DocumentMapper $documentMapper,
        private AuditService $auditService,
        private IUserManager $userManager,
        private IFactory $l10nFactory,
    ) {
    }

    /**
     * Contact role names in the user's language (falling back to English).
     *
     * @return array<int, string> role id => name
     */
    public function roleNames(?string $userId): array {
        $user  = $userId !== null ? $this->userManager->get($userId) : null;
        $names = $this->roleMapper->getNameMap($user !== null ? $this->l10nFactory->getUserLanguage($user) : 'en');
        return empty($names) ? $this->roleMapper->getNameMap('en') : $names;
    }

    /**
     * @return DocumentContact[]
     */
    public function listForDocument(int $documentId): array {
        return $this->contactMapper->findByDocument($documentId);
    }

    /**
     * Add a contact to a document.
     *
     * @param array $data contactrole_id (required), cpr_cvr, user_id, pnumber, name,
     *                    streetname, housenumber, floor, door, zipcode, zipdistrict,
     *                    phone, email, has_address_protection, updated_date
     * @throws \InvalidArgumentException when contactrole_id is missing
     * @throws \DomainException          when the CVR (and P-number) already has that role on the document
     */
    public function add(int $documentId, array $data, string $userId): DocumentContact {
        if (empty($data['contactrole_id'])) {
            throw new \InvalidArgumentException("Field 'contactrole_id' is required");
        }

        $contactroleId = (int)$data['contactrole_id'];
        $cprCvr        = isset($data['cpr_cvr']) && $data['cpr_cvr'] !== '' ? (string)$data['cpr_cvr'] : null;
        $pnumber       = isset($data['pnumber']) && $data['pnumber'] !== '' ? (string)$data['pnumber'] : null;
        $contactUserId = isset($data['user_id']) && $data['user_id'] !== '' ? (string)$data['user_id'] : null;

        if ($cprCvr !== null && $this->contactMapper->existsByDocumentRoleAndCvr($documentId, $contactroleId, $cprCvr, $pnumber)) {
            throw new \DomainException('A contact with this role and CVR already exists on this document');
        }

        $entity = new DocumentContact();
        $entity->setDocumentId($documentId);
        $entity->setContactroleId($contactroleId);
        $entity->setContactType(ContactTypeMapper::deriveFromCprCvr($cprCvr));
        $entity->setUserId($contactUserId);
        $entity->setCprCvr($cprCvr);
        $entity->setName($data['name'] ?? null);
        $entity->setStreetname($data['streetname'] ?? null);
        $entity->setHousenumber($data['housenumber'] ?? null);
        $entity->setFloor($data['floor'] ?? null);
        $entity->setDoor($data['door'] ?? null);
        $entity->setZipcode($data['zipcode'] ?? null);
        $entity->setZipdistrict($data['zipdistrict'] ?? null);
        $entity->setPhone($data['phone'] ?? null);
        $entity->setEmail($data['email'] ?? null);
        $entity->setHasAddressProtection(!empty($data['has_address_protection']));
        $entity->setPnumber($pnumber);

        if (!empty($data['updated_date'])) {
            $entity->setUpdatedDate(new \DateTime($data['updated_date']));
        }

        $saved = $this->contactMapper->insert($entity);

        $document = $this->documentMapper->findById($documentId);
        if ($document !== null) {
            $this->auditService->logContactAdded(
                $document->getCaseId(),
                $documentId,
                $userId,
                $this->roleNames($userId)[$contactroleId] ?? '',
                $saved->getName() ?? '',
                $saved->getCprCvr() ?? '',
            );
        }

        return $saved;
    }

    /**
     * Remove a contact from a document.
     *
     * @throws NotFoundException when the contact is not on that document
     */
    public function remove(int $documentId, int $contactId, string $userId): void {
        $contact = $this->contactMapper->findById($contactId);
        if ($contact === null || $contact->getDocumentId() !== $documentId) {
            throw new NotFoundException('Contact not found');
        }

        $this->contactMapper->delete($contact);

        $document = $this->documentMapper->findById($documentId);
        if ($document !== null) {
            $this->auditService->logContactDeleted(
                $document->getCaseId(),
                $documentId,
                $userId,
                $this->roleNames($userId)[$contact->getContactroleId()] ?? '',
                $contact->getName() ?? '',
                $contact->getCprCvr() ?? '',
            );
        }
    }

    /**
     * @throws NotFoundException when the contact is not on that document
     */
    public function get(int $documentId, int $contactId): DocumentContact {
        $contact = $this->contactMapper->findById($contactId);
        if ($contact === null || $contact->getDocumentId() !== $documentId) {
            throw new NotFoundException('Contact not found');
        }
        return $contact;
    }
}
