<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller\PublicApi;

use OCA\OpenCase\Db\FileMapper;
use OCA\OpenCase\Db\FileVersionEntity;
use OCA\OpenCase\Db\FileVersionMapper;
use OCA\OpenCase\Service\CaseExportService;
use OCA\OpenCase\Service\FileService;
use OCA\OpenCase\Service\NotFoundException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Public data REST API for files.
 *
 * GET  /index.php/apps/opencase/public/v1/api/files?uuid=...
 *   — a single file's metadata (FileType) plus its content, base64-encoded
 *     in the Content field
 * POST /index.php/apps/opencase/public/v1/api/files/version
 *   — upload a new version of an existing file. Body:
 *       Uuid    (required) — the file's uuid
 *       Content (required) — base64-encoded new content
 *     Keeps the existing filename/MIME type; increments Version. Returns
 *     the updated file as FileType.
 * GET  /index.php/apps/opencase/public/v1/api/files/versions?uuid=...
 *   — list historical (superseded) versions of a file, newest first:
 *     FileVersions { FileVersion: [{Uuid, Timestamp, Size, MimeType,
 *     Checksum, CreatedBy}, ...] }. The current/live content is not
 *     included here — retrieve it via GET .../files?uuid=....
 * GET  /index.php/apps/opencase/public/v1/api/files/versions/content?uuid=...
 *   — a single historical version's metadata plus its content,
 *     base64-encoded in the Content field: FileVersion
 *
 * See AbstractPublicDataApiController for auth and response-format handling.
 */
class PublicFileApiController extends AbstractPublicDataApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private FileMapper $fileMapper,
        private FileVersionMapper $fileVersionMapper,
        private FileService $fileService,
        private CaseExportService $caseExportService,
        IUserSession $userSession,
    ) {
        parent::__construct($appName, $request, $userSession);
    }

    /**
     * Retrieve a single file (metadata + base64 content) by uuid.
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(?string $uuid = null): Response {
        if (empty($uuid)) {
            return $this->errorResponse('uuid is required', Http::STATUS_BAD_REQUEST);
        }

        $userId = $this->actingUserId();
        if ($userId === null) {
            return $this->errorResponse('No acting user resolved', Http::STATUS_FORBIDDEN);
        }

        $entity = $this->fileMapper->findByUuid($uuid);
        if ($entity === null) {
            return $this->errorResponse('File not found', Http::STATUS_NOT_FOUND);
        }

        try {
            $result = $this->fileService->download($entity->getId(), $userId);
        } catch (NotFoundException $e) {
            return $this->errorResponse('File not found', Http::STATUS_NOT_FOUND);
        }

        $stream  = $result['stream'];
        $content = stream_get_contents($stream);
        fclose($stream);

        if ($content === false) {
            return $this->errorResponse('Failed to read file content', Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $fields = $this->caseExportService->fileFieldsFromEntity($entity, $entity->getDocumentId());
        $fields['Content'] = base64_encode($content);

        return $this->respond('File', $fields);
    }

    /**
     * Upload a new version of an existing file, identified by uuid.
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function uploadVersion(): Response {
        $params = $this->requestBodyParams();

        $uuid = $this->bodyValue($params, 'Uuid', 'uuid');
        if (empty($uuid)) {
            return $this->errorResponse("Field 'Uuid' is required", Http::STATUS_BAD_REQUEST);
        }

        $contentB64 = $this->bodyValue($params, 'Content', 'content');
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

        $entity = $this->fileMapper->findByUuid($uuid);
        if ($entity === null) {
            return $this->errorResponse('File not found', Http::STATUS_NOT_FOUND);
        }

        try {
            $updated = $this->fileService->uploadNewVersion($entity->getId(), $binary, $userId);
        } catch (NotFoundException $e) {
            return $this->errorResponse($e->getMessage(), Http::STATUS_FORBIDDEN);
        }

        $fields = $this->caseExportService->fileFieldsFromEntity($updated, $updated->getDocumentId());

        return $this->respond('File', $fields);
    }

    /**
     * List historical (superseded) versions of a file, identified by its uuid.
     * The current/live content is not included — retrieve it via show().
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function versions(?string $uuid = null): Response {
        if (empty($uuid)) {
            return $this->errorResponse('uuid is required', Http::STATUS_BAD_REQUEST);
        }

        $userId = $this->actingUserId();
        if ($userId === null) {
            return $this->errorResponse('No acting user resolved', Http::STATUS_FORBIDDEN);
        }

        $entity = $this->fileMapper->findByUuid($uuid);
        if ($entity === null) {
            return $this->errorResponse('File not found', Http::STATUS_NOT_FOUND);
        }

        try {
            $versions = $this->fileService->listVersions($entity->getId(), $userId);
        } catch (NotFoundException $e) {
            return $this->errorResponse('File not found', Http::STATUS_NOT_FOUND);
        }

        $items = array_map(fn (FileVersionEntity $v) => $this->versionFields($v), $versions);

        return $this->respondList('FileVersions', 'FileVersion', $items);
    }

    /**
     * Retrieve a single historical version's metadata plus its content
     * (base64-encoded), identified by the fileversion's uuid.
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function versionContent(?string $uuid = null): Response {
        if (empty($uuid)) {
            return $this->errorResponse('uuid is required', Http::STATUS_BAD_REQUEST);
        }

        $userId = $this->actingUserId();
        if ($userId === null) {
            return $this->errorResponse('No acting user resolved', Http::STATUS_FORBIDDEN);
        }

        $version = $this->fileVersionMapper->findByUuid($uuid);
        if ($version === null) {
            return $this->errorResponse('File version not found', Http::STATUS_NOT_FOUND);
        }

        try {
            $result = $this->fileService->downloadVersion($version->getFileId(), $version->getId(), $userId);
        } catch (NotFoundException $e) {
            return $this->errorResponse('File version not found', Http::STATUS_NOT_FOUND);
        }

        $stream  = $result['stream'];
        $content = stream_get_contents($stream);
        fclose($stream);

        if ($content === false) {
            return $this->errorResponse('Failed to read version content', Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $fields             = $this->versionFields($result['version']);
        $fields['Content']  = base64_encode($content);

        return $this->respond('FileVersion', $fields);
    }

    /** @return array<string, string|null> */
    private function versionFields(FileVersionEntity $version): array {
        return [
            'Uuid'      => $version->getUuid(),
            'Timestamp' => (string)$version->getTimestamp(),
            'Size'      => (string)$version->getSize(),
            'MimeType'  => $version->getMimeType(),
            'Checksum'  => $version->getChecksum(),
            'CreatedBy' => $version->getCreatedBy(),
            'CreatedAt' => $version->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
