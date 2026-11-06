<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\CaseEntity;
use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\CaseTypeMapper;
use OCA\OpenCase\Db\ClassificationPlanMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Db\Phrase;
use OCA\OpenCase\Db\PhraseScope;
use OCA\OpenCase\Mount\BrevdesignerMountProvider;
use OCA\OpenCase\Service\CaseScopeMatcher;
use OCA\OpenCase\Service\DesktopLinks;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\PermissionService;
use OCA\OpenCase\Service\PhraseCategoryService;
use OCA\OpenCase\Service\PhraseComposer;
use OCA\OpenCase\Service\PhraseHtml;
use OCA\OpenCase\Service\PhraseService;
use OCA\OpenCase\Service\RoleService;
use OCA\OpenCase\Service\TemplateService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Phrases (fraser) in the desktop API: list and read them, insert their text
 * into the open document, and — for template managers (Administrator /
 * Template Designer) — create and edit them, as the phrase dialog in
 * OpenCase (src/components/PhraseDialog.vue). A phrase's file is edited in
 * place in the editor, like a template. Same authentication as
 * DesktopApiController.
 *
 * Other users only reach what could go into a letter: active phrases, and
 * for a phrase with scopes only with a case (caseId or documentId) it matches.
 *
 * Routes:
 *   GET  /desktop/v1/api/phrases                 — Phrases (filterable)
 *   POST /desktop/v1/api/phrases                 — Create a phrase (managers)
 *   GET  /desktop/v1/api/phrases/{id}            — A phrase with its text, scopes and usage
 *   PUT  /desktop/v1/api/phrases/{id}            — Change a phrase (managers)
 *   POST /desktop/v1/api/phrases/{id}/file       — Give a phrase a file: blank or uploaded (managers)
 *   GET  /desktop/v1/api/phrases/{id}/file       — Download a phrase's file
 *   GET  /desktop/v1/api/phrases/{id}/content    — The phrase's text, for inserting into a document
 *   GET  /desktop/v1/api/phrase-categories       — Phrase categories, in tree order
 *   GET  /desktop/v1/api/case-types              — Case types, for a phrase's scopes
 *
 * The request and response formats are documented in README.md
 * ("Login and backend API").
 */
class DesktopPhraseApiController extends ApiController {

    /** Extensions a template file may have (see TemplateService::upload()). */
    private const TEMPLATE_FORMATS = ['docx', 'odt', 'xlsx', 'ods'];

