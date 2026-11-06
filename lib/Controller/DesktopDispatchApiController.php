<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\Document;
use OCA\OpenCase\Db\DocumentContact;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\DpFile;
use OCA\OpenCase\Db\DpReceiver;
use OCA\OpenCase\Db\FileEntity;
use OCA\OpenCase\Db\FileMapper;
use OCA\OpenCase\Service\DesktopLinks;
use OCA\OpenCase\Service\DigitalPostAlreadySentException;
use OCA\OpenCase\Service\DigitalPostService;
use OCA\OpenCase\Service\DocumentContactService;
use OCA\OpenCase\Service\DocumentEmailService;
use OCA\OpenCase\Service\DocumentRelationService;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\PdfExportService;
use OCA\OpenCase\Service\PermissionService;
use OCA\OpenCase\Service\PhraseHtml;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Sending a document from the desktop client — Digital Post (as the
 * DigitalPostDialog / DigitalPostStatusDialog in OpenCase), a link to the
 * document by email, and the document's files as PDF by email. Every send
 * is recorded in the document's audit log. Same authentication as
 * DesktopApiController.
 *
 * Routes:
 *   GET  /desktop/v1/api/documents/{id}/digital-post            — Latest shipment (status) and whether one can be sent
 *   GET  /desktop/v1/api/documents/{id}/digital-post/draft      — What a new shipment would contain
 *   GET  /desktop/v1/api/documents/{id}/contacts/{contactId}/digital-post-subscription
 *   POST /desktop/v1/api/documents/{id}/digital-post            — Queue a shipment
 *   GET  /desktop/v1/api/digital-post/files/{fileId}/pdf        — Generated PDF of an additional file
 *   GET  /desktop/v1/api/digital-post/receivers/{receiverId}/pdf — Generated PDF of a receiver's letter
 *   GET  /desktop/v1/api/email/account                          — The user's Mail app account
 *   GET  /desktop/v1/api/documents/{id}/email/recipients        — Search recipients
 *   POST /desktop/v1/api/documents/{id}/email/access            — Which addresses can open the document link
 *   POST /desktop/v1/api/documents/{id}/email/link              — Send a link to the document
 *   POST /desktop/v1/api/documents/{id}/email/pdf               — Send the files as PDF
 *
 * The request and response formats are documented in README.md
 * ("Login and backend API").
 */
class DesktopDispatchApiController extends ApiController {

    /** Contact role id for "Modtager" / "Receiver" (seeded with id=2). */
    private const RECEIVER_ROLE_ID = 2;

