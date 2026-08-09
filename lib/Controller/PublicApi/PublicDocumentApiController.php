<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller\PublicApi;

use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\ContactTypeMapper;
use OCA\OpenCase\Db\Document;
use OCA\OpenCase\Db\DocumentContact;
use OCA\OpenCase\Db\DocumentContactMapper;
use OCA\OpenCase\Db\DocumentNote;
use OCA\OpenCase\Db\DocumentNoteMapper;
use OCA\OpenCase\Db\DocumentStatusMapper;
use OCA\OpenCase\Db\ContactRoleMapper;
use OCA\OpenCase\Service\AuditService;
use OCA\OpenCase\Service\CaseExportService;
use OCA\OpenCase\Service\CaseService;
use OCA\OpenCase\Service\CprCvrLookupService;
use OCA\OpenCase\Service\DocumentService;
use OCA\OpenCase\Service\FileService;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\TemplateMergeService;
use OCA\OpenCase\Service\TemplateService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Public data REST API for documents.
 *
 * GET  /index.php/apps/opencase/public/v1/api/documents?uuid=...|document_number=...
 *   — a single document: DocumentType (Files, Contacts, Notes, WorkflowHistory nested)
 * GET  /index.php/apps/opencase/public/v1/api/documents/search?...
 *   — search documents by metadata (see search() for filters): DocumentSearchResult
 *     { Total, Limit, Offset, Documents: [{Id, Uuid, DocumentNumber, Title, ...}, ...] }
 * POST /index.php/apps/opencase/public/v1/api/documents/contacts
 *   — add one or more contacts (sender/receiver) to a document. Body:
 *       Uuid or DocumentNumber (required, one of)
 *       Contacts: list of {ContactRoleId, CprCvr}
 *     CprCvr is looked up via Datafordeler — 10 digits resolves a citizen
 *     (CPR), 8 digits resolves a company (CVR); name/address/etc. come back
 *     from that lookup, not from the request. Each contact is resolved
 *     independently — one bad entry doesn't fail the others; the response
 *     lists a result (or Error) per submitted entry.
 * POST /index.php/apps/opencase/public/v1/api/documents/files
 *   — upload a file to a document. Body:
 *       Uuid or DocumentNumber (required, one of)
 *       FileName   (required)
 *       Content    (required) — base64-encoded file content
 *       MimeType   (optional, defaults to application/octet-stream)
 * POST /index.php/apps/opencase/public/v1/api/documents/files/from-template
 *   — create a file on a document by merging a template (see
 *     GET .../templates) with case/document metadata. Body:
 *       Uuid or DocumentNumber (required, one of)
 *       TemplateId (required)
 *     Both return the created file as FileType.
 * POST /index.php/apps/opencase/public/v1/api/documents/notes
 *   — add a note to a document. Body:
 *       Uuid or DocumentNumber (required, one of)
 *       Title (required), Text (optional)
 * PUT  /index.php/apps/opencase/public/v1/api/documents
 *   — update an existing document's metadata. Body: Uuid or DocumentNumber
 *     (required, identifies the document) plus any subset of:
 *       Title, DocumentType, Status (1/2/3), InsightLevelId,
 *       DocumentDate, ReceivedDate, RegisteredDate
 *     Omitted fields are left unchanged; InsightLevelId/DocumentDate/
 *     ReceivedDate/RegisteredDate can be explicitly cleared by sending
 *     them empty. Setting Status to a Final status locks the document's
 *     files read-only, matching the internal UI's behaviour.
 *
 * See AbstractPublicDataApiController for auth and response-format handling.
 */