    public function __construct(
        string $appName,
        IRequest $request,
        private PhraseService $phraseService,
        private PhraseCategoryService $phraseCategoryService,
        private PhraseComposer $phraseComposer,
        private TemplateService $templateService,
        private RoleService $roleService,
        private CaseScopeMatcher $scopeMatcher,
        private CaseTypeMapper $caseTypeMapper,
        private OrganisationMapper $organisationMapper,
        private ClassificationPlanMapper $classificationPlanMapper,
        private CaseMapper $caseMapper,
        private DocumentMapper $documentMapper,
        private PermissionService $permissionService,
        private DesktopLinks $links,
        private IL10N $l10n,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Phrases, sorted by category path and title.
     *
     * Query (all optional):
     *   search          — part of the title, description or category
     *   status          — comma-separated: draft, active, archived (default draft,active).
     *                     Template managers only; other users get the active ones
     *   caseId, documentId — only phrases whose scopes match this case. Without
     *                     one, users who aren't template managers only get
     *                     phrases without scopes
     *   orgUuids        — comma-separated: only phrases that can be attached to a
     *                     template limited to these organisations (as the
     *                     "Ny skabelon" dialog; TemplateService::phraseMatchesOrgs())
     *   templateFormat  — docx, odt, xlsx, ods or "blank": adds formatNote when the
     *                     phrase would lose its formatting in such a template
     *   client          — editor the URLs are built for (default eurooffice)
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function index(string $search = '', string $status = '', string $orgUuids = '', string $templateFormat = '', string $client = 'eurooffice', int $caseId = 0, int $documentId = 0): JSONResponse {
        if (!$this->links->isSupportedClient($client)) {
            return new JSONResponse(['error' => 'Unsupported client'], Http::STATUS_BAD_REQUEST);
        }

        $canEdit = $this->canManage();
        $inScope = $this->scopeFilter($caseId, $documentId);
        if ($inScope === null) {
            return new JSONResponse(['error' => 'Case not found or no access'], Http::STATUS_NOT_FOUND);
        }

        $statuses = array_values(array_intersect($this->csv($status), Phrase::STATUSES));
        if (!$canEdit) {
            $statuses = ['active'];
        } elseif ($statuses === []) {
            $statuses = ['draft', 'active'];
        }

        $templateOrgs = $this->csv($orgUuids);
        $allowedOrgs  = [];
        foreach ($templateOrgs as $uuid) {
            foreach ($this->scopeMatcher->resolveOrgAncestorUuids($uuid) as $ancestor) {
                $allowedOrgs[$ancestor] = true;
            }
        }

        $templateFormat = strtolower($templateFormat);
        if ($templateFormat === 'blank') {
            $templateFormat = $this->templateService->buildBlankDocument()['ext'];
        }
        if (!in_array($templateFormat, self::TEMPLATE_FORMATS, true)) {
            $templateFormat = '';
        }

        $search  = mb_strtolower(trim($search));
        $paths   = $this->phraseCategoryService->paths();

        $result = [];
        foreach ($this->phraseService->listWithScopes() as ['phrase' => $phrase, 'scopes' => $scopes]) {
            if (!in_array($phrase->getStatus(), $statuses, true) || !$inScope($scopes)) {
                continue;
            }
            $phraseOrgs = $this->orgScopes($scopes);
            if ($templateOrgs !== [] && $phraseOrgs !== [] && array_intersect($phraseOrgs, array_keys($allowedOrgs)) === []) {
                continue;
            }
            $categoryPath = $paths[$phrase->getCategoryId()] ?? '';
            if ($search !== '' && !str_contains(mb_strtolower($phrase->getTitle() . "\n" . ($phrase->getDescription() ?? '') . "\n" . $categoryPath), $search)) {
                continue;
            }

            $result[] = $this->serializeSummary($phrase, $categoryPath, $client, $canEdit) + [
                'formatNote' => $templateFormat !== '' ? $this->formatNote($phrase, $templateFormat) : null,
            ];
        }

        usort($result, fn($a, $b) => strcoll($a['categoryPath'] . "\0" . $a['title'], $b['categoryPath'] . "\0" . $b['title']));
        return new JSONResponse($result);
    }

    /**
     * A phrase with its text, scopes and the templates it is attached to.
     * Query: caseId or documentId, as for index().
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function show(int $id, string $client = 'eurooffice', int $caseId = 0, int $documentId = 0): JSONResponse {
        if (!$this->links->isSupportedClient($client)) {
            return new JSONResponse(['error' => 'Unsupported client'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $this->requireReadable($id, $caseId, $documentId);
            return new JSONResponse($this->serializeDetail($id, $client));
        } catch (NotFoundException) {
            return new JSONResponse(['error' => 'Phrase not found'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * "Ny frase". Template managers only.
     *
     * JSON body: title (required), description, content (HTML), categoryId,
     * status (draft | active | archived; default draft), scopes
     * [{type: casetype | organisation | kle | class_plan, value}], blankFile (true: give
     * the phrase a blank document to write in the editor).
     * Or multipart/form-data with the same fields (scopes JSON-encoded) and
     * "file" — a .docx or .odt document for the phrase.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function create(string $client = 'eurooffice'): JSONResponse {
        if ($denied = $this->denyUnlessManager($client)) {
            return $denied;
        }

        $upload = $this->request->getUploadedFile('file');
        if ($upload !== null && $upload['error'] !== UPLOAD_ERR_OK) {
            return $this->uploadError($upload['error']);
        }

        try {
            $phrase = $this->phraseService->create($this->phraseChanges(true), $this->userId);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        // The phrase exists from here on; report a failed file without losing it
        $fileError = null;
        try {
            if ($upload !== null) {
                $this->storeUpload($phrase->getId(), $upload);
            } elseif (filter_var($this->request->getParam('blankFile', false), FILTER_VALIDATE_BOOLEAN)) {
                $this->phraseService->createBlankFile($phrase->getId(), $this->userId ?? '');
            }
        } catch (\Throwable $e) {
            $fileError = $e instanceof \InvalidArgumentException
                ? $e->getMessage()
                : $this->l10n->t('Frasen er oprettet, men filen kunne ikke uploades');
        }

        return new JSONResponse(
            $this->serializeDetail($phrase->getId(), $client) + ($fileError !== null ? ['fileError' => $fileError] : []),
            Http::STATUS_CREATED,
        );
    }

    /**
     * Change a phrase. Template managers only. JSON body: any of title,
     * description, content, categoryId, status, scopes (replaces all).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function update(int $id, string $client = 'eurooffice'): JSONResponse {
        if ($denied = $this->denyUnlessManager($client)) {
            return $denied;
        }
        try {
            $this->phraseService->update($id, $this->phraseChanges(false), $this->userId);
            return new JSONResponse($this->serializeDetail($id, $client));
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (NotFoundException) {
            return new JSONResponse(['error' => 'Phrase not found'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Give a phrase a file, replacing any it has. Template managers only.
     * multipart/form-data with "file" (.docx or .odt), or JSON
     * {"blank": true} for a blank document to write in the editor.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function setFile(int $id, string $client = 'eurooffice'): JSONResponse {
        if ($denied = $this->denyUnlessManager($client)) {
            return $denied;
        }
        $upload = $this->request->getUploadedFile('file');
        try {
            if ($upload !== null) {
                if ($upload['error'] !== UPLOAD_ERR_OK) {
                    return $this->uploadError($upload['error']);
                }
                $this->storeUpload($id, $upload);
            } elseif (filter_var($this->request->getParam('blank', false), FILTER_VALIDATE_BOOLEAN)) {
                $this->phraseService->createBlankFile($id, $this->userId ?? '');
            } else {
                return new JSONResponse(['error' => "Send a file in 'file', or {\"blank\": true}"], Http::STATUS_BAD_REQUEST);
            }
            return new JSONResponse($this->serializeDetail($id, $client));
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (NotFoundException) {
            return new JSONResponse(['error' => 'Phrase not found'], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function downloadFile(int $id, int $caseId = 0, int $documentId = 0): DataDownloadResponse|JSONResponse {
        try {
            $phrase = $this->requireReadable($id, $caseId, $documentId);
            $stream = $this->phraseService->openStream($id);
        } catch (NotFoundException) {
            return new JSONResponse(['error' => 'Phrase or file not found'], Http::STATUS_NOT_FOUND);
        }
        $content = (string)stream_get_contents($stream);
        fclose($stream);
        return new DataDownloadResponse($content, (string)$phrase->getOriginalFilename(), (string)$phrase->getMimeType());
    }

    /**
     * The phrase's text for "Indsæt frase": html and text. For a phrase with
     * a file, the text of the file (without its formatting) — the file
     * itself, with formatting, is at fileUrl. Query: caseId or documentId, as
     * for index().
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function content(int $id, int $caseId = 0, int $documentId = 0): JSONResponse {
        try {
            $phrase = $this->requireReadable($id, $caseId, $documentId);
        } catch (NotFoundException) {
            return new JSONResponse(['error' => 'Phrase not found'], Http::STATUS_NOT_FOUND);
        }

        if ($phrase->hasFile()) {
            $files = $this->phraseService->exportToTempFiles([$phrase]);
            try {
                $text = $files === [] ? '' : $this->phraseComposer->plainText($files);
            } finally {
                $this->phraseService->removeTempFiles($files);
            }
            $html = implode('', array_map(
                fn(string $line) => '<p>' . htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>',
                $text === '' ? [] : explode("\n", $text),
            ));
        } else {
            $html = (string)$phrase->getContent();
            $text = implode("\n", PhraseHtml::plainLines($html));
        }

        return new JSONResponse([
            'id'         => $phrase->getId(),
            'title'      => $phrase->getTitle(),
            'status'     => $phrase->getStatus(),
            'source'     => $phrase->hasFile() ? 'file' : 'text',
            'html'       => $html,
            'text'       => $text,
            'fileFormat' => $this->fileFormat($phrase),
            'fileUrl'    => $phrase->hasFile() ? $this->fileUrl($phrase->getId(), $caseId, $documentId) : null,
        ]);
    }

    /**
     * Phrase categories in tree order (parents before children), with depth
     * and full path — for the "Kategori" picker.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function categories(): JSONResponse {
        return new JSONResponse(array_map(fn(array $row) => [
            'id'       => $row['category']->getId(),
            'name'     => $row['category']->getName(),
            'parentId' => $row['category']->getParentId(),
            'depth'    => $row['depth'],
            'path'     => $row['path'],
        ], $this->phraseCategoryService->tree()));
    }

    /**
     * Case types in the user's language, for a phrase's scopes.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function caseTypes(): JSONResponse {
        return new JSONResponse(array_map(fn($t) => [
            'id'   => $t->getId(),
            'name' => $t->getName(),
        ], $this->caseTypeMapper->findAllForLanguage($this->l10n->getLanguageCode(), 'en', true)));
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function serializeSummary(Phrase $phrase, string $categoryPath, string $client, bool $canEdit): array {
        $ncFileId = $canEdit ? $this->editableFileId($phrase) : null;
        return [
            'id'           => $phrase->getId(),
            'title'        => $phrase->getTitle(),
            'description'  => $phrase->getDescription() ?? '',
            'categoryId'   => $phrase->getCategoryId(),
            'categoryPath' => $categoryPath,
            'status'       => $phrase->getStatus(),
            'hasText'      => PhraseHtml::hasText($phrase->getContent()),
            'hasFile'      => $phrase->hasFile(),
            'fileFormat'   => $this->fileFormat($phrase),
            'canEdit'      => $canEdit,
            'fileId'       => $ncFileId,
            'editorUrl'    => $ncFileId === null ? null : $this->links->editorUrl(
                $client,
                $ncFileId,
                '/' . BrevdesignerMountProvider::userPath('_phrases', (string)$phrase->getStorageFilename()),
            ),
            'updatedAt'    => $phrase->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @throws NotFoundException
     */
    private function serializeDetail(int $id, string $client): array {
        ['phrase' => $phrase, 'scopes' => $scopes] = $this->phraseService->getWithScopes($id);
        $paths = $this->phraseCategoryService->paths();

        return $this->serializeSummary($phrase, $paths[$phrase->getCategoryId()] ?? '', $client, $this->canManage()) + [
            'content'   => $phrase->getContent() ?? '',
            'scopes'    => array_map(fn(PhraseScope $s) => [
                'type'  => $s->getScopeType(),
                'value' => $s->getScopeValue(),
                'label' => $this->scopeLabel($s),
            ], $scopes),
            'templates' => array_map(fn(array $row) => [
                'id'          => $row['template']->getId(),
                'name'        => $row['template']->getName(),
                'isRequired'  => (bool)$row['is_required'],
                'isDefault'   => (bool)$row['is_default'],
                'matchesOrgs' => (bool)$row['matches_orgs'],
            ], $this->phraseService->templatesUsing($id)),
            'createdAt' => $phrase->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'createdBy' => $this->links->user($phrase->getCreatedBy()),
            'updatedBy' => $this->links->user($phrase->getUpdatedBy()),
        ];
    }

    private function editableFileId(Phrase $phrase): ?int {
        if (!$phrase->hasFile()) {
            return null;
        }
        try {
            return $this->phraseService->openForEdit($phrase->getId(), $this->userId ?? '');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The changes in the request in PhraseService form. Keys left out of the
     * request are left out, so an update only touches what was sent.
     */
    private function phraseChanges(bool $creating): array {
        $params  = $this->request->getParams();
        $changes = [];
        foreach (['title' => 'title', 'description' => 'description', 'content' => 'content', 'categoryId' => 'category_id', 'status' => 'status'] as $in => $out) {
            if (array_key_exists($in, $params)) {
                $changes[$out] = $params[$in];
            }
        }
        if (array_key_exists('scopes', $params)) {
            $scopes = $params['scopes'];
            if (is_string($scopes)) {
                $scopes = json_decode($scopes, true);
            }
            $changes['scopes'] = array_map(fn($s) => [
                'scope_type'  => (string)($s['type'] ?? ''),
                'scope_value' => (string)($s['value'] ?? ''),
            ], array_filter(is_array($scopes) ? $scopes : [], 'is_array'));
        }
        if ($creating && trim((string)($changes['title'] ?? '')) === '') {
            throw new \InvalidArgumentException("Field 'title' is required");
        }
        return $changes;
    }

    private function storeUpload(int $id, array $upload): void {
        $stream = fopen($upload['tmp_name'], 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Failed to read uploaded file');
        }
        try {
            $this->phraseService->uploadFile($id, (string)$upload['name'], $upload['type'] ?: 'application/octet-stream', $stream, $this->userId);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function uploadError(int $code): JSONResponse {
        return new JSONResponse(['error' => match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $this->l10n->t('Filen er større end den tilladte uploadstørrelse'),
            UPLOAD_ERR_PARTIAL  => $this->l10n->t('Filen blev kun delvist uploadet'),
            UPLOAD_ERR_NO_FILE  => $this->l10n->t('Der blev ikke uploadet nogen fil'),
            default             => $this->l10n->t('Upload af filen mislykkedes'),
        }], Http::STATUS_BAD_REQUEST);
    }

    private function denyUnlessManager(string $client): ?JSONResponse {
        if (!$this->links->isSupportedClient($client)) {
            return new JSONResponse(['error' => 'Unsupported client'], Http::STATUS_BAD_REQUEST);
        }
        if (!$this->canManage()) {
            return new JSONResponse(['error' => 'Not permitted'], Http::STATUS_FORBIDDEN);
        }
        return null;
    }

    /**
     * Decides which scoped phrases the user gets: those matching the case
     * when one is given (null if it can't be read); without one all phrases
     * for template managers, and for others only those without scopes, which
     * match every case.
     *
     * @return (\Closure(PhraseScope[]): bool)|null
     */
    private function scopeFilter(int $caseId, int $documentId): ?\Closure {
        if ($caseId > 0 || $documentId > 0) {
            $case = $this->readableCase($caseId, $documentId);
            return $case !== null ? $this->phraseService->caseScopeFilter($case) : null;
        }
        return $this->canManage() ? fn(array $scopes): bool => true : fn(array $scopes): bool => $scopes === [];
    }

    /**
     * The phrase, if the user may read it: template managers any phrase,
     * others an active one in scope.
     *
     * @throws NotFoundException
     */
    private function requireReadable(int $id, int $caseId, int $documentId): Phrase {
        ['phrase' => $phrase, 'scopes' => $scopes] = $this->phraseService->getWithScopes($id);
        if ($this->canManage()) {
            return $phrase;
        }
        $inScope = $this->scopeFilter($caseId, $documentId);
        if ($phrase->getStatus() !== 'active' || $inScope === null || !$inScope($scopes)) {
            throw new NotFoundException("Phrase {$id} not found");
        }
        return $phrase;
    }

    private function readableCase(int $caseId, int $documentId): ?CaseEntity {
        if ($documentId > 0) {
            $caseId = $this->documentMapper->findById($documentId)?->getCaseId() ?? 0;
        }
        $case = $caseId > 0 ? $this->caseMapper->findById($caseId) : null;
        if ($case === null || !$this->permissionService->userHasReadAccessToCase($this->userId ?? '', $caseId)) {
            return null;
        }
        return $case;
    }

    private function canManage(): bool {
        $userId = $this->userId ?? '';
        return $this->roleService->userHasRole($userId, 'Administrator')
            || $this->roleService->userHasRole($userId, 'Template Designer');
    }

    /** Extension of a phrase's file (docx, odt), or null for a text-only phrase. */
    private function fileFormat(Phrase $phrase): ?string {
        if (!$phrase->hasFile()) {
            return null;
        }
        $ext = strtolower(pathinfo((string)$phrase->getOriginalFilename(), PATHINFO_EXTENSION));
        return $ext !== '' ? $ext : null;
    }

    /**
     * Same warning as the template dialog's phrase picker: a phrase file goes
     * in with formatting only into a text template of the same format;
     * spreadsheets get plain text.
     */
    private function formatNote(Phrase $phrase, string $templateFormat): ?string {
        if (in_array($templateFormat, ['xlsx', 'ods'], true)) {
            return $this->l10n->t('Skabelonen er et regneark — frasen indsættes som ren tekst.');
        }
        $phraseFormat = $this->fileFormat($phrase);
        if ($phraseFormat !== null && $phraseFormat !== $templateFormat) {
            return str_replace(
                ['{phrase}', '{template}'],
                [$phraseFormat, $templateFormat],
                $this->l10n->t('Frasens fil er .{phrase}, skabelonen er .{template} — frasen indsættes uden formatering.'),
            );
        }
        return null;
    }

    private function scopeLabel(PhraseScope $s): string {
        $value = $s->getScopeValue();
        return match ($s->getScopeType()) {
            'casetype'     => $this->caseTypeMapper->getNamesByIds([(int)$value], $this->l10n->getLanguageCode())[(int)$value] ?? $value,
            'organisation' => $this->organisationMapper->getNamesByUuids([$value])[$value] ?? $value,
            'class_plan'   => $this->classificationPlanMapper->findByUuid($value)?->getTitle() ?? $value,
            default        => $value,
        };
    }

    /**
     * @param PhraseScope[] $scopes
     * @return string[]
     */
    private function orgScopes(array $scopes): array {
        $orgs = [];
        foreach ($scopes as $scope) {
            if ($scope->getScopeType() === 'organisation') {
                $orgs[] = $scope->getScopeValue();
            }
        }
        return $orgs;
    }

    private function fileUrl(int $id, int $caseId, int $documentId): string {
        $query = http_build_query(array_filter(['caseId' => $caseId, 'documentId' => $documentId]));
        return $this->links->absoluteUrl('/index.php/apps/opencase/desktop/v1/api/phrases/' . $id . '/file' . ($query !== '' ? '?' . $query : ''));
    }

    /** @return string[] */
    private function csv(string $value): array {
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn($v) => $v !== ''));
    }
}