    public function __construct(
        string $appName,
        IRequest $request,
        private DigitalPostService $digitalPostService,
        private DocumentEmailService $emailService,
        private PdfExportService $pdfExportService,
        private DocumentContactService $contactService,
        private DocumentRelationService $documentRelationService,
        private DocumentMapper $documentMapper,
        private FileMapper $fileMapper,
        private PermissionService $permissionService,
        private DesktopLinks $links,
        private IUserManager $userManager,
        private IL10N $l10n,
        private LoggerInterface $logger,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    // ---------------------------------------------------------------
    // Digital Post
    // ---------------------------------------------------------------

    /**
     * Whether Digital Post can be used for the document, and its latest
     * shipment. With a shipment, show its status (as DigitalPostStatusDialog);
     * without, offer a new one (canSend). A document is sent only once.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function digitalPost(int $id): JSONResponse {
        if ($this->readableDocument($id) === null) {
            return $this->documentNotFound();
        }
        return new JSONResponse($this->digitalPostState($id));
    }

    /**
     * What a new shipment would contain, for the "Digital post" dialog: the
     * document's files (the main document is chosen among them), the files of
     * its Bilag, and the receivers (the document's "Modtager" contacts).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function digitalPostDraft(int $id): JSONResponse {
        if ($this->readableDocument($id) === null) {
            return $this->documentNotFound();
        }
        $userId = $this->userId ?? '';

        $files = $this->fileMapper->findByDocument($id);

        $attachments = [];
        foreach ($this->documentRelationService->readableAttachmentIds($id, $userId) as $attachmentId) {
            $attachment = $this->documentMapper->findById($attachmentId);
            $attachmentFiles = $this->fileMapper->findByDocument($attachmentId);
            if ($attachment === null || $attachmentFiles === []) {
                continue;
            }
            $attachments[] = [
                'id'             => $attachment->getId(),
                'documentnumber' => $attachment->getDocumentNumber(),
                'title'          => $attachment->getTitle(),
                'files'          => array_map(fn(FileEntity $f) => $this->serializeFile($f), $attachmentFiles),
            ];
        }

        return new JSONResponse([
            'canSend'            => $this->digitalPostService->isEnabled() && $this->digitalPostService->latest($id) === null
                && $this->digitalPostService->canSend($id, $userId),
            'canBeReplied'       => true,
            'includeAttachments' => true,
            'mainFileId'         => isset($files[0]) ? $files[0]->getId() : null,
            'files'              => array_map(fn(FileEntity $f) => $this->serializeFile($f), $files),
            'attachments'        => $attachments,
            'receivers'          => array_map(fn(DocumentContact $c) => [
                'contactId'        => $c->getId(),
                'name'             => $c->getName() ?? '',
                'cprCvr'           => $c->getCprCvr(),
                'addressProtected' => (bool)$c->getHasAddressProtection(),
                'address'          => $c->getHasAddressProtection() ? null : $this->formatAddress($c),
            ], $this->receiverContacts($id)),
        ]);
    }

    /**
     * Whether a receiver (a contact on the document) has Digital Post.
     * hasSubscription is null when it can't be checked (no CPR/CVR number,
     * or the check failed).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function digitalPostSubscription(int $id, int $contactId): JSONResponse {
        if ($this->readableDocument($id) === null) {
            return $this->documentNotFound();
        }
        try {
            $contact = $this->contactService->get($id, $contactId);
        } catch (NotFoundException) {
            return new JSONResponse(['error' => 'Contact not found'], Http::STATUS_NOT_FOUND);
        }
        return new JSONResponse(['hasSubscription' => $this->subscriptionOf($contact)]);
    }

    /**
     * Queue a Digital Post shipment to all the document's receivers.
     * Body (JSON): mainFileId, canBeReplied (default true), includeAttachments
     * (default true). Returns the same as GET …/digital-post.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function sendDigitalPost(int $id): JSONResponse {
        if (!$this->digitalPostService->isEnabled()) {
            return new JSONResponse(['error' => 'Digital Post is not enabled on this server'], Http::STATUS_NOT_IMPLEMENTED);
        }
        $userId = $this->userId ?? '';
        if (!$this->digitalPostService->canSend($id, $userId)) {
            return new JSONResponse(['error' => 'Document not found, or it can\'t be sent by you'], Http::STATUS_NOT_FOUND);
        }

        // As in OpenCase, a document is sent with Digital Post once; after
        // that only the shipment's status is shown.
        if ($this->digitalPostService->latest($id) !== null) {
            return new JSONResponse(['error' => 'The document has already been sent with Digital Post'], Http::STATUS_CONFLICT);
        }

        $contacts = $this->receiverContacts($id);
        if ($contacts === []) {
            return new JSONResponse(['error' => 'The document has no receivers (Modtager)'], Http::STATUS_BAD_REQUEST);
        }

        $mainFileId = $this->request->getParam('mainFileId');
        $mainFileId = is_numeric($mainFileId) ? (int)$mainFileId : null;
        if ($mainFileId !== null && $this->fileMapper->findById($mainFileId)?->getDocumentId() !== $id) {
            return new JSONResponse(['error' => 'mainFileId is not a file of the document'], Http::STATUS_BAD_REQUEST);
        }

        // A receiver without CPR/CVR gets physical post. A failing lookup stops
        // the shipment: it says nothing about the receiver, and a letter meant
        // for Digital Post must not go as physical post because of it.
        try {
            $receivers = array_map(fn(DocumentContact $c) => [
                'document_contact_id' => $c->getId(),
                'cpr_cvr'             => $c->getCprCvr() ?: null,
                'receiver_name'       => $c->getName() ?: null,
                'dp_subscription'     => $c->getCprCvr() ? $this->digitalPostService->hasSubscription((string)$c->getCprCvr()) : false,
            ], $contacts);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->logger->warning('OpenCase desktop: Digital Post subscription check failed: {msg}', ['msg' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => 'Opslaget af Digital Post fejlede, så dokumentet er ikke sendt. Prøv igen om lidt.'],
                Http::STATUS_SERVICE_UNAVAILABLE,
            );
        }

        try {
            $this->digitalPostService->createShipment(
                $id,
                $userId,
                filter_var($this->request->getParam('canBeReplied', true), FILTER_VALIDATE_BOOLEAN),
                filter_var($this->request->getParam('includeAttachments', true), FILTER_VALIDATE_BOOLEAN),
                $mainFileId,
                $receivers,
            );
        } catch (NotFoundException) {
            return $this->documentNotFound();
        } catch (DigitalPostAlreadySentException) {
            return new JSONResponse(['error' => 'The document has already been sent with Digital Post'], Http::STATUS_CONFLICT);
        }

        return new JSONResponse($this->digitalPostState($id), Http::STATUS_CREATED);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function digitalPostFilePdf(int $fileId): DataDownloadResponse|JSONResponse {
        try {
            $path = $this->digitalPostService->shipmentFilePdf($fileId, $this->userId ?? '');
        } catch (NotFoundException) {
            return new JSONResponse(['error' => 'PDF not found'], Http::STATUS_NOT_FOUND);
        }
        return new DataDownloadResponse((string)file_get_contents($path), basename($path), 'application/pdf');
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function digitalPostReceiverPdf(int $receiverId): DataDownloadResponse|JSONResponse {
        try {
            $path = $this->digitalPostService->receiverPdf($receiverId, $this->userId ?? '');
        } catch (NotFoundException) {
            return new JSONResponse(['error' => 'PDF not found'], Http::STATUS_NOT_FOUND);
        }
        return new DataDownloadResponse((string)file_get_contents($path), basename($path), 'application/pdf');
    }

    // ---------------------------------------------------------------
    // Email
    // ---------------------------------------------------------------

    /**
     * The Mail app account emails are sent from. configured=false: the user
     * must add an account in the Mail app first.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function emailAccount(): JSONResponse {
        $status = $this->emailService->accountStatus($this->userId ?? '');
        return new JSONResponse([
            'mailAppEnabled' => $status['mail_app_enabled'],
            'configured'     => $status['configured'],
            'from'           => $status['from'],
        ]);
    }

    /**
     * Search recipients (Nextcloud users, organisations, the case's
     * participants, the document's contacts) by name or address. Each has
     * documentAccess: whether a link to the document would work for them
     * (null when unknown — not an OpenCase user).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function emailRecipients(int $id, string $q = ''): JSONResponse {
        $document = $this->readableDocument($id);
        if ($document === null) {
            return $this->documentNotFound();
        }
        return new JSONResponse(array_map(
            fn(array $r) => $this->serializeRecipient($r, $id),
            $this->emailService->searchRecipients($document, $q),
        ));
    }

    /**
     * Which email addresses belong to OpenCase users who can open the
     * document — for warning before sending a link.
     * Body (JSON): emails — list of addresses.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function emailAccess(int $id): JSONResponse {
        if ($this->readableDocument($id) === null) {
            return $this->documentNotFound();
        }
        $emails = $this->request->getParam('emails');
        $result = [];
        foreach (is_array($emails) ? $emails : [] as $email) {
            $email = trim((string)$email);
            if ($email !== '') {
                $result[] = ['email' => $email, 'documentAccess' => $this->linkWorksFor($email, $id)];
            }
        }
        return new JSONResponse($result);
    }

    /**
     * Send a link to the document in OpenCase. The link (with document
     * number and title) is added below the message by the server.
     * Body (JSON): to, cc, bcc, subject, bodyHtml, saveAsDocument.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function emailLink(int $id): JSONResponse {
        $document = $this->readableDocument($id);
        if ($document === null) {
            return $this->documentNotFound();
        }

        $link = $this->links->appUrl('document/' . $id);
        $label = trim(($document->getDocumentNumber() ?? '') . ' ' . $document->getTitle());
        $body = $this->messageBody()
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">' . htmlspecialchars($label, ENT_QUOTES) . '</a></p>';

        return $this->sendEmail($document, $body, [], DocumentEmailService::VARIANT_LINK, $link);
    }

    /**
     * Send the document's files as PDF. Each file is converted on the server
     * with the case's and document's merge fields filled in (as Digital Post
     * does); PDF files are sent as they are.
     * Body (JSON): to, cc, bcc, subject, bodyHtml, fileIds (default: all the
     * document's files), saveAsDocument.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function emailPdf(int $id): JSONResponse {
        $document = $this->readableDocument($id);
        if ($document === null) {
            return $this->documentNotFound();
        }

        $fileIds = $this->request->getParam('fileIds');
        $fileIds = is_array($fileIds)
            ? array_map('intval', $fileIds)
            : array_map(fn(FileEntity $f) => $f->getId(), $this->fileMapper->findByDocument($id));
        if ($fileIds === []) {
            return new JSONResponse(['error' => 'The document has no files to send'], Http::STATUS_BAD_REQUEST);
        }

        // Check what can be checked before the (slow) conversion
        if ($denied = $this->validateEmail()) {
            return $denied;
        }

        try {
            $attachments = $this->pdfExportService->filesAsPdf($document, $fileIds, $this->userId ?? '');
        } catch (NotFoundException) {
            return new JSONResponse(['error' => 'One or more files were not found'], Http::STATUS_NOT_FOUND);
        } catch (\RuntimeException $e) {
            $this->logger->error('OpenCase desktop: PDF conversion for email failed: {msg}', ['msg' => $e->getPrevious()?->getMessage() ?? $e->getMessage()]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        }
        if ($attachments === []) {
            return new JSONResponse(['error' => 'None of the files belong to the document'], Http::STATUS_BAD_REQUEST);
        }

        return $this->sendEmail($document, $this->messageBody(), $attachments, DocumentEmailService::VARIANT_PDF);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * @param array<int, array{filename: string, mimeType: string, content: string}> $attachments
     */
    private function sendEmail(Document $document, string $bodyHtml, array $attachments, string $variant, string $link = ''): JSONResponse {
        if ($denied = $this->validateEmail()) {
            return $denied;
        }
        try {
            $this->emailService->send(
                $document,
                $this->userId ?? '',
                $this->recipients('to'),
                $this->recipients('cc'),
                $this->recipients('bcc'),
                (string)$this->request->getParam('subject', ''),
                $bodyHtml,
                $attachments,
                filter_var($this->request->getParam('saveAsDocument', true), FILTER_VALIDATE_BOOLEAN),
                $variant,
                $link,
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\DomainException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_PRECONDITION_FAILED);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
        }

        return new JSONResponse([
            'sent'        => true,
            'attachments' => array_map(fn(array $a) => $a['filename'], $attachments),
        ]);
    }

    /** Recipient, subject and account checks shared by the email variants. */
    private function validateEmail(): ?JSONResponse {
        if ($this->recipients('to') === []) {
            return new JSONResponse(['error' => 'At least one recipient (to) with a valid email address is required'], Http::STATUS_BAD_REQUEST);
        }
        if (trim((string)$this->request->getParam('subject', '')) === '') {
            return new JSONResponse(['error' => 'Subject is required'], Http::STATUS_BAD_REQUEST);
        }
        if ($this->emailService->mailService($this->userId ?? '') === null) {
            return new JSONResponse(['error' => 'Der er ikke konfigureret en e-mailkonto i Mail-appen'], Http::STATUS_PRECONDITION_FAILED);
        }
        return null;
    }

    /**
     * Recipients of a field (to/cc/bcc): objects from the recipient search,
     * passed back as received, or {email, name} for a typed address.
     */
    private function recipients(string $field): array {
        $entries = $this->request->getParam($field);
        if (!is_array($entries)) {
            return [];
        }
        // Accept the desktop (camelCase) keys as well as the web ones
        $entries = array_map(fn($e) => is_array($e) ? [
            'email'                  => $e['email'] ?? null,
            'name'                   => $e['name'] ?? null,
            'contact_type'           => $e['contactType'] ?? $e['contact_type'] ?? null,
            'user_id'                => $e['userId'] ?? $e['user_id'] ?? null,
            'cpr_cvr'                => $e['cprCvr'] ?? $e['cpr_cvr'] ?? null,
            'streetname'             => $e['streetname'] ?? null,
            'housenumber'            => $e['housenumber'] ?? null,
            'floor'                  => $e['floor'] ?? null,
            'door'                   => $e['door'] ?? null,
            'zipcode'                => $e['zipcode'] ?? null,
            'zipdistrict'            => $e['zipdistrict'] ?? null,
            'phone'                  => $e['phone'] ?? null,
            'has_address_protection' => $e['addressProtected'] ?? $e['has_address_protection'] ?? false,
            'pnumber'                => $e['pnumber'] ?? null,
        ] : $e, $entries);
        return $this->emailService->normaliseRecipients($entries);
    }

    /** The user's message, reduced to plain formatting (no links, images or scripts). */
    private function messageBody(): string {
        return PhraseHtml::sanitize((string)$this->request->getParam('bodyHtml', ''));
    }

    private function readableDocument(int $id): ?Document {
        $document = $this->documentMapper->findById($id);
        if ($document === null || $document->isSoftDeleted()
            || !$this->permissionService->userHasReadAccessToDocument($this->userId ?? '', $id)) {
            return null;
        }
        return $document;
    }

    private function documentNotFound(): JSONResponse {
        return new JSONResponse(['error' => 'Document not found or no access'], Http::STATUS_NOT_FOUND);
    }

    /**
     * @return DocumentContact[] the document's "Modtager" contacts
     */
    private function receiverContacts(int $documentId): array {
        return array_values(array_filter(
            $this->contactService->listForDocument($documentId),
            fn(DocumentContact $c) => $c->getContactroleId() === self::RECEIVER_ROLE_ID,
        ));
    }

    private function subscriptionOf(DocumentContact $contact): ?bool {
        $cprCvr = (string)$contact->getCprCvr();
        if ($cprCvr === '' || !$this->digitalPostService->isEnabled()) {
            return null;
        }
        try {
            return $this->digitalPostService->hasSubscription($cprCvr);
        } catch (\Throwable $e) {
            $this->logger->warning('OpenCase desktop: Digital Post subscription check failed: {msg}', ['msg' => $e->getMessage()]);
            return null;
        }
    }

    private function digitalPostState(int $documentId): array {
        $enabled = $this->digitalPostService->isEnabled();
        $latest  = $enabled ? $this->digitalPostService->latest($documentId) : null;
        $status  = $latest !== null ? $latest['shipment']->getStatus() : null;

        return [
            'enabled'    => $enabled,
            'canSend'    => $enabled && $latest === null && $this->digitalPostService->canSend($documentId, $this->userId ?? ''),
            'inProgress' => $status !== null && in_array($status, DigitalPostService::IN_PROGRESS_STATUSES, true),
            'shipment'   => $latest === null ? null : [
                'id'                 => $latest['shipment']->getId(),
                'status'             => $this->serializeDpStatus($latest['shipment']->getStatus()),
                'canBeReplied'       => (bool)$latest['shipment']->getCanBeReplied(),
                'includeAttachments' => (bool)$latest['shipment']->getIncludeAttachments(),
                'createdAt'          => $latest['shipment']->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'createdBy'          => $this->links->user($latest['shipment']->getCreatedBy()),
                'updatedAt'          => $latest['shipment']->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
                'files'              => array_map(fn(DpFile $f) => [
                    'id'     => $f->getId(),
                    'name'   => $f->getFileName(),
                    'isMain' => (bool)$f->getIsMain(),
                    'hasPdf' => $f->getStorageFilename() !== null,
                ], $latest['files']),
                'receivers'          => array_map(fn(DpReceiver $r) => [
                    'id'        => $r->getId(),
                    'name'      => $r->getReceiverName() ?? '',
                    'cprCvr'    => $r->getCprCvr(),
                    'status'    => $this->serializeDpStatus($r->getStatus()),
                    'hasPdf'    => $r->getStorageFilename() !== null,
                    'messageId' => $r->getMessageId(),
                ], $latest['receivers']),
            ],
        ];
    }

    /**
     * A shipment or receiver status with its label in the user's language
     * and how to show it: progress, sent or failed.
     */
    private function serializeDpStatus(string $status): array {
        return [
            'code'  => $status,
            'label' => match ($status) {
                'Pending'               => $this->l10n->t('dp_status_Pending'),
                'Generating PDFs'       => $this->l10n->t('dp_status_Generating PDFs'),
                'PDF Generated'         => $this->l10n->t('dp_status_PDF Generated'),
                'PDF Generation Failed' => $this->l10n->t('dp_status_PDF Generation Failed'),
                'Sending'               => $this->l10n->t('dp_status_Sending'),
                'Sent'                  => $this->l10n->t('dp_status_Sent'),
                'Send Failed'           => $this->l10n->t('dp_status_Send Failed'),
                default                 => $status,
            },
            'kind'  => match ($status) {
                'Sent'                                 => 'sent',
                'PDF Generation Failed', 'Send Failed' => 'failed',
                default                                => 'progress',
            },
        ];
    }

    private function serializeFile(FileEntity $f): array {
        return [
            'id'       => $f->getId(),
            'name'     => $f->getVirtualFilename() ?: $f->getOriginalFilename(),
            'mimeType' => $f->getMimeType(),
        ];
    }

    private function formatAddress(DocumentContact $c): string {
        $line1 = implode(' ', array_filter([$c->getStreetname(), $c->getHousenumber(), $c->getFloor(), $c->getDoor()]));
        $line2 = implode(' ', array_filter([$c->getZipcode(), $c->getZipdistrict()]));
        return implode(', ', array_filter([$line1, $line2]));
    }

    /**
     * A recipient from the search, in the desktop API's form. The address
     * of someone with address protection is left out.
     */
    private function serializeRecipient(array $r, int $documentId): array {
        $protected = !empty($r['has_address_protection']);
        $userId    = $r['user_id'] ?? null;
        return [
            'type'             => $r['type'],
            'name'             => $r['name'],
            'email'            => $r['email'],
            'contactType'      => $r['contact_type'] ?? null,
            'userId'           => $userId,
            'cprCvr'           => $r['cpr_cvr'] ?? null,
            'pnumber'          => $r['pnumber'] ?? null,
            'phone'            => $r['phone'] ?? null,
            'addressProtected' => $protected,
            'streetname'       => $protected ? null : ($r['streetname'] ?? null),
            'housenumber'      => $protected ? null : ($r['housenumber'] ?? null),
            'floor'            => $protected ? null : ($r['floor'] ?? null),
            'door'             => $protected ? null : ($r['door'] ?? null),
            'zipcode'          => $protected ? null : ($r['zipcode'] ?? null),
            'zipdistrict'      => $protected ? null : ($r['zipdistrict'] ?? null),
            'documentAccess'   => $userId !== null && $userId !== ''
                ? $this->permissionService->userHasReadAccessToDocument($userId, $documentId)
                : $this->linkWorksFor((string)$r['email'], $documentId),
        ];
    }

    /**
     * Whether the document link works for the owner of an address: true/false
     * for an OpenCase user, null when no user has that address.
     */
    private function linkWorksFor(string $email, int $documentId): ?bool {
        $users = $this->userManager->getByEmail($email);
        if ($users === []) {
            return null;
        }
        foreach ($users as $user) {
            if ($this->permissionService->userHasReadAccessToDocument($user->getUID(), $documentId)) {
                return true;
            }
        }
        return false;
    }
}
