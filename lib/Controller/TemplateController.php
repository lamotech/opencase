<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\RoleService;
use OCA\OpenCase\Service\TemplateService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * REST API controller for document template management.
 *
 * Access control:
 *   - GET  /api/v1/templates          — any OpenCase user
 *   - POST /api/v1/templates          — Administrator or Template Designer
 *   - DELETE /api/v1/templates/{id}   — Administrator or Template Designer
 *   - GET  /api/v1/templates/{id}/download — any OpenCase user
 */
class TemplateController extends OCSController {

    public function __construct(
        string $appName,
        IRequest $request,
        private TemplateService $templateService,
        private RoleService $roleService,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * List all templates.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/templates')]
    public function index(): DataResponse {
        $templates = $this->templateService->list();
        return new DataResponse([
            'templates' => array_map([$this, 'serialize'], $templates),
        ]);
    }

    /**
     * Upload a new template.
     *
     * Requires Administrator or Template Designer role.
     * Expects multipart/form-data with:
     *   file — the template file
     *   name — (optional) display name; defaults to original filename
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/templates')]
    public function upload(): DataResponse {
        if (!$this->canManageTemplates()) {
            return new DataResponse(['error' => 'Not permitted'], Http::STATUS_FORBIDDEN);
        }

        $file = $this->request->getUploadedFile('file');

        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            $errorMsg = match ($errorCode) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds maximum upload size',
                UPLOAD_ERR_PARTIAL  => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE  => 'No file was uploaded',
                default             => 'File upload failed',
            };
            return new DataResponse(['error' => $errorMsg], Http::STATUS_BAD_REQUEST);
        }

        $name = trim((string) $this->request->getParam('name', ''));
        if ($name === '') {
            $name = $file['name'];
        }

        $stream = fopen($file['tmp_name'], 'rb');
        if ($stream === false) {
            return new DataResponse(['error' => 'Failed to read uploaded file'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        try {
            $entity = $this->templateService->upload(
                $name,
                $file['name'],
                $file['type'] ?: 'application/octet-stream',
                $stream,
                $this->userId ?? '',
            );
            return new DataResponse(['template' => $this->serialize($entity)], Http::STATUS_CREATED);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Delete a template.
     *
     * Requires Administrator or Template Designer role.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/templates/{id}')]
    public function destroy(int $id): DataResponse {
        if (!$this->canManageTemplates()) {
            return new DataResponse(['error' => 'Not permitted'], Http::STATUS_FORBIDDEN);
        }

        try {
            $this->templateService->delete($id);
            return new DataResponse(['deleted' => true]);
        } catch (NotFoundException) {
            return new DataResponse(['error' => 'Template not found'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Download a template file.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/templates/{id}/download')]
    public function download(int $id): DataDownloadResponse|DataResponse {
        try {
            $entity  = $this->templateService->get($id);
            $stream  = $this->templateService->openStream($id);
            $content = stream_get_contents($stream);
            fclose($stream);

            return new DataDownloadResponse(
                $content,
                $entity->getOriginalFilename(),
                $entity->getMimeType(),
            );
        } catch (NotFoundException) {
            return new DataResponse(['error' => 'Template not found'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Create a blank .odt template, register it in the database, and open it
     * for editing in Nextcloud Office.
     *
     * The blank file is written to both _templates/ storage (so download and
     * merge keep working) and to the user's Nextcloud home at
     * OpenCase/Skabeloner/<name>.odt (so it can be opened in NC Office).
     *
     * Returns the full template object plus nc_file_id so the frontend can
     * add it to the list and immediately open it for editing.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/templates/new-blank')]
    public function createBlank(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $name = trim((string) $this->request->getParam('name', ''));
        if ($name === '') {
            $name = 'Ny skabelon';
        }

        try {
            $entity = $this->templateService->createBlank($name, $this->userId);
            return new DataResponse(
                ['template' => $this->serialize($entity)],
                Http::STATUS_CREATED,
            );
        } catch (\Throwable $e) {
            return new DataResponse(
                ['error' => 'Failed to create blank template: ' . $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }

    /**
     * Ensure a live editable copy of the template exists in the user's
     * Nextcloud home and return its NC file ID.
     *
     * Works for both blank-created and uploaded templates.
     * The frontend can navigate to /f/{nc_file_id} to open it in NC Office.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/templates/{id}/open-for-edit')]
    public function openForEdit(int $id): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $ncFileId = $this->templateService->openForEdit($id, $this->userId);
            return new DataResponse(['nc_file_id' => $ncFileId]);
        } catch (NotFoundException) {
            return new DataResponse(['error' => 'Template not found'], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return new DataResponse(
                ['error' => 'Failed to open template for edit: ' . $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function canManageTemplates(): bool {
        if ($this->userId === null) {
            return false;
        }
        return $this->roleService->userHasRole($this->userId, 'Administrator')
            || $this->roleService->userHasRole($this->userId, 'Template Designer');
    }

    private function serialize(object $entity): array {
        return [
            'id'                => $entity->getId(),
            'name'              => $entity->getName(),
            'original_filename' => $entity->getOriginalFilename(),
            'mime_type'         => $entity->getMimeType(),
            'size'              => $entity->getSize(),
            'uploaded_by'       => $entity->getUploadedBy(),
            'created_at'        => $entity->getCreatedAt()?->format('c'),
            'nc_file_id'        => $entity->getNcFileId(),
        ];
    }

}
