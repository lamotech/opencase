<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\CaseParticipantMapper;
use OCA\OpenCase\Db\ContactTypeMapper;
use OCA\OpenCase\Db\Document;
use OCA\OpenCase\Db\DocumentContact;
use OCA\OpenCase\Db\DocumentContactMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\OrganisationMapper;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\Mail\Provider\Address;
use OCP\Mail\Provider\Attachment;
use OCP\Mail\Provider\IManager;
use OCP\Mail\Provider\IService;
use Psr\Log\LoggerInterface;

/**
 * Sending a document by email through the user's own mail account,
 * configured in the Mail app (OCP Mail Provider API). Shared by the web API
 * (EmailController) and the desktop API.
 *
 * Three variants, each with its own audit log entry on the document:
 *   files — the document's files attached as they are (document_sent_by_email)
 *   pdf   — the files converted to PDF (document_pdf_sent_by_email)
 *   link  — a link to the document in OpenCase, no files (document_link_sent_by_email)
 * A failed send is logged as document_send_by_email_failed with the variant.
 */
class DocumentEmailService {

    /** Contact role id for "Modtager" / "Receiver" (see opencase_contactroles seed data). */
    private const RECEIVER_ROLE_ID = 2;

    /** Document category id for "Udgående" / "Outbound" (see opencase_documentcategory seed data). */
    private const OUTBOUND_CATEGORY_ID = 2;

    public const VARIANT_FILES = 'files';
    public const VARIANT_PDF   = 'pdf';
    public const VARIANT_LINK  = 'link';