class PublicDocumentApiController extends AbstractPublicDataApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private DocumentService $documentService,
        private CaseService $caseService,
        private CaseExportService $caseExportService,
        private DocumentContactMapper $documentContactMapper,
        private ContactRoleMapper $contactRoleMapper,
        private DocumentNoteMapper $documentNoteMapper,
        private DocumentStatusMapper $documentStatusMapper,
        private AuditService $auditService,
        private CprCvrLookupService $cprCvrLookupService,
        private FileService $fileService,
        private TemplateService $templateService,
        private TemplateMergeService $templateMergeService,
        private CaseMapper $caseMapper,
        IUserSession $userSession,
    ) {
        parent::__construct($appName, $request, $userSession);
    }

    /**
     * Search documents by metadata (query parameters, all optional): title,
     * document_type, status, date_from, date_to, org_name,
     * document_category_id, insight_level_id, created_by, limit (default
     * 50, max 1000), offset.
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function search(): Response {
        $userId = $this->actingUserId();
        if ($userId === null) {
            return $this->errorResponse('No acting user resolved', Http::STATUS_FORBIDDEN);
        }

        $title               = $this->request->getParam('title') ?: null;
        $documentType        = $this->request->getParam('document_type') ?: null;
        $statusRaw           = $this->request->getParam('status');
        $status              = ($statusRaw !== null && $statusRaw !== '') ? (int)$statusRaw : null;
        $dateFrom            = $this->request->getParam('date_from') ?: null;
        $dateTo              = $this->request->getParam('date_to') ?: null;
        $orgName             = $this->request->getParam('org_name') ?: null;
        $documentCategoryRaw = $this->request->getParam('document_category_id');
        $documentCategoryId  = ($documentCategoryRaw !== null && $documentCategoryRaw !== '') ? (int)$documentCategoryRaw : null;
        $insightLevelRaw     = $this->request->getParam('insight_level_id');
        $insightLevelId      = ($insightLevelRaw !== null && $insightLevelRaw !== '') ? (int)$insightLevelRaw : null;
        $createdBy           = $this->request->getParam('created_by') ?: null;
        $limit               = max(1, min((int)$this->request->getParam('limit', '50'), 1000));
        $offset              = max(0, (int)$this->request->getParam('offset', '0'));

        $result = $this->documentService->searchDocuments(
            $userId,
            $title,
            $documentType,
            $status,
            $dateFrom,
            $dateTo,
            $orgName,
            $limit,
            $offset,
            $documentCategoryId,
            $insightLevelId,
            $createdBy,
        );

        $documents = array_map(static function (array $row): array {
            return [
                'Id'             => (string)$row['id'],
                'Uuid'           => $row['uuid'],
                'DocumentNumber' => $row['document_number'],
                'Title'          => $row['title'],
                'DocumentType'   => $row['document_type'],
                'Status'         => (string)$row['status'],
                'DocumentDate'   => $row['document_date'],
                'ReceivedDate'   => $row['received_date'],
                'CreatedAt'      => $row['created_at'],
                'UpdatedAt'      => $row['updated_at'],
                'CreatedBy'      => $row['created_by'],
                'CaseId'         => (string)$row['case_id'],
                'CaseNumber'     => $row['case_number'],
                'CaseTitle'      => $row['case_title'],
                'OrgName'        => $row['org_name'],
            ];
        }, $result['documents']);

        $payload = [
            'Total'     => (string)$result['total'],
            'Limit'     => (string)$limit,
            'Offset'    => (string)$offset,
            'Documents' => $documents,
        ];

        return $this->respond('DocumentSearchResult', $payload);
    }

    /**
     * Retrieve a single document by uuid or document_number (exactly one required).
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(?string $uuid = null, ?string $document_number = null): Response {
        try {
            $entity = $this->resolveDocument($uuid, $document_number);
        } catch (PublicDocumentApiError $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatusCode());
        }

        $fields = $this->caseExportService->singleDocumentFields($entity);

        return $this->respond('Document', $fields);
    }

    /**
     * Update an existing document's metadata. Only fields present in the
     * body are changed.
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function update(): Response {
        $params = $this->requestBodyParams();

        $uuid           = $this->bodyValue($params, 'Uuid', 'uuid');
        $documentNumber = $this->bodyValue($params, 'DocumentNumber', 'document_number');

        try {
            $documentEntity = $this->resolveDocument($uuid, $documentNumber);
        } catch (PublicDocumentApiError $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatusCode());
        }

        $title        = $this->bodyValue($params, 'Title', 'title');
        $documentType = $this->bodyValue($params, 'DocumentType', 'document_type');

        $statusRaw = $this->bodyValue($params, 'Status', 'status');
        $status    = $statusRaw !== null ? (int)$statusRaw : null;

        $insightLevelProvided = $this->bodyKeyProvided($params, 'InsightLevelId', 'insight_level_id');
        $insightLevelRaw      = $this->bodyValue($params, 'InsightLevelId', 'insight_level_id');
        $insightLevelId       = $insightLevelRaw !== null ? (int)$insightLevelRaw : null;

        $documentDateProvided = $this->bodyKeyProvided($params, 'DocumentDate', 'document_date');
        $documentDate         = $this->bodyValue($params, 'DocumentDate', 'document_date');

        $receivedDateProvided = $this->bodyKeyProvided($params, 'ReceivedDate', 'received_date');
        $receivedDate         = $this->bodyValue($params, 'ReceivedDate', 'received_date');

        $registeredDateProvided = $this->bodyKeyProvided($params, 'RegisteredDate', 'registered_date');
        $registeredDate         = $this->bodyValue($params, 'RegisteredDate', 'registered_date');

        $userId = $this->actingUserId();
        if ($userId === null) {
            return $this->errorResponse('No acting user resolved', Http::STATUS_FORBIDDEN);
        }

        try {
            $entity = $this->documentService->update(
                $documentEntity->getId(),
                $title,
                $documentType,
                $status,
                $userId,
                $insightLevelId,
                $insightLevelProvided,
                $documentDate,
                $documentDateProvided,
                $receivedDate,
                $receivedDateProvided,
                $registeredDate,
                $registeredDateProvided,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), Http::STATUS_BAD_REQUEST);
        } catch (NotFoundException $e) {
            return $this->errorResponse($e->getMessage(), Http::STATUS_FORBIDDEN);
        }

        // Sync filecache permissions whenever the status is explicitly set,
        // matching the internal document-update endpoint's behaviour.
        if ($status !== null) {
            $statusFinals = $this->documentStatusMapper->getIsFinalMap();
            $isFinal      = $statusFinals[$status] ?? false;
            $this->fileService->updateFilecachePermissionsForDocument($documentEntity->getId(), !$isFinal);
        }

        $fields = $this->caseExportService->singleDocumentFields($entity);

        return $this->respond('Document', $fields);
    }

    /**
     * Add a note to a document.
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function addNote(): Response {
        $params = $this->requestBodyParams();

        $uuid           = $this->bodyValue($params, 'Uuid', 'uuid');
        $documentNumber = $this->bodyValue($params, 'DocumentNumber', 'document_number');

        try {
            $documentEntity = $this->resolveDocument($uuid, $documentNumber);
        } catch (PublicDocumentApiError $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatusCode());
        }

        $title = $this->bodyValue($params, 'Title', 'title');
        $text  = $this->bodyValue($params, 'Text', 'text');

        if (empty($title)) {
            return $this->errorResponse("Field 'Title' is required", Http::STATUS_BAD_REQUEST);
        }

        $userId = $this->actingUserId();
        if ($userId === null) {
            return $this->errorResponse('No acting user resolved', Http::STATUS_FORBIDDEN);
        }

        if (!$this->caseService->canWriteCase($documentEntity->getCaseId(), $userId)) {
            return $this->errorResponse('User does not have write access to this document\'s case', Http::STATUS_FORBIDDEN);
        }

        $note = new DocumentNote();
        $note->setDocumentId($documentEntity->getId());
        $note->setTitle($title);
        $note->setText($text);
        $note->setCreatedBy($userId);
        $now = new \DateTime();
        $note->setCreatedAt($now);
        $note->setUpdatedAt($now);

        $note = $this->documentNoteMapper->insert($note);

        $this->auditService->logDocumentNoteCreated(
            $documentEntity->getCaseId(),
            $documentEntity->getId(),
            $userId,
            $note->getId(),
            $title,
        );

        $fields = $this->caseExportService->documentNoteFieldsFromEntity($note);

        return $this->respond('Note', $fields, Http::STATUS_CREATED);
    }

    /**
     * Add one or more contacts to a document.
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function addContacts(): Response {
        ['fields' => $fields, 'items' => $contactsInput] = $this->requestBodyWithList('Contacts', 'Contact');

        $uuid           = $this->bodyValue($fields, 'Uuid', 'uuid');
        $documentNumber = $this->bodyValue($fields, 'DocumentNumber', 'document_number');

        try {
            $documentEntity = $this->resolveDocument($uuid, $documentNumber);
        } catch (PublicDocumentApiError $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatusCode());
        }

        $userId = $this->actingUserId();
        if ($userId === null) {
            return $this->errorResponse('No acting user resolved', Http::STATUS_FORBIDDEN);
        }

        if (!$this->caseService->canWriteCase($documentEntity->getCaseId(), $userId)) {
            return $this->errorResponse('User does not have write access to this document\'s case', Http::STATUS_FORBIDDEN);
        }

        if (empty($contactsInput)) {
            return $this->errorResponse('At least one contact is required', Http::STATUS_BAD_REQUEST);
        }

        $roleNames = $this->contactRoleMapper->getNameMap('da');
        if (empty($roleNames)) {
            $roleNames = $this->contactRoleMapper->getNameMap('en');
        }

        $results      = [];
        $anySucceeded = false;

        foreach ($contactsInput as $item) {
            $result = $this->addOneContact($documentEntity, $userId, $roleNames, $item);
            if (!isset($result['Error'])) {
                $anySucceeded = true;
            }
            $results[] = $result;
        }

        $status = $anySucceeded ? Http::STATUS_CREATED : Http::STATUS_BAD_REQUEST;

        return $this->respondList('Contacts', 'Contact', $results, $status);
    }

    /** @param array<string, string> $roleNames */
    private function addOneContact(Document $documentEntity, string $userId, array $roleNames, array $item): array {
        $roleIdRaw = $this->bodyValue($item, 'ContactRoleId', 'contactrole_id', 'contact_role_id');
        $cprCvrRaw = $this->bodyValue($item, 'CprCvr', 'cpr_cvr');

        if ($roleIdRaw === null || $cprCvrRaw === null) {
            return ['CprCvr' => $cprCvrRaw, 'Error' => "'contactrole_id' and 'cpr_cvr' are both required"];
        }

        $contactRoleId = (int)$roleIdRaw;
        $digits         = preg_replace('/\D/', '', $cprCvrRaw) ?? '';
        $documentId     = $documentEntity->getId();

        try {
            if (strlen($digits) === 10) {
                $lookup      = $this->cprCvrLookupService->lookupCitizen($digits);
                $type        = 'citizen';
                $contactType = ContactTypeMapper::CITIZEN;
            } elseif (strlen($digits) === 8) {
                $lookup      = $this->cprCvrLookupService->lookupCompany($digits);
                $type        = 'company';
                $contactType = ContactTypeMapper::COMPANY;
            } else {
                return ['CprCvr' => $cprCvrRaw, 'Error' => "'cpr_cvr' must be 10 digits (CPR) or 8 digits (CVR)"];
            }
        } catch (\Throwable $e) {
            return ['CprCvr' => $cprCvrRaw, 'Error' => $e->getMessage()];
        }

        if ($lookup === null) {
            return ['CprCvr' => $cprCvrRaw, 'Error' => "No {$type} found for {$digits}"];
        }

        if ($this->documentContactMapper->existsByDocumentRoleAndCvr($documentId, $contactRoleId, $digits, $lookup['pnumber'])) {
            return ['CprCvr' => $cprCvrRaw, 'Error' => 'A contact with this role and CPR/CVR already exists on this document'];
        }

        $entity = new DocumentContact();
        $entity->setDocumentId($documentId);
        $entity->setContactroleId($contactRoleId);
        $entity->setContactType($contactType);
        $entity->setCprCvr($digits);
        $entity->setName($lookup['name']);
        $entity->setStreetname($lookup['streetname']);
        $entity->setHousenumber($lookup['housenumber']);
        $entity->setFloor($lookup['floor']);
        $entity->setDoor($lookup['door']);
        $entity->setZipcode($lookup['zipcode']);
        $entity->setZipdistrict($lookup['zipdistrict']);
        $entity->setPhone($lookup['phone']);
        $entity->setEmail($lookup['email']);
        $entity->setHasAddressProtection($lookup['has_address_protection']);
        $entity->setPnumber($lookup['pnumber']);

        $saved = $this->documentContactMapper->insert($entity);

        $this->auditService->logContactAdded(
            $documentEntity->getCaseId(),
            $documentId,
            $userId,
            $roleNames[$contactRoleId] ?? '',
            $saved->getName() ?? '',
            $saved->getCprCvr() ?? '',
        );

        return [
            'Id'                   => $saved->getId(),
            'RoleId'               => $saved->getContactroleId(),
            'RoleName'             => $roleNames[$contactRoleId] ?? '',
            'ContactType'          => $saved->getContactType(),
            'CprCvr'               => $saved->getCprCvr(),
            'Name'                 => $saved->getName(),
            'Streetname'           => $saved->getStreetname(),
            'Housenumber'          => $saved->getHousenumber(),
            'Floor'                => $saved->getFloor(),
            'Door'                 => $saved->getDoor(),
            'Zipcode'              => $saved->getZipcode(),
            'Zipdistrict'          => $saved->getZipdistrict(),
            'Phone'                => $saved->getPhone(),
            'Email'                => $saved->getEmail(),
            'HasAddressProtection' => $saved->getHasAddressProtection() ? 'true' : 'false',
            'Pnumber'              => $saved->getPnumber(),
        ];
    }

    /**
     * Upload a file to a document. Content is base64-encoded in the request
     * body (there's no multipart/form-data upload on this JSON/XML API).
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function uploadFile(): Response {
        $params = $this->requestBodyParams();

        $uuid           = $this->bodyValue($params, 'Uuid', 'uuid');
        $documentNumber = $this->bodyValue($params, 'DocumentNumber', 'document_number');

        try {
            $documentEntity = $this->resolveDocument($uuid, $documentNumber);
        } catch (PublicDocumentApiError $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatusCode());
        }

        $fileName   = $this->bodyValue($params, 'FileName', 'file_name');
        $contentB64 = $this->bodyValue($params, 'Content', 'content');
        $mimeType   = $this->bodyValue($params, 'MimeType', 'mime_type') ?? 'application/octet-stream';

        if (empty($fileName)) {
            return $this->errorResponse("Field 'FileName' is required", Http::STATUS_BAD_REQUEST);
        }
        if (empty($contentB64)) {
            return $this->errorResponse("Field 'Content' is required", Http::STATUS_BAD_REQUEST);
        }

        $binary = base64_decode($contentB64, true);
        if ($binary === false) {
            return $this->errorResponse("Field 'Content' is not valid base64", Http::STATUS_BAD_REQUEST);
        }

        $userId = $this->actingUserId();
        if ($userId === null) {
            return $this->errorResponse('No acting user resolved', Http::STATUS_FORBIDDEN);
        }

        try {
            $fileEntity = $this->fileService->upload(
                $documentEntity->getId(),
                $fileName,
                $mimeType,
                $binary,
                $userId,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), Http::STATUS_BAD_REQUEST);
        } catch (NotFoundException $e) {
            return $this->errorResponse($e->getMessage(), Http::STATUS_FORBIDDEN);
        }

        $fields = $this->caseExportService->fileFieldsFromEntity($fileEntity, $documentEntity->getId());

        return $this->respond('File', $fields, Http::STATUS_CREATED);
    }

    /**
     * Create a file on a document by merging a template (see GET .../templates)
     * with case/document metadata (e.g. {{case.number}}, {{sag.titel}}).
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function createFileFromTemplate(): Response {
        $params = $this->requestBodyParams();

        $uuid           = $this->bodyValue($params, 'Uuid', 'uuid');
        $documentNumber = $this->bodyValue($params, 'DocumentNumber', 'document_number');

        try {
            $documentEntity = $this->resolveDocument($uuid, $documentNumber);
        } catch (PublicDocumentApiError $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatusCode());
        }

        $templateIdRaw = $this->bodyValue($params, 'TemplateId', 'template_id');
        if (empty($templateIdRaw)) {
            return $this->errorResponse("Field 'TemplateId' is required", Http::STATUS_BAD_REQUEST);
        }
        $templateId = (int)$templateIdRaw;

        $userId = $this->actingUserId();
        if ($userId === null) {
            return $this->errorResponse('No acting user resolved', Http::STATUS_FORBIDDEN);
        }

        try {
            $template = $this->templateService->get($templateId);
        } catch (NotFoundException) {
            return $this->errorResponse('Template not found', Http::STATUS_NOT_FOUND);
        }

        $caseEntity = $this->caseMapper->findById($documentEntity->getCaseId());
        if ($caseEntity === null) {
            return $this->errorResponse('Case not found', Http::STATUS_NOT_FOUND);
        }

        $tempPath  = null;
        $localCopy = null;
        $stream    = null;
        try {
            $values    = $this->templateMergeService->buildValues($caseEntity, $documentEntity);
            $localCopy = $this->templateService->makeLocalCopy($templateId);
            $tempPath  = $this->templateMergeService->mergeToTempFile(
                $localCopy['path'],
                $template->getMimeType(),
                $values,
            );

            $stream = fopen($tempPath, 'rb');
            if ($stream === false) {
                throw new \RuntimeException("Failed to open merged temp file: {$tempPath}");
            }

            $fileEntity = $this->fileService->upload(
                $documentEntity->getId(),
                $template->getOriginalFilename(),
                $template->getMimeType(),
                $stream,
                $userId,
            );
        } catch (NotFoundException $e) {
            return $this->errorResponse($e->getMessage(), Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            return $this->errorResponse('Failed to create file from template', Http::STATUS_INTERNAL_SERVER_ERROR);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if ($tempPath !== null && file_exists($tempPath)) {
                @unlink($tempPath);
            }
            if ($localCopy !== null) {
                $this->templateService->releaseLocalCopy($localCopy);
            }
        }

        $fields = $this->caseExportService->fileFieldsFromEntity($fileEntity, $documentEntity->getId());

        return $this->respond('File', $fields, Http::STATUS_CREATED);
    }

    /** @throws PublicDocumentApiError */
    private function resolveDocument(?string $uuid, ?string $documentNumber): Document {
        if (empty($uuid) && empty($documentNumber)) {
            throw new PublicDocumentApiError('uuid or document_number is required', Http::STATUS_BAD_REQUEST);
        }

        $userId = $this->actingUserId();
        if ($userId === null) {
            throw new PublicDocumentApiError('No acting user resolved', Http::STATUS_FORBIDDEN);
        }

        try {
            return !empty($uuid)
                ? $this->documentService->getByUuid($uuid, $userId)
                : $this->documentService->getByDocumentNumber($documentNumber, $userId);
        } catch (NotFoundException $e) {
            throw new PublicDocumentApiError('Document not found', Http::STATUS_NOT_FOUND);
        }
    }
}
