<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\Phrase;
use OCA\OpenCase\Db\PhraseScope;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\PhraseService;
use OCA\OpenCase\Service\RoleService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IL10N;

/**
 * REST API for the Brevdesigner phrase library.
 *
 * Access control (like TemplateController):
 *   - GET    /api/v1/phrases                     — any OpenCase user
 *   - GET    /api/v1/phrases/{id}/download       — any OpenCase user
 *   - GET    /api/v1/phrases/{id}/templates      — any OpenCase user
 *   - POST   /api/v1/phrases                     — Administrator or Template Designer
 *   - PUT    /api/v1/phrases/{id}                — Administrator or Template Designer
 *   - DELETE /api/v1/phrases/{id}                — Administrator or Template Designer
 *   - POST   /api/v1/phrases/{id}/file           — Administrator or Template Designer
 *   - POST   /api/v1/phrases/{id}/new-blank      — Administrator or Template Designer
 *   - POST   /api/v1/phrases/{id}/open-for-edit  — Administrator or Template Designer
 */
class PhraseController extends OCSController {

    public function __construct(
        string $appName,
        IRequest $request,
        private PhraseService $phraseService,
        private RoleService $roleService,
        private IL10N $l10n,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/phrases')]
    public function index(): DataResponse {
        if ($denied = $this->checkCanManage()) {
            return $denied;
        }
        return new DataResponse([
            'phrases' => array_map(
                fn(array $row) => $this->serialize($row['phrase'], $row['scopes']),
                $this->phraseService->listWithScopes(),
            ),
        ]);
    }

    /**
     * Request body (JSON): title (required), description, content (rich text,
     * HTML — sanitised, see PhraseHtml), category_id,
     * status (draft | active | archived), scopes — array of {scope_type, scope_value}.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/phrases')]
    public function create(): DataResponse {
        if ($denied = $this->checkCanManage()) {
            return $denied;
        }
        try {
            $phrase = $this->phraseService->create($this->phraseParams(), $this->userId);
            return new DataResponse(['phrase' => $this->serializeById($phrase->getId())], Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Request body (JSON): any of title, description, content, category_id, status, scopes.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/phrases/{id}')]
    public function update(int $id): DataResponse {
        if ($denied = $this->checkCanManage()) {
            return $denied;
        }
        try {
            $this->phraseService->update($id, $this->phraseParams(), $this->userId);
            return new DataResponse(['phrase' => $this->serializeById($id)]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * The templates a phrase is attached to:
     * [{id, name, original_filename, is_required, is_default, org_uuids, matches_orgs}].
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/phrases/{id}/templates')]
    public function templates(int $id): DataResponse {
        if ($denied = $this->checkCanManage()) {
            return $denied;
        }
        try {
            return new DataResponse(['templates' => array_map(fn(array $row) => [
                'id'                => $row['template']->getId(),
                'name'              => $row['template']->getName(),
                'original_filename' => $row['template']->getOriginalFilename(),
                'is_required'       => $row['is_required'],
                'is_default'        => $row['is_default'],
                'org_uuids'         => $row['org_uuids'],
                'matches_orgs'      => $row['matches_orgs'],
            ], $this->phraseService->templatesUsing($id))]);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/phrases/{id}')]
    public function destroy(int $id): DataResponse {
        if ($denied = $this->checkCanManage()) {
            return $denied;
        }
        try {
            $this->phraseService->delete($id);
            return new DataResponse(['deleted' => true]);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Upload (or replace) the phrase's file. Expects multipart/form-data
     * with "file" — a .docx or .odt document.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/phrases/{id}/file')]
    public function uploadFile(int $id): DataResponse {
        if ($denied = $this->checkCanManage()) {
            return $denied;
        }

        $file = $this->request->getUploadedFile('file');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            $errorMsg = match ($errorCode) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $this->l10n->t('Filen er større end den tilladte uploadstørrelse'),
                UPLOAD_ERR_PARTIAL  => $this->l10n->t('Filen blev kun delvist uploadet'),
                UPLOAD_ERR_NO_FILE  => $this->l10n->t('Der blev ikke uploadet nogen fil'),
                default             => $this->l10n->t('Upload af filen mislykkedes'),
            };
            return new DataResponse(['error' => $errorMsg], Http::STATUS_BAD_REQUEST);
        }

        $stream = fopen($file['tmp_name'], 'rb');
        if ($stream === false) {
            return new DataResponse(['error' => $this->l10n->t('Den uploadede fil kunne ikke læses')], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        try {
            $this->phraseService->uploadFile($id, $file['name'], $file['type'] ?: 'application/octet-stream', $stream, $this->userId);
            return new DataResponse(['phrase' => $this->serializeById($id)]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Give the phrase a blank document (stored in _phrases/). The response's
     * nc_file_id opens it in Nextcloud Office at /f/{nc_file_id}.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/phrases/{id}/new-blank')]
    public function createBlankFile(int $id): DataResponse {
        if ($denied = $this->checkCanManage()) {
            return $denied;
        }
        try {
            $this->phraseService->createBlankFile($id, $this->userId);
            return new DataResponse(
                ['phrase' => ['nc_file_id' => $this->phraseService->openForEdit($id, $this->userId)] + $this->serializeById($id)],
                Http::STATUS_CREATED,
            );
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return new DataResponse(
                ['error' => 'Failed to create blank phrase document: ' . $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }

    /**
     * The Nextcloud file id of the phrase's stored file, for editing it in
     * place in Nextcloud Office.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/phrases/{id}/open-for-edit')]
    public function openForEdit(int $id): DataResponse {
        if ($denied = $this->checkCanManage()) {
            return $denied;
        }
        try {
            return new DataResponse(['nc_file_id' => $this->phraseService->openForEdit($id, $this->userId)]);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return new DataResponse(
                ['error' => 'Failed to open phrase for edit: ' . $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/phrases/{id}/download')]
    public function download(int $id): DataDownloadResponse|DataResponse {
        if ($denied = $this->checkCanManage()) {
            return $denied;
        }
        try {
            $phrase  = $this->phraseService->get($id);
            $stream  = $this->phraseService->openStream($id);
            $content = stream_get_contents($stream);
            fclose($stream);

            return new DataDownloadResponse(
                $content,
                (string)$phrase->getOriginalFilename(),
                (string)$phrase->getMimeType(),
            );
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function checkCanManage(): ?DataResponse {
        if ($this->userId === null
            || !($this->roleService->userHasRole($this->userId, 'Administrator')
                || $this->roleService->userHasRole($this->userId, 'Template Designer'))) {
            return new DataResponse(['error' => 'Not permitted'], Http::STATUS_FORBIDDEN);
        }
        return null;
    }

    private function phraseParams(): array {
        return array_intersect_key(
            $this->request->getParams(),
            array_flip(['title', 'description', 'content', 'category_id', 'status', 'scopes']),
        );
    }

    private function serializeById(int $id): array {
        $row = $this->phraseService->getWithScopes($id);
        return $this->serialize($row['phrase'], $row['scopes']);
    }

    /**
     * @param PhraseScope[] $scopes
     */
    private function serialize(Phrase $phrase, array $scopes): array {
        return [
            'id'                => $phrase->getId(),
            'category_id'       => $phrase->getCategoryId(),
            'title'             => $phrase->getTitle(),
            'description'       => $phrase->getDescription(),
            'content'           => $phrase->getContent(),
            'has_content'       => $phrase->hasContent(),
            'original_filename' => $phrase->getOriginalFilename(),
            'mime_type'         => $phrase->getMimeType(),
            'size'              => $this->phraseService->fileSize($phrase),
            'has_file'          => $phrase->hasFile(),
            'status'            => $phrase->getStatus(),
            'created_at'        => $phrase->getCreatedAt()?->format('c'),
            'created_by'        => $phrase->getCreatedBy(),
            'updated_at'        => $phrase->getUpdatedAt()?->format('c'),
            'updated_by'        => $phrase->getUpdatedBy(),
            'scopes'            => array_map(fn(PhraseScope $s) => [
                'scope_type'  => $s->getScopeType(),
                'scope_value' => $s->getScopeValue(),
            ], $scopes),
        ];
    }
}
