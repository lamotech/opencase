<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\ContactRoleMapper;
use OCA\OpenCase\Db\DocumentContact;
use OCA\OpenCase\Service\DocumentContactService;
use OCA\OpenCase\Service\NotFoundException;
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
        private DocumentContactService $contactService,
        private ContactRoleMapper $roleMapper,
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
        return $this->contactService->roleNames($this->userId);
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
        $contacts  = $this->contactService->listForDocument($documentId);

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

        try {
            $saved = $this->contactService->add($documentId, $this->request->getParams(), $this->userId ?? '');
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\DomainException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        }

        return new DataResponse(
            ['contact' => $this->serializeContact($saved, $this->resolveRoleNames())],
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

        try {
            $this->contactService->remove($documentId, $contactId, $this->userId ?? '');
        } catch (NotFoundException) {
            return new DataResponse(['error' => 'Contact not found'], Http::STATUS_NOT_FOUND);
        }

        return new DataResponse([], Http::STATUS_NO_CONTENT);
    }
}
