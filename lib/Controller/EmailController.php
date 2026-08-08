<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\CaseParticipantMapper;
use OCA\OpenCase\Db\ContactTypeMapper;
use OCA\OpenCase\Db\Document;
use OCA\OpenCase\Db\DocumentContact;
use OCA\OpenCase\Db\DocumentContactMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\FileMapper;
use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Service\AuditService;
use OCA\OpenCase\Service\DocumentService;
use OCA\OpenCase\Service\FileService;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\PermissionService;
use OCP\App\IAppManager;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Mail\Provider\Address;
use OCP\Mail\Provider\Attachment;
use OCP\Mail\Provider\Exception\SendException;
use OCP\Mail\Provider\IManager;
use OCP\Mail\Provider\IService;
use Psr\Log\LoggerInterface;

/**
 * REST endpoints for sending a document by email through the user's
 * own mail account, configured via the Mail app (OCP Mail Provider API).
 *
 * Routes:
 *   GET  /api/v1/email/account-status                      — Is Mail app enabled & does the user have an account?
 *   GET  /api/v1/documents/{documentId}/email-recipients    — Search recipients (users/orgs/participants/contacts)
 *   POST /api/v1/documents/{documentId}/send-email          — Send the document's files as email attachments
 */
class EmailController extends ApiController {

    /** Contact role id for "Modtager" / "Receiver" (see opencase_contactroles seed data). */
    private const RECEIVER_ROLE_ID = 2;

    /** Document category id for "Udgående" / "Outbound" (see opencase_documentcategory seed data). */
    private const OUTBOUND_CATEGORY_ID = 2;

