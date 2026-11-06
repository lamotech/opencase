<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\CaseEntity;
use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Db\TemplateEntity;
use OCA\OpenCase\Mount\BrevdesignerMountProvider;
use OCA\OpenCase\Service\DesktopLinks;
use OCA\OpenCase\Service\HistoryService;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\PermissionService;
use OCA\OpenCase\Service\PhraseCategoryService;
use OCA\OpenCase\Service\PhraseService;
use OCA\OpenCase\Service\RoleService;
use OCA\OpenCase\Service\TemplateFieldCatalog;
use OCA\OpenCase\Service\TemplateFileService;
use OCA\OpenCase\Service\TemplateService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Templates in the desktop API: list them, create new ones (as the
 * "Ny skabelon" dialog, src/components/NewTemplateDialog.vue) and open them
 * in the editor. Same authentication as DesktopApiController.
 *
 * Routes:
 *   GET  /desktop/v1/api/templates         — Templates, with editor URLs for template managers
 *   POST /desktop/v1/api/templates         — Create a template (blank or uploaded file)
 *   GET  /desktop/v1/api/templates/fields  — Merge fields for templates
 *   GET  /desktop/v1/api/organisations     — Organisation tree, for limiting a template
 *   GET  /desktop/v1/api/templates/{id}/phrases            — A template's phrases usable for a case
 *   POST /desktop/v1/api/documents/{id}/files/from-template — New file on a document from a template
 *
 * The current user (GET /desktop/v1/api/me): see DesktopApiController.
 *
 * The request and response formats are documented in README.md
 * ("Login and backend API").
 */
class DesktopTemplateApiController extends ApiController {


