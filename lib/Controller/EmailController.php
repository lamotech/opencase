<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Service\DocumentEmailService;
use OCA\OpenCase\Service\FileService;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\PermissionService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;

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

    public function __construct(
        string $appName,
        IRequest $request,
        private DocumentEmailService $emailService,
        private DocumentMapper $documentMapper,
        private PermissionService $permissionService,
        private FileService $fileService,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function accountStatus(): DataResponse {
        return new DataResponse($this->emailService->accountStatus($this->userId ?? ''));
    }

    /**
     * Search recipients across Nextcloud users, organisations, case
     * participants (of the document's case) and document contacts.
     * Only entries with a known email address are returned.
     */
    #[NoAdminRequired]
    public function recipients(int $documentId): DataResponse {
        $q = trim((string)$this->request->getParam('q', ''));
        if (strlen($q) < 2) {
            return new DataResponse(['recipients' => []]);
        }

        $document = $this->documentMapper->findById($documentId);
        if ($document === null || $this->userId === null
            || !$this->permissionService->userHasReadAccessToDocument($this->userId, $documentId)
        ) {
            return new DataResponse(['error' => 'Document not found'], Http::STATUS_NOT_FOUND);
        }

        return new DataResponse(['recipients' => $this->emailService->searchRecipients($document, $q)]);
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
        $fileIds = array_map('intval', is_array($params['file_ids'] ?? null) ? $params['file_ids'] : []);
        $saveAsDocument = !array_key_exists('save_as_document', $params)
            || filter_var($params['save_as_document'], FILTER_VALIDATE_BOOLEAN);

        try {
            $attachments = [];
            foreach ($fileIds as $fileId) {
                $result = $this->fileService->download($fileId, $this->userId);
                $entity = $result['entity'];
                if ($entity->getDocumentId() !== $documentId) {
                    fclose($result['stream']);
                    continue;
                }
                $content = stream_get_contents($result['stream']);
                fclose($result['stream']);
                $attachments[] = [
                    'filename' => $entity->getVirtualFilename() ?: $entity->getOriginalFilename(),
                    'mimeType' => $entity->getMimeType(),
                    'content'  => $content,
                ];
            }
        } catch (NotFoundException) {
            return new DataResponse(['error' => 'En eller flere filer blev ikke fundet'], Http::STATUS_NOT_FOUND);
        }

        try {
            $this->emailService->send(
                $document,
                $this->userId,
                $this->emailService->normaliseRecipients($params['to'] ?? []),
                $this->emailService->normaliseRecipients($params['cc'] ?? []),
                $this->emailService->normaliseRecipients($params['bcc'] ?? []),
                (string)($params['subject'] ?? ''),
                (string)($params['body_html'] ?? ''),
                $attachments,
                $saveAsDocument,
                DocumentEmailService::VARIANT_FILES,
            );
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\DomainException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_PRECONDITION_FAILED);
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new DataResponse(['success' => true]);
    }
}