    public function __construct(
        string $appName,
        IRequest $request,
        private IAppManager $appManager,
        private IManager $mailManager,
        private IUserManager $userManager,
        private DocumentMapper $documentMapper,
        private FileMapper $fileMapper,
        private CaseMapper $caseMapper,
        private OrganisationMapper $organisationMapper,
        private CaseParticipantMapper $caseParticipantMapper,
        private DocumentContactMapper $documentContactMapper,
        private PermissionService $permissionService,
        private FileService $fileService,
        private DocumentService $documentService,
        private AuditService $auditService,
        private LoggerInterface $logger,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Find the first mail-application (Mail app) service configured for the
     * current user, or null if the Mail app isn't installed/enabled or the
     * user has no account configured in it.
     */
    private function findMailService(): ?IService {
        if (!$this->appManager->isEnabledForUser('mail')) {
            return null;
        }
        $provider = $this->mailManager->findProviderById('mail-application');
        if ($provider === null || $this->userId === null) {
            return null;
        }
        $services = $provider->listServices($this->userId);
        if (empty($services)) {
            return null;
        }
        return array_values($services)[0];
    }

    #[NoAdminRequired]
    public function accountStatus(): DataResponse {
        $mailAppEnabled = $this->appManager->isEnabledForUser('mail');
        $service = $mailAppEnabled ? $this->findMailService() : null;

        return new DataResponse([
            'mail_app_enabled' => $mailAppEnabled,
            'configured'       => $service !== null,
            'from'             => $service !== null ? [
                'address' => $service->getPrimaryAddress()->getAddress(),
                'label'   => $service->getPrimaryAddress()->getLabel() ?: $service->getLabel(),
            ] : null,
        ]);
    }

    /**
     * Search recipients across Nextcloud users, organisations, case
     * participants (of the document's case) and document contacts.
     * Only entries with a known email address are returned.
     */
    #[NoAdminRequired]
    public function recipients(int $documentId): DataResponse {
        $q = trim($this->request->getParam('q', ''));
        if (strlen($q) < 2) {
            return new DataResponse(['recipients' => []]);
        }

        $document = $this->documentMapper->findById($documentId);
        if ($document === null || $this->userId === null
            || !$this->permissionService->userHasReadAccessToDocument($this->userId, $documentId)
        ) {
            return new DataResponse(['error' => 'Document not found'], Http::STATUS_NOT_FOUND);
        }

        $results = [];
        $seen = [];
        $add = function (string $type, string $name, ?string $email, array $extra = []) use (&$results, &$seen) {
            if (!$email) {
                return;
            }
            $key = strtolower($email);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $results[] = array_merge([
                'type'  => $type,
                'name'  => $name ?: $email,
                'email' => $email,
            ], $extra);
        };

        // Nextcloud users — internal employees
        foreach ($this->userManager->searchDisplayName($q, 15) as $user) {
            $add('user', $user->getDisplayName(), $user->getEMailAddress(), [
                'contact_type' => ContactTypeMapper::EMPLOYEE,
                'user_id'      => $user->getUID(),
            ]);
        }

        // Organisations — not a citizen/company/employee
        foreach ($this->organisationMapper->searchByName($q, 15) as $org) {
            $add('organisation', $org->getOrgName(), $org->getEmail(), [
                'contact_type' => ContactTypeMapper::UNKNOWN,
            ]);
        }

        // Case participants (on the document's parent case)
        foreach ($this->caseParticipantMapper->findByCase($document->getCaseId()) as $participant) {
            if ($this->matchesQuery($q, $participant->getName(), $participant->getEmail())) {
                $add('participant', $participant->getName() ?? '', $participant->getEmail(), [
                    'contact_type'           => $participant->getContactType(),
                    'user_id'                => $participant->getUserId(),
                    'cpr_cvr'                => $participant->getCprCvr(),
                    'streetname'             => $participant->getStreetname(),
                    'housenumber'            => $participant->getHousenumber(),
                    'floor'                  => $participant->getFloor(),
                    'door'                   => $participant->getDoor(),
                    'zipcode'                => $participant->getZipcode(),
                    'zipdistrict'            => $participant->getZipdistrict(),
                    'phone'                  => $participant->getPhone(),
                    'has_address_protection' => (bool)$participant->getHasAddressProtection(),
                    'pnumber'                => $participant->getPnumber(),
                ]);
            }
        }

        // Document contacts (senders/receivers on this document)
        foreach ($this->documentContactMapper->findByDocument($documentId) as $contact) {
            if ($this->matchesQuery($q, $contact->getName(), $contact->getEmail())) {
                $add('contact', $contact->getName() ?? '', $contact->getEmail(), [
                    'contact_type'           => $contact->getContactType(),
                    'user_id'                => $contact->getUserId(),
                    'cpr_cvr'                => $contact->getCprCvr(),
                    'streetname'             => $contact->getStreetname(),
                    'housenumber'            => $contact->getHousenumber(),
                    'floor'                  => $contact->getFloor(),
                    'door'                   => $contact->getDoor(),
                    'zipcode'                => $contact->getZipcode(),
                    'zipdistrict'            => $contact->getZipdistrict(),
                    'phone'                  => $contact->getPhone(),
                    'has_address_protection' => (bool)$contact->getHasAddressProtection(),
                    'pnumber'                => $contact->getPnumber(),
                ]);
            }
        }

        return new DataResponse(['recipients' => array_slice($results, 0, 30)]);
    }

    private function matchesQuery(string $q, ?string $name, ?string $email): bool {
        $needle = mb_strtolower($q);
        return ($name && str_contains(mb_strtolower($name), $needle))
            || ($email && str_contains(mb_strtolower($email), $needle));
    }

    /**
     * Send the document (and selected attached files) by email through the
     * user's configured Mail app account.
     */
    #[NoAdminRequired]
    public function send(int $documentId): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $document = $this->documentMapper->findById($documentId);
        if ($document === null || !$this->permissionService->userHasReadAccessToDocument($this->userId, $documentId)) {
            return new DataResponse(['error' => 'Document not found'], Http::STATUS_NOT_FOUND);
        }

        $params = $this->request->getParams();
        $to  = $this->extractRecipients($params['to'] ?? []);
        $cc  = $this->extractRecipients($params['cc'] ?? []);
        $bcc = $this->extractRecipients($params['bcc'] ?? []);
        $subject = trim((string)($params['subject'] ?? ''));
        $bodyHtml = (string)($params['body_html'] ?? '');
        $fileIds = array_map('intval', is_array($params['file_ids'] ?? null) ? $params['file_ids'] : []);
        $saveAsDocument = !array_key_exists('save_as_document', $params)
            || filter_var($params['save_as_document'], FILTER_VALIDATE_BOOLEAN);

        if (empty($to)) {
            return new DataResponse(['error' => 'At least one recipient (To) is required'], Http::STATUS_BAD_REQUEST);
        }
        if ($subject === '') {
            return new DataResponse(['error' => 'Subject is required'], Http::STATUS_BAD_REQUEST);
        }

        $service = $this->findMailService();
        if ($service === null) {
            return new DataResponse(
                ['error' => 'Der er ikke konfigureret en e-mailkonto i Mail-appen'],
                Http::STATUS_PRECONDITION_FAILED
            );
        }

        try {
            $attachments = [];
            $rawAttachments = [];
            $filenames = [];
            foreach ($fileIds as $fileId) {
                $result = $this->fileService->download($fileId, $this->userId);
                $entity = $result['entity'];
                if ($entity->getDocumentId() !== $documentId) {
                    continue;
                }
                $content = stream_get_contents($result['stream']);
                fclose($result['stream']);
                $filename = $entity->getVirtualFilename() ?: $entity->getOriginalFilename();
                $attachments[] = new Attachment($content, $filename, $entity->getMimeType());
                $rawAttachments[] = ['filename' => $filename, 'mimeType' => $entity->getMimeType(), 'content' => $content];
                $filenames[] = $filename;
            }

            $message = $service->initiateMessage();
            $message->setFrom($service->getPrimaryAddress());
            $message->setTo(...array_map(fn($a) => new Address($a['email'], $a['name']), $to));
            if (!empty($cc)) {
                $message->setCc(...array_map(fn($a) => new Address($a['email'], $a['name']), $cc));
            }
            if (!empty($bcc)) {
                $message->setBcc(...array_map(fn($a) => new Address($a['email'], $a['name']), $bcc));
            }
            $message->setSubject($subject);
            $message->setBodyHtml($bodyHtml);
            if (!empty($attachments)) {
                $message->setAttachments(...$attachments);
            }

            $service->sendMessage($message);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => 'En eller flere filer blev ikke fundet'], Http::STATUS_NOT_FOUND);
        } catch (SendException|\Throwable $e) {
            $this->auditService->logDocumentSendByEmailFailed(
                $document->getCaseId(),
                $documentId,
                $this->userId,
                $e->getMessage(),
            );
            return new DataResponse(['error' => 'Kunne ikke sende e-mail: ' . $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $this->auditService->logDocumentSentByEmail(
            $document->getCaseId(),
            $documentId,
            $this->userId,
            $service->getPrimaryAddress()->getAddress() ?? '',
            array_map(fn($a) => $a['email'], $to),
            array_map(fn($a) => $a['email'], $cc),
            array_map(fn($a) => $a['email'], $bcc),
            $subject,
            $filenames,
        );

        if ($saveAsDocument) {
            try {
                $this->saveEmailAsDocument(
                    $document,
                    $subject,
                    $bodyHtml,
                    $service->getPrimaryAddress(),
                    $to,
                    $cc,
                    $bcc,
                    $rawAttachments,
                );
            } catch (\Throwable $e) {
                $this->logger->error('Failed to save sent email as a document on the case: ' . $e->getMessage(), ['exception' => $e]);
            }
        }

        return new DataResponse(['success' => true]);
    }

    /**
     * Save the just-sent email as a new document (.eml) on the same case,
     * with the recipients added as "Modtager" (Receiver) contacts.
     *
     * @param array<int, array<string, mixed>> $to
     * @param array<int, array<string, mixed>> $cc
     * @param array<int, array<string, mixed>> $bcc
     * @param array<int, array{filename: string, mimeType: string, content: string}> $attachments
     */
    private function saveEmailAsDocument(
        Document $document,
        string $subject,
        string $bodyHtml,
        Address $from,
        array $to,
        array $cc,
        array $bcc,
        array $attachments,
    ): void {
        $case = $this->caseMapper->findById($document->getCaseId());
        $title = $subject !== '' ? $subject : $document->getTitle();

        $newDocument = $this->documentService->create(
            $document->getCaseId(),
            $title,
            null,
            $this->userId,
            $case?->getInsightLevelId(),
            null,
            null,
            null,
            self::OUTBOUND_CATEGORY_ID,
        );

        $emlContent = $this->buildEmlContent(
            $from->getAddress() ?? '',
            $from->getLabel() ?? '',
            $to,
            $cc,
            $bcc,
            $subject,
            $bodyHtml,
            $attachments,
        );

        $filename = $this->sanitizeFilename($title) . '.eml';
        $this->fileService->upload($newDocument->getId(), $filename, 'message/rfc822', $emlContent, $this->userId);

        $recipients = [];
        $seen = [];
        foreach ([...$to, ...$cc, ...$bcc] as $recipient) {
            $key = strtolower($recipient['email']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $recipients[] = $recipient;
        }

        foreach ($recipients as $recipient) {
            $contact = new DocumentContact();
            $contact->setDocumentId($newDocument->getId());
            $contact->setContactroleId(self::RECEIVER_ROLE_ID);
            $contact->setContactType($recipient['contact_type'] ?? ContactTypeMapper::UNKNOWN);
            $contact->setUserId($recipient['user_id']);
            $contact->setCprCvr($recipient['cpr_cvr']);
            $contact->setName($recipient['name']);
            $contact->setStreetname($recipient['streetname']);
            $contact->setHousenumber($recipient['housenumber']);
            $contact->setFloor($recipient['floor']);
            $contact->setDoor($recipient['door']);
            $contact->setZipcode($recipient['zipcode']);
            $contact->setZipdistrict($recipient['zipdistrict']);
            $contact->setPhone($recipient['phone']);
            $contact->setEmail($recipient['email']);
            $contact->setHasAddressProtection($recipient['has_address_protection']);
            $contact->setPnumber($recipient['pnumber']);

            $saved = $this->documentContactMapper->insert($contact);

            $this->auditService->logContactAdded(
                $document->getCaseId(),
                $newDocument->getId(),
                $this->userId ?? '',
                'Modtager',
                $saved->getName() ?? '',
                $saved->getCprCvr() ?? '',
            );
        }
    }

    /**
     * Build a raw RFC 5322 / RFC 2045 MIME message (.eml) from the already
     * composed and sent email, so it can be stored as a document.
     *
     * @param array<int, array<string, mixed>> $to
     * @param array<int, array<string, mixed>> $cc
     * @param array<int, array<string, mixed>> $bcc
     * @param array<int, array{filename: string, mimeType: string, content: string}> $attachments
     */
    private function buildEmlContent(
        string $fromAddress,
        string $fromLabel,
        array $to,
        array $cc,
        array $bcc,
        string $subject,
        string $bodyHtml,
        array $attachments,
    ): string {
        $boundary = 'oc_' . bin2hex(random_bytes(16));
        $formatAddress = fn(array $a) => ($a['name'] && $a['name'] !== $a['email'])
            ? sprintf('%s <%s>', $this->encodeHeader($a['name']), $a['email'])
            : $a['email'];

        $lines = [];
        $lines[] = 'From: ' . (($fromLabel !== '' && $fromLabel !== $fromAddress)
            ? sprintf('%s <%s>', $this->encodeHeader($fromLabel), $fromAddress)
            : $fromAddress);
        $lines[] = 'To: ' . implode(', ', array_map($formatAddress, $to));
        if (!empty($cc)) {
            $lines[] = 'Cc: ' . implode(', ', array_map($formatAddress, $cc));
        }
        if (!empty($bcc)) {
            $lines[] = 'Bcc: ' . implode(', ', array_map($formatAddress, $bcc));
        }
        $lines[] = 'Subject: ' . $this->encodeHeader($subject);
        $lines[] = 'Date: ' . (new \DateTime())->format(\DateTime::RFC2822);
        $lines[] = 'MIME-Version: 1.0';
        $lines[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $lines[] = '';
        $lines[] = '--' . $boundary;
        $lines[] = 'Content-Type: text/html; charset=UTF-8';
        $lines[] = 'Content-Transfer-Encoding: base64';
        $lines[] = '';
        $lines[] = chunk_split(base64_encode($bodyHtml));

        foreach ($attachments as $attachment) {
            $lines[] = '--' . $boundary;
            $lines[] = sprintf('Content-Type: %s; name="%s"', $attachment['mimeType'], $attachment['filename']);
            $lines[] = 'Content-Transfer-Encoding: base64';
            $lines[] = sprintf('Content-Disposition: attachment; filename="%s"', $attachment['filename']);
            $lines[] = '';
            $lines[] = chunk_split(base64_encode($attachment['content']));
        }

        $lines[] = '--' . $boundary . '--';

        return implode("\r\n", $lines);
    }

    private function encodeHeader(string $value): string {
        return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
    }

    private function sanitizeFilename(string $name): string {
        return preg_replace('/[^\w\-. ]/', '_', $name) ?: 'email';
    }

    /**
     * @return array<int, array{
     *     name: string, email: string, contact_type: int, user_id: ?string, cpr_cvr: ?string,
     *     streetname: ?string, housenumber: ?string, floor: ?string, door: ?string,
     *     zipcode: ?string, zipdistrict: ?string, phone: ?string, has_address_protection: bool, pnumber: ?string,
     * }>
     */
    private function extractRecipients(mixed $entries): array {
        if (!is_array($entries)) {
            return [];
        }
        $out = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $email = trim((string)($entry['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $name = trim((string)($entry['name'] ?? '')) ?: $email;
            $contactType = isset($entry['contact_type']) && $entry['contact_type'] !== '' && $entry['contact_type'] !== null
                ? (int)$entry['contact_type']
                : ContactTypeMapper::UNKNOWN;

            $out[] = [
                'name'                   => $name,
                'email'                  => $email,
                'contact_type'           => $contactType,
                'user_id'                => $this->nullableString($entry['user_id'] ?? null),
                'cpr_cvr'                => $this->nullableString($entry['cpr_cvr'] ?? null),
                'streetname'             => $this->nullableString($entry['streetname'] ?? null),
                'housenumber'            => $this->nullableString($entry['housenumber'] ?? null),
                'floor'                  => $this->nullableString($entry['floor'] ?? null),
                'door'                   => $this->nullableString($entry['door'] ?? null),
                'zipcode'                => $this->nullableString($entry['zipcode'] ?? null),
                'zipdistrict'            => $this->nullableString($entry['zipdistrict'] ?? null),
                'phone'                  => $this->nullableString($entry['phone'] ?? null),
                'has_address_protection' => !empty($entry['has_address_protection']),
                'pnumber'                => $this->nullableString($entry['pnumber'] ?? null),
            ];
        }
        return $out;
    }

    private function nullableString(mixed $value): ?string {
        if ($value === null) {
            return null;
        }
        $str = trim((string)$value);
        return $str === '' ? null : $str;
    }
}
