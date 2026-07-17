<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Service\AuditService;
use OCA\OpenCase\Service\FileService;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\TemplateMergeService;
use OCA\OpenCase\Service\TemplateService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * REST API controller for file management.
 *
 * Handles upload and download of physical files attached to documents.
 *
 * Routes:
 *   GET    /api/v1/documents/{docId}/files    — List files in a document
 *   POST   /api/v1/documents/{docId}/files    — Upload a file to a document
 *   GET    /api/v1/cases/{caseId}/files        — List all files in a case
 *   GET    /api/v1/files/{id}                  — Get file metadata
 *   GET    /api/v1/files/{id}/download         — Download file content
 *   POST   /api/v1/files/{id}/version          — Upload new version
 *   DELETE /api/v1/files/{id}                  — Delete a file
 */
class FileController extends ApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private FileService $fileService,
        private TemplateService $templateService,
        private TemplateMergeService $templateMergeService,
        private AuditService $auditService,
        private DocumentMapper $documentMapper,
        private CaseMapper $caseMapper,
        private LoggerInterface $logger,
        private ?string $userId,
        private IURLGenerator $urlGenerator,
        private IUserManager $userManager,
    ) {
        parent::__construct($appName, $request, corsAllowedHeaders: 'Authorization, Content-Type, Accept, OCS-APIREQUEST');
    }

    /**
     * List all files in a document.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/documents/{docId}/files')]
    public function indexByDocument(int $docId): DataResponse {
        try {
            $files          = $this->fileService->listByDocument($docId, $this->userId);
            $document       = $this->documentMapper->findById($docId);
            $documentNumber = $document?->getDocumentNumber();
            return new DataResponse([
                'files' => array_map(fn($f) => $this->serializeFile($f, $documentNumber), $files),
            ]);
        } catch (NotFoundException $e) {
            return new DataResponse(
                ['error' => 'Document not found or access denied'],
                Http::STATUS_NOT_FOUND
            );
        }
    }

    /**
     * List all files in a case (across all documents).
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/cases/{caseId}/files')]
    public function indexByCase(int $caseId): DataResponse {
        try {
            $files = $this->fileService->listByCase($caseId, $this->userId);

            // Build documentId → documentNumber map in one query
            $documents     = $this->documentMapper->findByCase($caseId);
            $documentNumbers = [];
            foreach ($documents as $doc) {
                $documentNumbers[$doc->getId()] = $doc->getDocumentNumber();
            }

            return new DataResponse([
                'files' => array_map(
                    fn($f) => $this->serializeFile($f, $documentNumbers[$f->getDocumentId()] ?? null),
                    $files
                ),
            ]);
        } catch (NotFoundException $e) {
            return new DataResponse(
                ['error' => 'Case not found or access denied'],
                Http::STATUS_NOT_FOUND
            );
        }
    }

    /**
     * Copy all files from one document to another document.
     *
     * Request body: { "target_document_id": int }
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/documents/{docId}/copy-files')]
    public function copyFiles(int $docId): DataResponse {
        $params          = $this->request->getParams();
        $targetDocId     = isset($params['target_document_id']) ? (int)$params['target_document_id'] : null;

        if ($targetDocId === null) {
            return new DataResponse(['error' => "Field 'target_document_id' is required"], Http::STATUS_BAD_REQUEST);
        }

        try {
            $created = $this->fileService->copyFilesToDocument($docId, $targetDocId, $this->userId);
            $targetDoc      = $this->documentMapper->findById($targetDocId);
            $documentNumber = $targetDoc?->getDocumentNumber();
            return new DataResponse([
                'files' => array_map(fn($f) => $this->serializeFile($f, $documentNumber), $created),
            ], Http::STATUS_CREATED);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'File copy failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Upload a file to a document.
     *
     * Expects a multipart/form-data request with:
     *   file — The uploaded file
     *
     * The file's original name and MIME type are read from the upload.
     */
    #[NoAdminRequired]
    #[CORS]
    #[ApiRoute(verb: 'POST', url: '/api/v1/documents/{docId}/files')]
    public function upload(int $docId): DataResponse {
        $file = $this->request->getUploadedFile('file');

        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            $errorMsg = match ($errorCode) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds maximum upload size',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error: missing temp directory',
                default => 'File upload failed',
            };
            return new DataResponse(
                ['error' => $errorMsg],
                Http::STATUS_BAD_REQUEST
            );
        }

        $stream = fopen($file['tmp_name'], 'rb');
        if ($stream === false) {
            return new DataResponse(
                ['error' => 'Failed to read uploaded file'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        try {
            $entity = $this->fileService->upload(
                $docId,
                $file['name'],
                $file['type'] ?: 'application/octet-stream',
                $stream,
                $this->userId,
            );

            $document       = $this->documentMapper->findById($docId);
            $documentNumber = $document?->getDocumentNumber();
            return new DataResponse(
                ['file' => $this->serializeFile($entity, $documentNumber)],
                Http::STATUS_CREATED
            );
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        } catch (NotFoundException $e) {
            return new DataResponse(
                ['error' => 'Document not found or access denied'],
                Http::STATUS_NOT_FOUND
            );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Create a new file in a document by merging a template with case/document metadata.
     *
     * Request body (JSON or form):
     *   template_id — ID of the template to use
     *
     * Placeholders in the template file (e.g. {{case.number}}, {{sag.titel}})
     * are replaced with live metadata from the parent case and document before
     * the file is created. Supported formats: .docx, .xlsx, .odt, .ods.
     *
     * Returns the newly created file entity. The frontend can then call
     * getEditUrl to open it for editing in Nextcloud Office.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/documents/{docId}/files/from-template')]
    public function createFromTemplate(int $docId): DataResponse {
        $templateId = (int) $this->request->getParam('template_id', 0);
        if ($templateId <= 0) {
            return new DataResponse(['error' => 'template_id is required'], Http::STATUS_BAD_REQUEST);
        }

        // Load template metadata
        try {
            $template = $this->templateService->get($templateId);
        } catch (NotFoundException) {
            return new DataResponse(['error' => 'Template not found'], Http::STATUS_NOT_FOUND);
        }

        // Load document and case for placeholder resolution
        $document = $this->documentMapper->findById($docId);
        if ($document === null) {
            return new DataResponse(['error' => 'Document not found'], Http::STATUS_NOT_FOUND);
        }

        $case = $this->caseMapper->findById($document->getCaseId());
        if ($case === null) {
            return new DataResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
        }

        $tempPath  = null;
        $localCopy = null;
        $stream    = null;
        try {
            // Build placeholder values and merge into a temp file.
            // makeLocalCopy() handles both regular and NC-backed templates.
            $values    = $this->templateMergeService->buildValues($case, $document);
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

            $entity = $this->fileService->upload(
                $docId,
                $template->getOriginalFilename(),
                $template->getMimeType(),
                $stream,
                $this->userId ?? '',
            );

            return new DataResponse(
                ['file' => $this->serializeFile($entity, $document->getDocumentNumber())],
                Http::STATUS_CREATED,
            );
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('OpenCase: createFromTemplate failed: {msg}', ['msg' => $e->getMessage()]);
            return new DataResponse(['error' => 'Failed to create file from template'], Http::STATUS_INTERNAL_SERVER_ERROR);
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
    }

    /**
     * Get file metadata.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/files/{id}')]
    public function show(int $id): DataResponse {
        try {
            $entity         = $this->fileService->get($id, $this->userId);
            $document       = $this->documentMapper->findById($entity->getDocumentId());
            $documentNumber = $document?->getDocumentNumber();
            return new DataResponse(['file' => $this->serializeFile($entity, $documentNumber)]);
        } catch (NotFoundException $e) {
            return new DataResponse(
                ['error' => 'File not found'],
                Http::STATUS_NOT_FOUND
            );
        }
    }

    /**
     * Download file content.
     *
     * Returns the raw file content with appropriate Content-Type
     * and Content-Disposition headers.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/files/{id}/download')]
    public function download(int $id): DataDownloadResponse|DataResponse {
        $this->logger->debug('OpenCase download: entered, id={id}, userId={userId}', [
            'id' => $id,
            'userId' => $this->userId ?? 'NULL',
        ]);

        try {
            $result = $this->fileService->download($id, $this->userId);
            $entity = $result['entity'];
            $stream = $result['stream'];

            $this->logger->debug('OpenCase download: file found, filename={filename}, size={size}', [
                'filename' => $entity->getOriginalFilename(),
                'size' => $entity->getSize(),
            ]);

            $content = stream_get_contents($stream);
            fclose($stream);

            $this->logger->debug('OpenCase download: content read, bytes={bytes}', [
                'bytes' => strlen($content),
            ]);

            $this->auditService->logFileDownloaded(
                $entity->getCaseId(),
                $entity->getDocumentId(),
                $entity->getId(),
                $this->userId ?? '',
                $entity->getOriginalFilename(),
            );

            return new DataDownloadResponse(
                $content,
                $entity->getOriginalFilename(),
                $entity->getMimeType()
            );
        } catch (NotFoundException $e) {
            $this->logger->warning('OpenCase download: NotFoundException for id={id}, userId={userId}: {msg}', [
                'id' => $id,
                'userId' => $this->userId ?? 'NULL',
                'msg' => $e->getMessage(),
            ]);
            return new DataResponse(
                ['error' => 'File not found'],
                Http::STATUS_NOT_FOUND
            );
        } catch (\Throwable $e) {
            $this->logger->error('OpenCase download: unexpected error for id={id}: {msg}', [
                'id' => $id,
                'msg' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get the Nextcloud file ID needed to open this file in Nextcloud Office.
     *
     * Returns the internal NC file ID so the frontend can navigate to
     * /f/{id}, which Nextcloud routes to the appropriate editor (Collabora /
     * OnlyOffice) based on MIME type.
     */
    /**
     * Serve a file inline in the browser (Content-Disposition: inline).
     * Used for non-Office/non-text files such as HTML saved from Talk.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/files/{id}/view')]
    public function view(int $id): DataDisplayResponse|DataResponse {
        try {
            $result  = $this->fileService->download($id, $this->userId);
            $entity  = $result['entity'];
            $stream  = $result['stream'];
            $content = stream_get_contents($stream);
            fclose($stream);

            $response = new DataDisplayResponse($content, Http::STATUS_OK);
            $response->addHeader('Content-Type', $entity->getMimeType());
            $response->addHeader('Content-Disposition', 'inline; filename="' . rawurlencode($entity->getOriginalFilename()) . '"');

            // Allow the saved HTML's own <style> blocks and style attributes to apply —
            // Nextcloud's default CSP otherwise strips inline styles.
            $csp = new ContentSecurityPolicy();
            $csp->allowInlineStyle(true);
            $response->setContentSecurityPolicy($csp);

            return $response;
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/files/{id}/edit-url')]
    public function getEditUrl(int $id, string $action = 'view'): DataResponse {
        try {
            $entity   = $this->fileService->get($id, $this->userId);
            $ncFileId = $this->fileService->getNextcloudFileId($id, $this->userId);

            if ($action === 'edit') {
                $this->auditService->logFileEdited(
                    $entity->getCaseId(),
                    $entity->getDocumentId(),
                    $entity->getId(),
                    $this->userId ?? '',
                    $entity->getOriginalFilename(),
                );
            } else {
                $this->auditService->logFileViewed(
                    $entity->getCaseId(),
                    $entity->getDocumentId(),
                    $entity->getId(),
                    $this->userId ?? '',
                    $entity->getOriginalFilename(),
                );
            }

            return new DataResponse(['nc_file_id' => $ncFileId]);
        } catch (NotFoundException $e) {
            $this->logger->warning('OpenCase getEditUrl: {msg}', ['msg' => $e->getMessage()]);
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Upload a new version of an existing file.
     *
     * Replaces the file content while keeping the same file ID
     * and virtual filename. Increments the version counter.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/files/{id}/version')]
    public function uploadNewVersion(int $id): DataResponse {
        $file = $this->request->getUploadedFile('file');

        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            return new DataResponse(
                ['error' => 'No file uploaded or upload error'],
                Http::STATUS_BAD_REQUEST
            );
        }

        $stream = fopen($file['tmp_name'], 'rb');
        if ($stream === false) {
            return new DataResponse(
                ['error' => 'Failed to read uploaded file'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        try {
            $entity         = $this->fileService->uploadNewVersion($id, $stream, $this->userId);
            $document       = $this->documentMapper->findById($entity->getDocumentId());
            $documentNumber = $document?->getDocumentNumber();
            return new DataResponse(['file' => $this->serializeFile($entity, $documentNumber)]);
        } catch (NotFoundException $e) {
            return new DataResponse(
                ['error' => 'File not found or access denied'],
                Http::STATUS_NOT_FOUND
            );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * List all stored versions of a file, newest first.
     *
     * The current (live) file is always returned as the first entry with
     * is_current=true so the UI can show the full version timeline in one
     * request.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/files/{id}/versions')]
    public function versions(int $id): DataResponse {
        try {
            $file      = $this->fileService->get($id, $this->userId);
            $versions  = $this->fileService->listVersions($id, $this->userId);
            $lastMtime = $this->fileService->getPhysicalMtime($file);

            $current = [
                'id'         => null,
                'file_id'    => $file->getId(),
                'timestamp'  => $lastMtime->getTimestamp(),
                'size'       => $file->getSize(),
                'mime_type'  => $file->getMimeType(),
                'checksum'   => $file->getChecksum(),
                'created_by' => $this->resolveDisplayName($file->getLastModifiedBy() ?: $file->getCreatedBy()),
                'created_at' => $lastMtime->format('c'),
                'is_current' => true,
            ];

            $history = array_map(
                fn($v) => array_merge($this->serializeVersion($v), ['is_current' => false]),
                $versions
            );

            return new DataResponse(['versions' => [$current, ...$history]]);
        } catch (NotFoundException $e) {
            return new DataResponse(
                ['error' => 'File not found or access denied'],
                Http::STATUS_NOT_FOUND
            );
        }
    }

    /**
     * Download a specific historical version of a file.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/files/{id}/versions/{versionId}/download')]
    public function downloadVersion(int $id, int $versionId): DataDownloadResponse|DataResponse {
        try {
            $result  = $this->fileService->downloadVersion($id, $versionId, $this->userId ?? '');
            $entity  = $result['entity'];
            $version = $result['version'];
            $stream  = $result['stream'];

            $content = stream_get_contents($stream);
            fclose($stream);

            $this->auditService->logFileVersionDownloaded(
                $entity->getCaseId(),
                $entity->getDocumentId(),
                $entity->getId(),
                $this->userId ?? '',
                $entity->getOriginalFilename(),
                $version->getTimestamp(),
            );

            return new DataDownloadResponse(
                $content,
                $entity->getOriginalFilename(),
                $version->getMimeType() ?: $entity->getMimeType(),
            );
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Copy a historical version to the user's NC home and return its NC file ID
     * so the frontend can open it read-only in Nextcloud Office.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/files/{id}/versions/{versionId}/edit-url')]
    public function getVersionEditUrl(int $id, int $versionId): DataResponse {
        try {
            $result   = $this->fileService->openVersionForView($id, $versionId, $this->userId ?? '');
            $entity   = $result['entity'];
            $version  = $result['version'];
            $ncFileId = $result['nc_file_id'];

            $this->auditService->logFileVersionViewed(
                $entity->getCaseId(),
                $entity->getDocumentId(),
                $entity->getId(),
                $this->userId ?? '',
                $entity->getOriginalFilename(),
                $version->getTimestamp(),
            );

            return new DataResponse(['nc_file_id' => $ncFileId]);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Restore a historical version as the current version of the file.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/files/{id}/versions/{versionId}/restore')]
    public function restoreVersion(int $id, int $versionId): DataResponse {
        try {
            $result   = $this->fileService->restoreVersion($id, $versionId, $this->userId ?? '');
            $entity   = $result['entity'];
            $version  = $result['version'];

            $this->auditService->logFileVersionRestored(
                $entity->getCaseId(),
                $entity->getDocumentId(),
                $entity->getId(),
                $this->userId ?? '',
                $entity->getOriginalFilename(),
                $version->getTimestamp(),
            );

            $document       = $this->documentMapper->findById($entity->getDocumentId());
            $documentNumber = $document?->getDocumentNumber();
            return new DataResponse(['file' => $this->serializeFile($entity, $documentNumber)]);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Delete a file.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/files/{id}')]
    public function destroy(int $id): DataResponse {
        try {
            $this->fileService->delete($id, $this->userId);
            return new DataResponse(['deleted' => true]);
        } catch (NotFoundException $e) {
            return new DataResponse(
                ['error' => 'File not found or access denied'],
                Http::STATUS_NOT_FOUND
            );
        }
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function serializeFile($entity, ?string $documentNumber = null): array {
        return [
            'id'                => $entity->getId(),
            'document_id'       => $entity->getDocumentId(),
            'document_number'   => $documentNumber,
            'case_id'           => $entity->getCaseId(),
            'original_filename' => $entity->getOriginalFilename(),
            'virtual_filename'  => $entity->getVirtualFilename(),
            'mime_type'         => $entity->getMimeType(),
            'size'              => $entity->getSize(),
            'version'           => $entity->getVersion(),
            'checksum'          => $entity->getChecksum(),
            'created_at'        => $entity->getCreatedAt()?->format('c'),
            'updated_at'        => $entity->getUpdatedAt()?->format('c'),
            'created_by'        => $entity->getCreatedBy(),
            'download_url'      => $this->buildDownloadUrl($entity->getId()),
        ];
    }

    private function buildDownloadUrl(int $fileId): string {
        return $this->urlGenerator->linkToRouteAbsolute(
            'ocs.opencase.file.download',
            ['id' => $fileId]
        );
    }

    private function serializeVersion($entity): array {
        return [
            'id'         => $entity->getId(),
            'file_id'    => $entity->getFileId(),
            'timestamp'  => $entity->getTimestamp(),
            'size'       => $entity->getSize(),
            'mime_type'  => $entity->getMimeType(),
            'checksum'   => $entity->getChecksum(),
            'created_by' => $this->resolveDisplayName($entity->getCreatedBy()),
            'created_at' => $entity->getCreatedAt()?->format('c'),
        ];
    }

    private function resolveDisplayName(?string $userId): ?string {
        if ($userId === null || $userId === '') {
            return $userId;
        }
        $user = $this->userManager->get($userId);
        return $user ? $user->getDisplayName() : $userId;
    }

    private function sanitiseFilename(string $filename): string {
        return str_replace(['"', '\\'], ['_', '_'], $filename);
    }
}
