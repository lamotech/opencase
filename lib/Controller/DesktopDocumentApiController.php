<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\ContactRoleMapper;
use OCA\OpenCase\Db\ContactTypeMapper;
use OCA\OpenCase\Db\Document;
use OCA\OpenCase\Db\DocumentContact;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\DocumentNote;
use OCA\OpenCase\Db\DocumentStatusMapper;
use OCA\OpenCase\Db\FavoriteEntity;
use OCA\OpenCase\Db\FavoriteMapper;
use OCA\OpenCase\Service\CitizenSearchService;
use OCA\OpenCase\Service\CompanySearchService;
use OCA\OpenCase\Service\DesktopLinks;
use OCA\OpenCase\Service\DocumentContactService;
use OCA\OpenCase\Service\DocumentNoteService;
use OCA\OpenCase\Service\DocumentService;
use OCA\OpenCase\Service\FileService;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\PermissionService;
use OCA\OpenCase\Service\PhraseHtml;
use OCA\OpenCase\Service\TransactionLogService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * The "Dokument info" panel of the desktop API: status, favourite,
 * senders/recipients (contacts) and notes of a document. Same
 * authentication as DesktopApiController.
 *
 * Like the document page in OpenCase, contacts and notes cannot be changed
 * while the document's status is final (409).
 *
 * Routes:
 *   GET    /desktop/v1/api/document-statuses
 *   PUT    /desktop/v1/api/documents/{id}/status
 *   PUT    /desktop/v1/api/documents/{id}/favorite
 *   DELETE /desktop/v1/api/documents/{id}/favorite
 *   GET    /desktop/v1/api/contact-roles
 *   GET    /desktop/v1/api/citizens/search
 *   GET    /desktop/v1/api/companies/search
 *   GET    /desktop/v1/api/documents/{id}/contacts
 *   POST   /desktop/v1/api/documents/{id}/contacts
 *   DELETE /desktop/v1/api/documents/{id}/contacts/{contactId}
 *   POST   /desktop/v1/api/documents/{id}/contacts/{contactId}/address — Show a protected address (logged)
 *   GET    /desktop/v1/api/documents/{id}/notes
 *   POST   /desktop/v1/api/documents/{id}/notes
 *   PUT    /desktop/v1/api/documents/{id}/notes/{noteId}
 *   DELETE /desktop/v1/api/documents/{id}/notes/{noteId}
 *
 * The request and response formats are documented in README.md
 * ("Login and backend API").
 */