    public function __construct(
        private IAppManager $appManager,
        private IManager $mailManager,
        private IUserManager $userManager,
        private DocumentMapper $documentMapper,
        private CaseMapper $caseMapper,
        private OrganisationMapper $organisationMapper,
        private CaseParticipantMapper $caseParticipantMapper,
        private DocumentContactMapper $documentContactMapper,
        private FileService $fileService,
        private DocumentService $documentService,
        private AuditService $auditService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The first Mail app account of the user, or null if the Mail app isn't
     * enabled or the user has no account in it.
     */
    public function mailService(string $userId): ?IService {
        if (!$this->appManager->isEnabledForUser('mail')) {
            return null;
        }
        $provider = $this->mailManager->findProviderById('mail-application');
        if ($provider === null) {
            return null;
        }
        $services = $provider->listServices($userId);
        return empty($services) ? null : array_values($services)[0];
    }

    /**
     * @return array{mail_app_enabled: bool, configured: bool, from: array{address: string, label: string}|null}
     */
    public function accountStatus(string $userId): array {
        $mailAppEnabled = $this->appManager->isEnabledForUser('mail');
        $service = $mailAppEnabled ? $this->mailService($userId) : null;
        return [
            'mail_app_enabled' => $mailAppEnabled,
            'configured'       => $service !== null,
            'from'             => $service !== null ? [
                'address' => (string)$service->getPrimaryAddress()->getAddress(),
                'label'   => (string)($service->getPrimaryAddress()->getLabel() ?: $service->getLabel()),
            ] : null,
        ];
    }

    /**
     * Recipients matching $q (at least 2 characters) with a known email
     * address: Nextcloud users, organisations, the case's participants and
     * the document's contacts. Callers check read access to the document.
     *
     * @return list<array<string, mixed>>
     */
    public function searchRecipients(Document $document, string $q): array {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
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
        foreach ($this->documentContactMapper->findByDocument($document->getId()) as $contact) {
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

        return array_slice($results, 0, 30);
    }

    /**
     * Send an email about the document and log it on the document. Callers
     * check access to the document and build the attachments.
     *
     * @param array<int, array<string, mixed>> $to  normalised recipients, see normaliseRecipients()
     * @param array<int, array<string, mixed>> $cc
     * @param array<int, array<string, mixed>> $bcc
     * @param array<int, array{filename: string, mimeType: string, content: string}> $attachments
     * @param string $variant one of the VARIANT_* constants (decides the audit entry)
     * @param string $link    the document link, for the link variant
     *
     * @throws \InvalidArgumentException no recipient or subject
     * @throws \DomainException          the user has no Mail app account
     * @throws \RuntimeException         sending failed (logged on the document)
     */
    public function send(
        Document $document,
        string $userId,
        array $to,
        array $cc,
        array $bcc,
        string $subject,
        string $bodyHtml,
        array $attachments,
        bool $saveAsDocument,
        string $variant,
        string $link = '',
    ): void {
        if (empty($to)) {
            throw new \InvalidArgumentException('At least one recipient (To) is required');
        }
        $subject = trim($subject);
        if ($subject === '') {
            throw new \InvalidArgumentException('Subject is required');
        }

        $service = $this->mailService($userId);
        if ($service === null) {
            throw new \DomainException('Der er ikke konfigureret en e-mailkonto i Mail-appen');
        }

        try {
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
                $message->setAttachments(...array_map(
                    fn(array $a) => new Attachment($a['content'], $a['filename'], $a['mimeType']),
                    $attachments,
                ));
            }
            $service->sendMessage($message);
        } catch (\Throwable $e) {
            $this->auditService->logDocumentSendByEmailFailed($document->getCaseId(), $document->getId(), $userId, $e->getMessage(), $variant);
            throw new \RuntimeException('Kunne ikke sende e-mail: ' . $e->getMessage(), 0, $e);
        }

        $from      = (string)$service->getPrimaryAddress()->getAddress();
        $toEmails  = array_map(fn($a) => $a['email'], $to);
        $ccEmails  = array_map(fn($a) => $a['email'], $cc);
        $bccEmails = array_map(fn($a) => $a['email'], $bcc);
        $filenames = array_map(fn($a) => $a['filename'], $attachments);
        match ($variant) {
            self::VARIANT_LINK => $this->auditService->logDocumentLinkSentByEmail(
                $document->getCaseId(), $document->getId(), $userId, $from, $toEmails, $ccEmails, $bccEmails, $subject, $link),
            self::VARIANT_PDF => $this->auditService->logDocumentPdfSentByEmail(
                $document->getCaseId(), $document->getId(), $userId, $from, $toEmails, $ccEmails, $bccEmails, $subject, $filenames),
            default => $this->auditService->logDocumentSentByEmail(
                $document->getCaseId(), $document->getId(), $userId, $from, $toEmails, $ccEmails, $bccEmails, $subject, $filenames),
        };

        if ($saveAsDocument) {
            try {
                $this->saveEmailAsDocument($document, $userId, $subject, $bodyHtml, $service->getPrimaryAddress(), $to, $cc, $bcc, $attachments);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to save sent email as a document on the case: ' . $e->getMessage(), ['exception' => $e]);
            }
        }
    }

    /**
     * Recipients as sent by a client — [{email, name, contact_type, user_id,
     * cpr_cvr, …}] — validated and normalised; entries without a valid
     * address are dropped.
     *
     * @return array<int, array{
     *     name: string, email: string, contact_type: int, user_id: ?string, cpr_cvr: ?string,
     *     streetname: ?string, housenumber: ?string, floor: ?string, door: ?string,
     *     zipcode: ?string, zipdistrict: ?string, phone: ?string, has_address_protection: bool, pnumber: ?string,
     * }>
     */
    public function normaliseRecipients(mixed $entries): array {
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

    private function matchesQuery(string $q, ?string $name, ?string $email): bool {
        $needle = mb_strtolower($q);
        return ($name && str_contains(mb_strtolower($name), $needle))
            || ($email && str_contains(mb_strtolower($email), $needle));
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
        string $userId,
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
            $userId,
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
        $this->fileService->upload($newDocument->getId(), $filename, 'message/rfc822', $emlContent, $userId);

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
                $userId,
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

    private function nullableString(mixed $value): ?string {
        if ($value === null) {
            return null;
        }
        $str = trim((string)$value);
        return $str === '' ? null : $str;
    }
}
