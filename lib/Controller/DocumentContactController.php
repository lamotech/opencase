<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\ContactRoleMapper;
use OCA\OpenCase\Db\ContactTypeMapper;
use OCA\OpenCase\Db\DocumentContact;
use OCA\OpenCase\Db\DocumentContactMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Service\AuditService;
use OCA\OpenCase\Service\PermissionService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\L10N\IFactory;

/**
 * REST API controller for document contacts (senders / receivers).
 *
 * Routes:
 *   GET    /api/v1/contact-roles                                   — List contact roles (localized)
 *   GET    /api/v1/documents/{documentId}/contacts                 — List contacts for a document
 *   POST   /api/v1/documents/{documentId}/contacts                 — Add a contact to a document
 *   DELETE /api/v1/documents/{documentId}/contacts/{contactId}     — Remove a contact
 */
class DocumentContactController extends ApiController {

    use CaseAccessGuard;

    public function __construct(
        string $appName,
        IRequest $request,
        private DocumentContactMapper $contactMapper,
        private ContactRoleMapper $roleMapper,
        private DocumentMapper $documentMapper,
        private AuditService $auditService,
        private IFactory $l10nFactory,
        private IUserManager $userManager,
        private PermissionService $permissionService,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request, corsAllowedHeaders: 'Authorization, Content-Type, Accept, OCS-APIREQUEST');
    }

    private function getUserLanguage(): string {
        if ($this->userId === null) {
            return 'en';
        }
        $user = $this->userManager->get($this->userId);
        return $user ? $this->l10nFactory->getUserLanguage($user) : 'en';
    }

    private function resolveRoleNames(): array {
        $lang = $this->getUserLanguage();
        $names = $this->roleMapper->getNameMap($lang);
        return empty($names) ? $this->roleMapper->getNameMap('en') : $names;
    }

    private function serializeContact(DocumentContact $c, array $roleNames): array {
        return [
            'id'                     => $c->getId(),
            'document_id'            => $c->getDocumentId(),
            'contactrole_id'         => $c->getContactroleId(),
            'role_name'              => $roleNames[$c->getContactroleId()] ?? '',
            'contact_type'           => $c->getContactType(),
            'user_id'                => $c->getUserId(),
            'cpr_cvr'                => $c->getCprCvr(),
            'name'                   => $c->getName(),
            'streetname'             => $c->getStreetname(),
            'housenumber'            => $c->getHousenumber(),
            'floor'                  => $c->getFloor(),
            'door'                   => $c->getDoor(),
            'zipcode'                => $c->getZipcode(),
            'zipdistrict'            => $c->getZipdistrict(),
            'phone'                  => $c->getPhone(),
            'email'                  => $c->getEmail(),
            'updated_date'           => $c->getUpdatedDate()?->format('Y-m-d'),
            'has_address_protection' => (bool)$c->getHasAddressProtection(),
            'pnumber'                => $c->getPnumber(),
        ];
    }

    /**
     * List contact roles in the current user's language.
     */
    #[NoAdminRequired]
    #[CORS]
    #[ApiRoute(verb: 'GET', url: '/api/v1/contact-roles')]
    public function contactRoles(): DataResponse {
        $lang = $this->getUserLanguage();
        $roles = $this->roleMapper->findByLanguage($lang, true);
        if (empty($roles)) {
            $roles = $this->roleMapper->findByLanguage('en', true);
        }
        return new DataResponse(['roles' => $roles]);
    }

    /**
     * List all contacts attached to a document.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/documents/{documentId}/contacts')]
    public function indexByDocument(int $documentId): DataResponse {
        if ($denied = $this->denyUnlessDocumentRead($documentId)) {
            return $denied;
        }

        $roleNames = $this->resolveRoleNames();
        $contacts  = $this->contactMapper->findByDocument($documentId);

        return new DataResponse([
            'contacts' => array_map(fn($c) => $this->serializeContact($c, $roleNames), $contacts),
        ]);
    }

    /**
     * Add a contact to a document.
     */
    #[NoAdminRequired]
    #[CORS]
    #[ApiRoute(verb: 'POST', url: '/api/v1/documents/{documentId}/contacts')]
    public function create(int $documentId): DataResponse {
        if ($denied = $this->denyUnlessDocumentWrite($documentId)) {
            return $denied;
        }

        $params = $this->request->getParams();

        if (empty($params['contactrole_id'])) {
            return new DataResponse(
                ['error' => "Field 'contactrole_id' is required"],
                Http::STATUS_BAD_REQUEST
            );
        }

        $contactroleId = (int)$params['contactrole_id'];
        $cprCvr        = $params['cpr_cvr'] ?? null;
        $userId        = isset($params['user_id']) && $params['user_id'] !== '' ? (string)$params['user_id'] : null;
        $pnumber       = isset($params['pnumber']) && $params['pnumber'] !== '' ? $params['pnumber'] : null;

        if ($cprCvr !== null && $cprCvr !== '') {
            if ($this->contactMapper->existsByDocumentRoleAndCvr($documentId, $contactroleId, $cprCvr, $pnumber)) {
                return new DataResponse(
                    ['error' => 'A contact with this role and CVR already exists on this document'],
                    Http::STATUS_CONFLICT
                );
            }
        }

        $entity = new DocumentContact();
        $entity->setDocumentId($documentId);
        $entity->setContactroleId($contactroleId);
        $entity->setContactType(ContactTypeMapper::deriveFromCprCvr($cprCvr));
        $entity->setUserId($userId);
        $entity->setCprCvr($cprCvr);
        $entity->setName($params['name'] ?? null);
        $entity->setStreetname($params['streetname'] ?? null);
        $entity->setHousenumber($params['housenumber'] ?? null);
        $entity->setFloor($params['floor'] ?? null);
        $entity->setDoor($params['door'] ?? null);
        $entity->setZipcode($params['zipcode'] ?? null);
        $entity->setZipdistrict($params['zipdistrict'] ?? null);
        $entity->setPhone($params['phone'] ?? null);
        $entity->setEmail($params['email'] ?? null);
        $entity->setHasAddressProtection(!empty($params['has_address_protection']));
        $entity->setPnumber($params['pnumber'] ?? null);

        if (!empty($params['updated_date'])) {
            $entity->setUpdatedDate(new \DateTime($params['updated_date']));
        }

        $saved     = $this->contactMapper->insert($entity);
        $roleNames = $this->resolveRoleNames();

        $document = $this->documentMapper->findById($documentId);
        if ($document !== null) {
            $this->auditService->logContactAdded(
                $document->getCaseId(),
                $documentId,
                $this->userId ?? '',
                $roleNames[$contactroleId] ?? '',
                $saved->getName() ?? '',
                $saved->getCprCvr() ?? '',
            );
        }

        return new DataResponse(
            ['contact' => $this->serializeContact($saved, $roleNames)],
            Http::STATUS_CREATED
        );
    }

    /**
     * Remove a contact from a document.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/documents/{documentId}/contacts/{contactId}')]
    public function destroy(int $documentId, int $contactId): DataResponse {
        if ($denied = $this->denyUnlessDocumentWrite($documentId)) {
            return $denied;
        }

        $contact = $this->contactMapper->findById($contactId);

        if ($contact === null || $contact->getDocumentId() !== $documentId) {
            return new DataResponse(['error' => 'Contact not found'], Http::STATUS_NOT_FOUND);
        }

        $roleNames = $this->resolveRoleNames();
        $roleName  = $roleNames[$contact->getContactroleId()] ?? '';
        $name      = $contact->getName() ?? '';
        $cvr       = $contact->getCprCvr() ?? '';

        $this->contactMapper->delete($contact);

        $document = $this->documentMapper->findById($documentId);
        if ($document !== null) {
            $this->auditService->logContactDeleted(
                $document->getCaseId(),
                $documentId,
                $this->userId ?? '',
                $roleName,
                $name,
                $cvr,
            );
        }

        return new DataResponse([], Http::STATUS_NO_CONTENT);
    }
}
