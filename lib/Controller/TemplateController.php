<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\TemplatePhrase;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\PhraseService;
use OCA\OpenCase\Service\RoleService;
use OCA\OpenCase\Service\TemplateFieldCatalog;
use OCA\OpenCase\Service\TemplateService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IL10N;

/**
 * REST API controller for document template management.
 *
 * Access control:
 *   - GET  /api/v1/templates          — any OpenCase user
 *   - POST /api/v1/templates          — Administrator or Template Designer
 *   - DELETE /api/v1/templates/{id}   — Administrator or Template Designer
 *   - GET  /api/v1/templates/{id}/download — any OpenCase user
 *   - PUT  /api/v1/templates/{id}     — Administrator or Template Designer
 *   - GET  /api/v1/templates/{id}/phrases — any OpenCase user
 *   - PUT  /api/v1/templates/{id}/phrases — Administrator or Template Designer
 *   - GET  /api/v1/templates/fields   — any OpenCase user
 */
class TemplateController extends OCSController {

    public function __construct(
        string $appName,
        IRequest $request,
        private TemplateService $templateService,
        private TemplateFieldCatalog $templateFieldCatalog,
        private PhraseService $phraseService,
        private RoleService $roleService,
        private IL10N $l10n,
        private DocumentMapper $documentMapper,
        private CaseMapper $caseMapper,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * List templates, each with phrase_count and org_uuids (the organisations
     * it is limited to; empty = all).
     *
     * Query (optional, one of):
     *   document_id — only templates available for that document's case organisation
     *   case_id     — only templates available for that case's organisation
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/templates')]
    public function index(): DataResponse {
        $templates = $this->templateService->list();
        $counts = $this->templateService->phraseCounts();
        $orgScopes = $this->templateService->orgScopes();

        [$case, $notFound] = $this->caseFromRequest();
        if ($notFound !== null) {
            return $notFound;
        }
        if ($case !== null) {
            $templates = array_values(array_filter(
                $templates,
                fn(object $t) => $this->templateService->isAvailableForOrg($t->getId(), $case->getOrgUuid(), $orgScopes[$t->getId()] ?? []),
            ));
        }

        return new DataResponse([
            'templates' => array_map(
                fn(object $t) => $this->serialize($t) + [
                    'phrase_count' => $counts[$t->getId()] ?? 0,
                    'org_uuids'    => $orgScopes[$t->getId()] ?? [],
                ],
                $templates,
            ),
        ]);
    }

    /**
     * The merge fields a template can contain, grouped, with descriptions in
     * the user's language (the "Skabelonfelter" page).
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/templates/fields')]
    public function fields(): DataResponse {
        return new DataResponse(['groups' => $this->templateFieldCatalog->groups($this->l10n->getLanguageCode())]);
    }

    /**
     * Upload a new template.
     *
     * Requires Administrator or Template Designer role.
     * Expects multipart/form-data with:
     *   file      — the template file (.docx, .odt, .xlsx or .ods)
     *   name      — (optional) display name; defaults to original filename
     *   org_uuids — (optional) JSON array: organisations to limit it to
     *   phrases   — (optional) JSON array of {phrase_id, is_default, is_required}
     *
     * All in one request: if the organisations or phrases are invalid the
     * template is not kept (400).
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
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $this->l10n->t('Filen er større end den tilladte uploadstørrelse'),
                UPLOAD_ERR_PARTIAL  => $this->l10n->t('Filen blev kun delvist uploadet'),
                UPLOAD_ERR_NO_FILE  => $this->l10n->t('Der blev ikke uploadet nogen fil'),
                default             => $this->l10n->t('Upload af filen mislykkedes'),
            };
            return new DataResponse(['error' => $errorMsg], Http::STATUS_BAD_REQUEST);
        }

        $name = trim((string) $this->request->getParam('name', ''));
        if ($name === '') {
            $name = $file['name'];
        }

        $stream = fopen($file['tmp_name'], 'rb');
        if ($stream === false) {
            return new DataResponse(['error' => $this->l10n->t('Den uploadede fil kunne ikke læses')], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        try {
            $entity = $this->templateService->upload(
                $name,
                $file['name'],
                $file['type'] ?: 'application/octet-stream',
                $stream,
                $this->userId ?? '',
            );
            $this->templateService->setUpNew($entity->getId(), $this->listParam('org_uuids'), $this->listParam('phrases'));
            return new DataResponse(['template' => $this->serializeNew($entity)], Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Update a template's info. Request body (JSON), each optional:
     *   name      — display name
     *   org_uuids — organisations the template is limited to ([] = all)
     *
     * Requires Administrator or Template Designer role.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/templates/{id}')]
    public function update(int $id): DataResponse {
        if (!$this->canManageTemplates()) {
            return new DataResponse(['error' => 'Not permitted'], Http::STATUS_FORBIDDEN);
        }
        $params = $this->request->getParams();
        try {
            $entity = array_key_exists('name', $params)
                ? $this->templateService->rename($id, (string)$params['name'])
                : $this->templateService->get($id);
            if (array_key_exists('org_uuids', $params)) {
                $this->templateService->setOrgScope($id, is_array($params['org_uuids']) ? $params['org_uuids'] : []);
            }
            $counts = $this->templateService->phraseCounts();
            return new DataResponse(['template' => $this->serialize($entity) + [
                'phrase_count' => $counts[$id] ?? 0,
                'org_uuids'    => $this->templateService->getOrgScope($id),
            ]]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (NotFoundException) {
            return new DataResponse(['error' => 'Template not found'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Phrases attached to a template, in sort order, with each phrase's
     * title, description, status and whether it has a file.
     *
     * Query (optional, one of): document_id / case_id — only the phrases
     * usable in a letter for that case (active, with content, scopes
     * matching the case; see PhraseService::usableForCase()).
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/templates/{id}/phrases')]
    public function phrases(int $id): DataResponse {
        [$case, $notFound] = $this->caseFromRequest();
        if ($notFound !== null) {
            return $notFound;
        }
        try {
            $rows = $this->templateService->getPhrases($id);
            if ($case !== null) {
                $usable = $this->phraseService->usableForCase($id, $case);
                $rows = array_values(array_filter($rows, fn(TemplatePhrase $row) => isset($usable[$row->getPhraseId()])));
            }
            return new DataResponse([
                'phrases' => array_map([$this, 'serializePhrase'], $rows),
            ]);
        } catch (NotFoundException) {
            return new DataResponse(['error' => 'Template not found'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Replace the phrases attached to a template.
     *
     * Request body (JSON): phrases — ordered array of
     * {phrase_id, is_default, is_required}.
     *
     * Requires Administrator or Template Designer role.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/templates/{id}/phrases')]
    public function setPhrases(int $id): DataResponse {
        if (!$this->canManageTemplates()) {
            return new DataResponse(['error' => 'Not permitted'], Http::STATUS_FORBIDDEN);
        }
        $phrases = $this->request->getParam('phrases', []);
        if (!is_array($phrases)) {
            return new DataResponse(['error' => 'phrases must be an array'], Http::STATUS_BAD_REQUEST);
        }
        try {
            return new DataResponse([
                'phrases' => array_map([$this, 'serializePhrase'], $this->templateService->setPhrases($id, $phrases)),
            ]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (NotFoundException) {
            return new DataResponse(['error' => 'Template not found'], Http::STATUS_NOT_FOUND);
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
     * Create a blank template (.docx or .odt, per Nextcloud Office's format
     * setting) in _templates/ storage and register it in the database.
     *
     * Returns the template plus nc_file_id so the frontend can add it to the
     * list and open it in Nextcloud Office right away (edited in place).
     *
     * Requires Administrator or Template Designer role.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/templates/new-blank')]
    public function createBlank(): DataResponse {
        if (!$this->canManageTemplates()) {
            return new DataResponse(['error' => 'Not permitted'], Http::STATUS_FORBIDDEN);
        }

        $name = trim((string) $this->request->getParam('name', ''));
        if ($name === '') {
            $name = 'Ny skabelon';
        }

        try {
            $entity = $this->templateService->createBlank($name, $this->userId);
            $this->templateService->setUpNew($entity->getId(), $this->listParam('org_uuids'), $this->listParam('phrases'));
            return new DataResponse(
                ['template' => $this->serializeNew($entity) + [
                    'nc_file_id' => $this->templateService->openForEdit($entity->getId(), $this->userId),
                ]],
                Http::STATUS_CREATED,
            );
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return new DataResponse(
                ['error' => 'Failed to create blank template: ' . $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }

    /**
     * The Nextcloud file id of the template's stored file. The frontend opens
     * /f/{nc_file_id} in Nextcloud Office, which saves straight back to
     * _templates/ storage — no per-user copy (see BrevdesignerMountProvider).
     *
     * Requires Administrator or Template Designer role.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/templates/{id}/open-for-edit')]
    public function openForEdit(int $id): DataResponse {
        if (!$this->canManageTemplates()) {
            return new DataResponse(['error' => 'Not permitted'], Http::STATUS_FORBIDDEN);
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

    /**
     * The case given by the document_id or case_id request parameter.
     *
     * @return array{0: ?\OCA\OpenCase\Db\CaseEntity, 1: ?DataResponse} [case or null when
     *         neither parameter is given, 404 response when it is not found]
     */
    private function caseFromRequest(): array {
        $documentId = (int)$this->request->getParam('document_id', 0);
        $caseId = (int)$this->request->getParam('case_id', 0);
        if ($documentId <= 0 && $caseId <= 0) {
            return [null, null];
        }
        if ($documentId > 0) {
            $document = $this->documentMapper->findById($documentId);
            $case = $document !== null ? $this->caseMapper->findById($document->getCaseId()) : null;
        } else {
            $case = $this->caseMapper->findById($caseId);
        }
        if ($case === null) {
            return [null, new DataResponse(['error' => $documentId > 0 ? 'Document not found' : 'Case not found'], Http::STATUS_NOT_FOUND)];
        }
        return [$case, null];
    }

    private function canManageTemplates(): bool {
        if ($this->userId === null) {
            return false;
        }
        return $this->roleService->userHasRole($this->userId, 'Administrator')
            || $this->roleService->userHasRole($this->userId, 'Template Designer');
    }

    /**
     * A just created template with its organisations and phrase count.
     */
    private function serializeNew(object $entity): array {
        return $this->serialize($entity) + [
            'phrase_count' => $this->templateService->phraseCounts()[$entity->getId()] ?? 0,
            'org_uuids'    => $this->templateService->getOrgScope($entity->getId()),
        ];
    }

    /**
     * An array request parameter — sent as JSON (array) or, in multipart
     * uploads, as a JSON-encoded string. Missing or invalid = [].
     */
    private function listParam(string $name): array {
        $value = $this->request->getParam($name);
        if (is_string($value) && $value !== '') {
            $value = json_decode($value, true);
        }
        return is_array($value) ? $value : [];
    }

    private function serialize(object $entity): array {
        return [
            'id'                => $entity->getId(),
            'name'              => $entity->getName(),
            'original_filename' => $entity->getOriginalFilename(),
            'mime_type'         => $entity->getMimeType(),
            'size'              => $this->templateService->fileSize($entity),
            'uploaded_by'       => $entity->getUploadedBy(),
            'created_at'        => $entity->getCreatedAt()?->format('c'),
        ];
    }

    private function serializePhrase(TemplatePhrase $row): array {
        try {
            $phrase = $this->phraseService->get($row->getPhraseId());
        } catch (NotFoundException) {
            $phrase = null;
        }
        return [
            'phrase_id'   => $row->getPhraseId(),
            'sort_order'  => $row->getSortOrder(),
            'is_default'  => $row->getIsDefault(),
            'is_required' => $row->getIsRequired(),
            'title'       => $phrase?->getTitle(),
            'description' => $phrase?->getDescription(),
            'category_id' => $phrase?->getCategoryId(),
            'status'      => $phrase?->getStatus(),
            'has_file'    => $phrase?->hasFile() ?? false,
            'has_content' => $phrase?->hasContent() ?? false,
        ];
    }

}