class DesktopDocumentApiController extends ApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private DocumentService $documentService,
        private DocumentMapper $documentMapper,
        private DocumentStatusMapper $documentStatusMapper,
        private FileService $fileService,
        private FavoriteMapper $favoriteMapper,
        private ContactRoleMapper $contactRoleMapper,
        private DocumentContactService $contactService,
        private DocumentNoteService $noteService,
        private CitizenSearchService $citizenSearchService,
        private CompanySearchService $companySearchService,
        private PermissionService $permissionService,
        private TransactionLogService $transactionLogService,
        private DesktopLinks $links,
        private IL10N $l10n,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    // ---------------------------------------------------------------
    // Status
    // ---------------------------------------------------------------

    /**
     * Document statuses in the user's language.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function statuses(): JSONResponse {
        $rows = $this->documentStatusMapper->findByLanguage($this->l10n->getLanguageCode(), true);
        if (empty($rows)) {
            $rows = $this->documentStatusMapper->findByLanguage('en', true);
        }
        return new JSONResponse(array_map(fn(array $r) => [
            'id'      => $r['id'],
            'name'    => $r['name'],
            'isFinal' => $r['is_final'],
        ], $rows));
    }

    /**
     * Change a document's status. Body (JSON): status — an id from
     * GET /document-statuses. A final status makes the document's files
     * read-only, as in OpenCase.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function setStatus(int $id): JSONResponse {
        $status = $this->request->getParam('status');
        if (!is_numeric($status)) {
            return new JSONResponse(['error' => "Field 'status' is required"], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->documentService->update($id, null, null, (int)$status, $this->userId ?? '');
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (NotFoundException) {
            return $this->documentNotFound();
        }

        $isFinal = (bool)($this->documentStatusMapper->getIsFinalMap()[(int)$status] ?? false);
        $this->fileService->updateFilecachePermissionsForDocument($id, !$isFinal);

        return new JSONResponse(['status' => $this->serializeStatus((int)$status)]);
    }

    // ---------------------------------------------------------------
    // Favourite
    // ---------------------------------------------------------------

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function addFavorite(int $id): JSONResponse {
        if ($this->readableDocument($id) === null) {
            return $this->documentNotFound();
        }
        $userId = $this->userId ?? '';
        if ($this->favoriteMapper->findByUserAndEntity($userId, 'document', $id) === null) {
            $fav = new FavoriteEntity();
            $fav->setUserId($userId);
            $fav->setEntity('document');
            $fav->setEntityKey($id);
            $fav->setAddedAt(new \DateTime());
            $this->favoriteMapper->insert($fav);
        }
        return new JSONResponse(['isFavorite' => true]);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function removeFavorite(int $id): JSONResponse {
        $this->favoriteMapper->deleteByUserAndEntity($this->userId ?? '', 'document', $id);
        return new JSONResponse(['isFavorite' => false]);
    }

    // ---------------------------------------------------------------
    // Contacts (senders and recipients)
    // ---------------------------------------------------------------

    /**
     * Contact roles (e.g. Afsender, Modtager) in the user's language.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function contactRoles(): JSONResponse {
        $roles = $this->contactRoleMapper->findByLanguage($this->l10n->getLanguageCode(), true);
        if (empty($roles)) {
            $roles = $this->contactRoleMapper->findByLanguage('en', true);
        }
        return new JSONResponse(array_map(fn(array $r) => ['id' => (int)$r['id'], 'name' => $r['name']], $roles));
    }

    /**
     * Search citizens: by CPR number, or by name and/or address fields.
     * Searches the CPR register when the instance has access to it,
     * otherwise people already on cases and documents.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function searchCitizens(): JSONResponse {
        $criteria = [];
        foreach (['cpr', 'firstname', 'lastname', 'streetname', 'housenumber', 'zipcode', 'zipdistrict'] as $key) {
            $criteria[$key] = trim((string)$this->request->getParam($key, ''));
        }
        if (implode('', $criteria) === '') {
            return new JSONResponse(['error' => 'Give a CPR number, a name or an address'], Http::STATUS_BAD_REQUEST);
        }
        return $this->searchResponse($this->citizenSearchService->search($criteria, $this->userId), $this->citizenSearchService->usesRegister());
    }

    /**
     * Search companies by CVR number or name. With cvr and pnumbers=1, the
     * company's production units (P-numbers) are returned instead.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function searchCompanies(string $cvr = '', string $name = '', bool $pnumbers = false): JSONResponse {
        $cvr  = trim($cvr);
        $name = trim($name);
        if ($cvr === '' && $name === '') {
            return new JSONResponse(['error' => 'Give a CVR number or a name'], Http::STATUS_BAD_REQUEST);
        }
        $result = $this->companySearchService->search(
            ['cvr' => $cvr, 'name' => $name, 'mode' => $pnumbers && $cvr !== '' ? 'pnumber' : ''],
            $this->userId,
        );
        return $this->searchResponse($result, $this->companySearchService->usesRegister());
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function contacts(int $id): JSONResponse {
        if ($this->readableDocument($id) === null) {
            return $this->documentNotFound();
        }
        $roleNames = $this->contactService->roleNames($this->userId);
        return new JSONResponse(array_map(
            fn(DocumentContact $c) => $this->serializeContact($c, $roleNames),
            $this->contactService->listForDocument($id),
        ));
    }

    /**
     * Add a sender or recipient. Body (JSON):
     *   roleId (required), and either
     *   - cprCvr (+ pnumber for a company's production unit) alone: the person
     *     or company is looked up again and stored with the details found, or
     *   - name and the other details, entered by hand.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function addContact(int $id): JSONResponse {
        if ($denied = $this->denyUnlessEditable($id)) {
            return $denied;
        }

        $params  = $this->request->getParams();
        $roleId  = (int)($params['roleId'] ?? 0);
        $cprCvr  = preg_replace('/\D/', '', (string)($params['cprCvr'] ?? ''));
        $pnumber = trim((string)($params['pnumber'] ?? ''));
        $name    = trim((string)($params['name'] ?? ''));

        if ($roleId <= 0) {
            return new JSONResponse(['error' => "Field 'roleId' is required"], Http::STATUS_BAD_REQUEST);
        }

        if ($name === '') {
            if ($cprCvr === '') {
                return new JSONResponse(['error' => "Give 'cprCvr' to look up, or 'name' for a contact entered by hand"], Http::STATUS_BAD_REQUEST);
            }
            $found = $this->lookUp($cprCvr, $pnumber);
            if ($found === null) {
                return new JSONResponse(['error' => 'No citizen or company found with that number'], Http::STATUS_NOT_FOUND);
            }
            $data = $found;
        } else {
            $data = [
                'cpr_cvr'                => $cprCvr !== '' ? $cprCvr : null,
                'pnumber'                => $pnumber !== '' ? $pnumber : null,
                'name'                   => $name,
                'streetname'             => $params['streetname'] ?? null,
                'housenumber'            => $params['housenumber'] ?? null,
                'floor'                  => $params['floor'] ?? null,
                'door'                   => $params['door'] ?? null,
                'zipcode'                => $params['zipcode'] ?? null,
                'zipdistrict'            => $params['zipdistrict'] ?? null,
                'phone'                  => $params['phone'] ?? null,
                'email'                  => $params['email'] ?? null,
                'has_address_protection' => !empty($params['addressProtected']),
            ];
        }
        $data['contactrole_id'] = $roleId;

        try {
            $contact = $this->contactService->add($id, $data, $this->userId ?? '');
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\DomainException) {
            return new JSONResponse(['error' => 'This contact already has that role on the document'], Http::STATUS_CONFLICT);
        }

        return new JSONResponse(
            $this->serializeContact($contact, $this->contactService->roleNames($this->userId)),
            Http::STATUS_CREATED,
        );
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function removeContact(int $id, int $contactId): JSONResponse {
        if ($denied = $this->denyUnlessEditable($id)) {
            return $denied;
        }
        try {
            $this->contactService->remove($id, $contactId, $this->userId ?? '');
        } catch (NotFoundException) {
            return new JSONResponse(['error' => 'Contact not found'], Http::STATUS_NOT_FOUND);
        }
        return new JSONResponse([]);
    }

    /**
     * The address of a contact with address protection. Each call is
     * recorded in the transaction log, as when the address is shown in OpenCase.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function revealAddress(int $id, int $contactId): JSONResponse {
        if ($this->readableDocument($id) === null) {
            return $this->documentNotFound();
        }
        try {
            $contact = $this->contactService->get($id, $contactId);
        } catch (NotFoundException) {
            return new JSONResponse(['error' => 'Contact not found'], Http::STATUS_NOT_FOUND);
        }

        $address = $this->serializeAddress($contact->getStreetname(), $contact->getHousenumber(), $contact->getFloor(),
            $contact->getDoor(), $contact->getZipcode(), $contact->getZipdistrict());
        if ($contact->getHasAddressProtection()) {
            $this->transactionLogService->log($this->userId ?? '', 'show_address_protection', [
                'cpr'     => $contact->getCprCvr() ?? '',
                'address' => $address['formatted'],
            ]);
        }
        return new JSONResponse(['address' => $address]);
    }

    // ---------------------------------------------------------------
    // Notes
    // ---------------------------------------------------------------

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function notes(int $id): JSONResponse {
        if ($this->readableDocument($id) === null) {
            return $this->documentNotFound();
        }
        return new JSONResponse(array_map(
            fn(DocumentNote $n) => $this->serializeNote($n),
            $this->noteService->listForDocument($id),
        ));
    }

    /**
     * Body (JSON): title (required), text (HTML; reduced to the formatting
     * the OpenCase note editor offers).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function addNote(int $id): JSONResponse {
        if ($denied = $this->denyUnlessEditable($id)) {
            return $denied;
        }
        try {
            $note = $this->noteService->create($id, (string)$this->request->getParam('title', ''), $this->noteText(), $this->userId ?? '');
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
        return new JSONResponse($this->serializeNote($note), Http::STATUS_CREATED);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function updateNote(int $id, int $noteId): JSONResponse {
        if ($denied = $this->denyUnlessEditable($id)) {
            return $denied;
        }
        $note = $this->noteOnDocument($id, $noteId);
        if ($note === null) {
            return new JSONResponse(['error' => 'Note not found'], Http::STATUS_NOT_FOUND);
        }
        try {
            $note = $this->noteService->update($note, (string)$this->request->getParam('title', ''), $this->noteText(), $this->userId ?? '');
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
        return new JSONResponse($this->serializeNote($note));
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function deleteNote(int $id, int $noteId): JSONResponse {
        if ($denied = $this->denyUnlessEditable($id)) {
            return $denied;
        }
        $note = $this->noteOnDocument($id, $noteId);
        if ($note === null) {
            return new JSONResponse(['error' => 'Note not found'], Http::STATUS_NOT_FOUND);
        }
        $this->noteService->delete($note, $this->userId ?? '');
        return new JSONResponse([]);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function readableDocument(int $id): ?Document {
        $document = $this->documentMapper->findById($id);
        if ($document === null || $document->isSoftDeleted()
            || !$this->permissionService->userHasReadAccessToDocument($this->userId ?? '', $id)) {
            return null;
        }
        return $document;
    }

    /**
     * null when the user may change the document's contacts and notes:
     * write access, and the status is not final (as on the document page).
     */
    private function denyUnlessEditable(int $id): ?JSONResponse {
        $document = $this->readableDocument($id);
        if ($document === null || !$this->permissionService->userHasWriteAccessToDocument($this->userId ?? '', $id)) {
            return new JSONResponse(['error' => 'Document not found or no write access'], Http::STATUS_NOT_FOUND);
        }
        if ($this->documentStatusMapper->getIsFinalMap()[$document->getStatus()] ?? false) {
            return new JSONResponse(['error' => 'The document is final and cannot be changed'], Http::STATUS_CONFLICT);
        }
        return null;
    }

    private function documentNotFound(): JSONResponse {
        return new JSONResponse(['error' => 'Document not found or no access'], Http::STATUS_NOT_FOUND);
    }

    private function noteOnDocument(int $documentId, int $noteId): ?DocumentNote {
        try {
            $note = $this->noteService->get($noteId);
        } catch (NotFoundException) {
            return null;
        }
        return $note->getDocumentId() === $documentId ? $note : null;
    }

    private function noteText(): ?string {
        $text = PhraseHtml::sanitize((string)$this->request->getParam('text', ''));
        return $text !== '' ? $text : null;
    }

    /**
     * Look up a citizen (10 digits) or company (8 digits) by number, for
     * adding as a contact. A P-number picks one of the company's units.
     *
     * @return array|null contact data in DocumentContactService::add() form
     */
    private function lookUp(string $cprCvr, string $pnumber): ?array {
        if (strlen($cprCvr) === 10) {
            $results = $this->citizenSearchService->search(['cpr' => $cprCvr], $this->userId)['results'];
        } elseif (strlen($cprCvr) === 8) {
            $results = $this->companySearchService->search(['cvr' => $cprCvr, 'mode' => $pnumber !== '' ? 'pnumber' : ''], $this->userId)['results'];
            if ($pnumber !== '') {
                $results = array_values(array_filter($results, fn(array $r) => (string)($r['pnumber'] ?? '') === $pnumber));
            }
        } else {
            return null;
        }
        return $results[0] ?? null;
    }

    private function searchResponse(array $result, bool $register): JSONResponse {
        if ($result['error'] !== null) {
            return new JSONResponse(['error' => 'Search failed: ' . $result['error']], Http::STATUS_BAD_GATEWAY);
        }
        return new JSONResponse([
            'source'  => $register ? 'register' : 'local',
            'results' => array_map(fn(array $r) => $this->serializeSearchResult($r), $result['results']),
        ]);
    }

    /**
     * A search hit. The address of someone with address protection is left
     * out — it is stored on the contact when they are added by number.
     */
    private function serializeSearchResult(array $r): array {
        $protected = !empty($r['has_address_protection']);
        return [
            'cprCvr'           => (string)($r['cpr_cvr'] ?? ''),
            'pnumber'          => isset($r['pnumber']) && $r['pnumber'] !== '' ? (string)$r['pnumber'] : null,
            'name'             => (string)($r['name'] ?? ''),
            'phone'            => (string)($r['phone'] ?? ''),
            'email'            => (string)($r['email'] ?? ''),
            'addressProtected' => $protected,
            'address'          => $protected ? null : $this->serializeAddress($r['streetname'] ?? null, $r['housenumber'] ?? null,
                $r['floor'] ?? null, $r['door'] ?? null, $r['zipcode'] ?? null, $r['zipdistrict'] ?? null),
        ];
    }

    /**
     * @param array<int, string> $roleNames
     */
    private function serializeContact(DocumentContact $c, array $roleNames): array {
        $protected = (bool)$c->getHasAddressProtection();
        return [
            'id'               => $c->getId(),
            'role'             => ['id' => $c->getContactroleId(), 'name' => $roleNames[$c->getContactroleId()] ?? ''],
            'type'             => match ($c->getContactType()) {
                ContactTypeMapper::CITIZEN  => 'citizen',
                ContactTypeMapper::COMPANY  => 'company',
                ContactTypeMapper::EMPLOYEE => 'employee',
                default                     => 'unknown',
            },
            'name'             => $c->getName() ?? '',
            'cprCvr'           => $c->getCprCvr(),
            'pnumber'          => $c->getPnumber() ?: null,
            'phone'            => $c->getPhone() ?? '',
            'email'            => $c->getEmail() ?? '',
            'addressProtected' => $protected,
            'address'          => $protected ? null : $this->serializeAddress($c->getStreetname(), $c->getHousenumber(),
                $c->getFloor(), $c->getDoor(), $c->getZipcode(), $c->getZipdistrict()),
        ];
    }

    private function serializeAddress(?string $street, ?string $house, ?string $floor, ?string $door, ?string $zip, ?string $district): array {
        $line1 = implode(' ', array_filter([$street, $house, $floor, $door], fn($v) => $v !== null && $v !== ''));
        $line2 = implode(' ', array_filter([$zip, $district], fn($v) => $v !== null && $v !== ''));
        return [
            'streetname'  => $street ?? '',
            'housenumber' => $house ?? '',
            'floor'       => $floor ?? '',
            'door'        => $door ?? '',
            'zipcode'     => $zip ?? '',
            'zipdistrict' => $district ?? '',
            'formatted'   => implode(', ', array_filter([$line1, $line2], fn($v) => $v !== '')),
        ];
    }

    private function serializeNote(DocumentNote $n): array {
        return [
            'id'        => $n->getId(),
            'title'     => $n->getTitle(),
            'text'      => $n->getText() ?? '',
            'createdAt' => $n->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'createdBy' => $this->links->user($n->getCreatedBy()),
            'updatedAt' => $n->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function serializeStatus(int $statusId): array {
        $names = $this->documentStatusMapper->getNameMap($this->l10n->getLanguageCode());
        return [
            'id'      => $statusId,
            'name'    => $names[$statusId] ?? (string)$statusId,
            'isFinal' => (bool)($this->documentStatusMapper->getIsFinalMap()[$statusId] ?? false),
        ];
    }
}