    public function __construct(
        string $appName,
        IRequest $request,
        private TemplateService $templateService,
        private TemplateFieldCatalog $templateFieldCatalog,
        private PhraseService $phraseService,
        private PhraseCategoryService $phraseCategoryService,
        private RoleService $roleService,
        private PermissionService $permissionService,
        private CaseMapper $caseMapper,
        private DocumentMapper $documentMapper,
        private TemplateFileService $templateFileService,
        private LoggerInterface $logger,
        private OrganisationMapper $organisationMapper,
        private DesktopLinks $links,
        private HistoryService $historyService,
        private IUserManager $userManager,
        private IL10N $l10n,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * List templates, newest first, like the template list in OpenCase.
     *
     * Query: search (part of the name or file name), caseId or documentId
     * (only templates available for that case's organisation). Users who may manage templates
     * (Administrator / Template Designer) get a fileId and editorUrl that
     * edit the stored template in place; for others those are null.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function index(string $client = 'eurooffice', string $search = '', int $caseId = 0, int $documentId = 0): JSONResponse {
        if (!$this->links->isSupportedClient($client)) {
            return new JSONResponse(['error' => 'Unsupported client'], Http::STATUS_BAD_REQUEST);
        }

        $userId    = $this->userId ?? '';
        $templates = $this->templateService->list();

        $search = mb_strtolower(trim($search));
        if ($search !== '') {
            $templates = array_filter($templates, fn(TemplateEntity $t) =>
                str_contains(mb_strtolower($t->getName()), $search)
                || str_contains(mb_strtolower($t->getOriginalFilename()), $search));
        }

        if ($caseId > 0 || $documentId > 0) {
            $case = $this->readableCase($caseId, $documentId);
            if ($case === null) {
                return new JSONResponse(['error' => 'Case not found or no access'], Http::STATUS_NOT_FOUND);
            }
            $orgScopes = $this->templateService->orgScopes();
            $templates = array_filter($templates, fn(TemplateEntity $t) =>
                $this->templateService->isAvailableForOrg($t->getId(), $case->getOrgUuid(), $orgScopes[$t->getId()] ?? []));
        }

        $canEdit = $this->canManageTemplates();
        return new JSONResponse(array_values(array_map(
            fn(TemplateEntity $t) => $this->serializeTemplate($t, $client, $canEdit),
            $templates,
        )));
    }

    /**
     * Create a template, as the "Ny skabelon" dialog does. Template managers only.
     *
     * Blank file — JSON body: name, orgUuids, phrases.
     * Uploaded file — multipart/form-data: file, name, orgUuids and phrases
     * (both JSON-encoded).
     *
     * phrases: [{phraseId, isRequired, isDefault}], in order. All in one
     * request: if the organisations or phrases are invalid, nothing is kept.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function create(string $client = 'eurooffice'): JSONResponse {
        if (!$this->links->isSupportedClient($client)) {
            return new JSONResponse(['error' => 'Unsupported client'], Http::STATUS_BAD_REQUEST);
        }
        if (!$this->canManageTemplates()) {
            return new JSONResponse(['error' => 'Not permitted'], Http::STATUS_FORBIDDEN);
        }

        $userId   = $this->userId ?? '';
        $name     = trim((string)$this->request->getParam('name', ''));
        $orgUuids = array_map('strval', $this->listParam('orgUuids'));
        $phrases  = array_map(fn($p) => [
            'phrase_id'   => (int)($p['phraseId'] ?? 0),
            'is_required' => $p['isRequired'] ?? false,
            'is_default'  => $p['isDefault'] ?? false,
        ], array_filter($this->listParam('phrases'), 'is_array'));

        $upload = $this->request->getUploadedFile('file');
        $stream = null;

        try {
            if ($upload === null) {
                $entity = $this->templateService->createBlank($name !== '' ? $name : $this->l10n->t('Ny skabelon'), $userId);
            } else {
                if ($upload['error'] !== UPLOAD_ERR_OK) {
                    $message = match ($upload['error']) {
                        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds maximum upload size',
                        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                        default => 'File upload failed',
                    };
                    return new JSONResponse(['error' => $message], Http::STATUS_BAD_REQUEST);
                }
                $stream = fopen($upload['tmp_name'], 'rb');
                if ($stream === false) {
                    return new JSONResponse(['error' => 'Failed to read uploaded file'], Http::STATUS_INTERNAL_SERVER_ERROR);
                }
                $entity = $this->templateService->upload(
                    $name !== '' ? $name : (string)$upload['name'],
                    (string)$upload['name'],
                    $upload['type'] ?: 'application/octet-stream',
                    $stream,
                    $userId,
                );
            }
            $this->templateService->setUpNew($entity->getId(), $orgUuids, $phrases);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException) {
            return new JSONResponse(['error' => 'Failed to create template'], Http::STATUS_INTERNAL_SERVER_ERROR);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return new JSONResponse(
            $this->serializeTemplate($entity, $client, true) + [
                'orgUuids'    => $this->templateService->getOrgScope($entity->getId()),
                'phraseCount' => count($phrases),
            ],
            Http::STATUS_CREATED,
        );
    }

    /**
     * A template's phrases that can go into a file for the given case (or
     * the document's case), in the template's order: active, with content,
     * and scoped to the case's case type, organisation and KLE number — the
     * same ones the "Ny fil fra skabelon" dialog offers
     * (PhraseService::usableForCase()). Without a case: all active phrases
     * with content.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function templatePhrases(int $id, int $caseId = 0, int $documentId = 0): JSONResponse {
        $case = null;
        if ($caseId > 0 || $documentId > 0) {
            $case = $this->readableCase($caseId, $documentId);
            if ($case === null) {
                return new JSONResponse(['error' => 'Case not found or no access'], Http::STATUS_NOT_FOUND);
            }
        }
        try {
            $rows = $this->templateService->getPhrases($id);
        } catch (NotFoundException) {
            return new JSONResponse(['error' => 'Template not found'], Http::STATUS_NOT_FOUND);
        }

        $usable     = $this->phraseService->usableForCase($id, $case);
        $paths      = $this->phraseCategoryService->paths();
        $result     = [];
        foreach ($rows as $row) {
            $phrase = $usable[$row->getPhraseId()] ?? null;
            if ($phrase === null) {
                continue;
            }
            $result[] = [
                'phraseId'     => $phrase->getId(),
                'title'        => $phrase->getTitle(),
                'description'  => $phrase->getDescription() ?? '',
                'categoryPath' => $paths[$phrase->getCategoryId()] ?? '',
                'isRequired'   => (bool)$row->getIsRequired(),
                'isDefault'    => (bool)$row->getIsRequired() || (bool)$row->getIsDefault(),
            ];
        }
        return new JSONResponse($result);
    }

    /**
     * Create a file on a document from a template, as the "Ny fil fra
     * skabelon" dialog does, and return it ready to open in the editor.
     *
     * Body (JSON): templateId (required), filename (optional; the template's
     * extension is kept), phraseIds (optional; the template's phrases to
     * include — obligatory ones always are; left out = the pre-selected ones).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function createFile(int $id, string $client = 'eurooffice'): JSONResponse {
        if (!$this->links->isSupportedClient($client)) {
            return new JSONResponse(['error' => 'Unsupported client'], Http::STATUS_BAD_REQUEST);
        }
        $templateId = (int)$this->request->getParam('templateId', 0);
        if ($templateId <= 0) {
            return new JSONResponse(['error' => "Field 'templateId' is required"], Http::STATUS_BAD_REQUEST);
        }
        $phraseIds = $this->request->getParam('phraseIds');
        $userId    = $this->userId ?? '';

        try {
            $file = $this->templateFileService->createFile(
                $id,
                $templateId,
                (string)$this->request->getParam('filename', ''),
                is_array($phraseIds) ? array_map('intval', $phraseIds) : null,
                $userId,
            );
        } catch (NotFoundException) {
            return new JSONResponse(['error' => 'Template or document not found, or no write access'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('OpenCase desktop: create file from template failed: {msg}', ['msg' => $e->getMessage()]);
            return new JSONResponse(['error' => 'Failed to create file from template'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $document   = $this->documentMapper->findById($id);
        $case       = $this->caseMapper->findById($file->getCaseId());
        $editorFile = $case === null ? null : $this->links->editorFile($file, $case, $client, $userId);
        if ($document === null || $editorFile === null) {
            return new JSONResponse(['error' => 'File was created but cannot be opened'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        $this->historyService->recordDocumentAccess($id, $userId);

        return new JSONResponse($editorFile + [
            'document' => [
                'id'             => $document->getId(),
                'documentnumber' => $document->getDocumentNumber(),
                'title'          => $document->getTitle(),
                'url'            => $this->links->appUrl('document/' . $document->getId()),
            ],
        ], Http::STATUS_CREATED);
    }

    /**
     * The merge fields a template can contain, as on the "Skabelonfelter"
     * page (/opencase/templates/fields). `name` and `placeholder` use the
     * Danish field names when the user's language is Danish, like the page.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function fields(): JSONResponse {
        $lang   = $this->l10n->getLanguageCode();
        $danish = str_starts_with($lang, 'da');

        return new JSONResponse([
            'syntax' => $danish ? '{{feltnavn}}' : '{{fieldname}}',
            'groups' => array_map(fn(array $group) => [
                'key'    => $group['key'],
                'label'  => $group['label'],
                'note'   => $group['note'],
                'fields' => array_map(fn(array $f) => [
                    'name'        => $danish ? $f['da'] : $f['en'],
                    'placeholder' => '{{' . ($danish ? $f['da'] : $f['en']) . '}}',
                    'da'          => $f['da'],
                    'en'          => $f['en'],
                    'description' => $f['description'],
                ], $group['fields']),
            ], $this->templateFieldCatalog->groups($lang)),
        ]);
    }

    /**
     * Active organisations as a tree flattened in display order (parents
     * before children, siblings by name), with each one's depth — for the
     * "Organisationer" picker of a new template.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[CORS]
    public function organisations(): JSONResponse {
        $orgs = $this->organisationMapper->findAllActive();
        $uuids = [];
        foreach ($orgs as $o) {
            $uuids[$o->getOrgUuid()] = true;
        }
        $children = [];
        foreach ($orgs as $o) {
            $parent = $o->getOrgParentUuid() !== '' && isset($uuids[$o->getOrgParentUuid()]) ? $o->getOrgParentUuid() : '';
            $children[$parent][] = $o;
        }

        $result = [];
        $seen = [];
        $walk = function (string $parent, int $depth) use (&$walk, &$result, &$seen, $children): void {
            $list = $children[$parent] ?? [];
            usort($list, fn($a, $b) => strcoll($a->getOrgName(), $b->getOrgName()));
            foreach ($list as $o) {
                if (isset($seen[$o->getOrgUuid()])) {
                    continue;
                }
                $seen[$o->getOrgUuid()] = true;
                $result[] = [
                    'uuid'       => $o->getOrgUuid(),
                    'name'       => $o->getOrgName(),
                    'parentUuid' => $parent !== '' ? $parent : null,
                    'depth'      => $depth,
                ];
                $walk($o->getOrgUuid(), $depth + 1);
            }
        };
        $walk('', 0);

        return new JSONResponse($result);
    }


    private function serializeTemplate(TemplateEntity $t, string $client, bool $canEdit): array {
        $ncFileId = null;
        if ($canEdit) {
            try {
                $ncFileId = $this->templateService->openForEdit($t->getId(), $this->userId ?? '');
            } catch (NotFoundException) {
                // File missing on disk — listed, but cannot be opened
            }
        }
        return [
            'id'               => $t->getId(),
            'name'             => $t->getName(),
            'originalFilename' => $t->getOriginalFilename(),
            'mimeType'         => $t->getMimeType(),
            'size'             => $this->templateService->fileSize($t),
            'createdAt'        => $t->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'uploadedBy'       => $this->links->user($t->getUploadedBy()),
            'canEdit'          => $ncFileId !== null,
            'fileId'           => $ncFileId,
            'editorUrl'        => $ncFileId === null ? null : $this->links->editorUrl(
                $client,
                $ncFileId,
                '/' . BrevdesignerMountProvider::userPath('_templates', $t->getStorageFilename()),
            ),
        ];
    }





    /**
     * The case given directly or through one of its documents, if the user
     * can read it.
     */
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

    /** A JSON array parameter, sent as JSON or (multipart) as a JSON string. */
    private function listParam(string $name): array {
        $value = $this->request->getParam($name);
        if (is_string($value) && $value !== '') {
            $value = json_decode($value, true);
        }
        return is_array($value) ? $value : [];
    }

    private function canManageTemplates(): bool {
        $userId = $this->userId ?? '';
        return $this->roleService->userHasRole($userId, 'Administrator')
            || $this->roleService->userHasRole($userId, 'Template Designer');
    }
}
