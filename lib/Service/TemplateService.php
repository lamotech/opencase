<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Db\PhraseMapper;
use OCA\OpenCase\Db\PhraseScopeMapper;
use OCA\OpenCase\Db\TemplateEntity;
use OCA\OpenCase\Db\TemplatePhrase;
use OCA\OpenCase\Db\TemplateMapper;
use OCA\OpenCase\Db\TemplatePhraseMapper;
use OCA\OpenCase\Db\TemplateScope;
use OCA\OpenCase\Db\TemplateScopeMapper;
use OCA\OpenCase\Mount\BrevdesignerMountProvider;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Business logic for document template management.
 *
 * Templates are stored on disk under {storage_base_dir}/_templates/
 * using UUID-based filenames to avoid collisions.
 */
class TemplateService {

    /**
     * Template formats placeholders can be merged into (TemplateMergeService):
     * extension => MIME type. Phrases and logos only go into .docx / .odt.
     */
    public const FORMATS = [
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'odt'  => 'application/vnd.oasis.opendocument.text',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
    ];

    private string $templatesDir;

    public function __construct(
        private TemplateMapper $templateMapper,
        private TemplatePhraseMapper $templatePhraseMapper,
        private PhraseMapper $phraseMapper,
        private PhraseScopeMapper $phraseScopeMapper,
        private TemplateScopeMapper $templateScopeMapper,
        private OrganisationMapper $organisationMapper,
        private CaseScopeMatcher $scopeMatcher,
        private IL10N $l10n,
        private IConfig $config,
        private IRootFolder $rootFolder,
        private LoggerInterface $logger,
    ) {
        $defaultDir = rtrim($config->getSystemValueString('datadirectory', ''), '/') . '/opencase_storage';
        $baseDir = $config->getAppValue('opencase', 'storage_base_dir', $defaultDir);
        $this->templatesDir = rtrim($baseDir, '/') . '/_templates';
    }

    /**
     * Return all templates, newest first.
     *
     * @return TemplateEntity[]
     */
    public function list(): array {
        return $this->templateMapper->findAll();
    }

    /**
     * Upload a new template.
     *
     * @param string   $name             Display name chosen by the user
     * @param string   $originalFilename Original upload filename
     * @param string   $mimeType
     * @param resource $stream           Readable stream of file content
     * @param string   $uploadedBy       NC user ID
     */
    public function upload(
        string $name,
        string $originalFilename,
        string $mimeType,
        mixed $stream,
        string $uploadedBy,
    ): TemplateEntity {
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        if (!isset(self::FORMATS[$extension])) {
            throw new \InvalidArgumentException($this->l10n->t('Kun Word (.docx), ODF tekst (.odt), Excel (.xlsx) og ODF regneark (.ods) kan bruges som skabeloner'));
        }
        $this->ensureTemplatesDir();

        $storageFilename = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
        $storagePath     = $this->templatesDir . '/' . $storageFilename;

        $dest = fopen($storagePath, 'wb');
        if ($dest === false) {
            throw new \RuntimeException("Failed to open template storage path: {$storagePath}");
        }

        $size = stream_copy_to_stream($stream, $dest);
        fclose($dest);

        if ($size === false) {
            @unlink($storagePath);
            throw new \RuntimeException('Failed to write template file to disk');
        }

        $entity = new TemplateEntity();
        $entity->setName($name);
        $entity->setOriginalFilename($originalFilename);
        $entity->setStorageFilename($storageFilename);
        // The merge picks the format by MIME type; browsers sometimes send a generic one.
        $entity->setMimeType(self::FORMATS[$extension]);
        $entity->setSize((int) $size);
        $entity->setUploadedBy($uploadedBy);
        $entity->setCreatedAt(new \DateTime());

        return $this->templateMapper->insert($entity);
    }

    /**
     * Current size of the template's stored file — it changes when the file
     * is edited in Nextcloud Office; the size column is from the upload.
     */
    public function fileSize(TemplateEntity $entity): int {
        $size = @filesize($this->templatesDir . '/' . $entity->getStorageFilename());
        return $size !== false ? $size : $entity->getSize();
    }

    /**
     * Limit a newly created template to organisations and attach phrases.
     * When either is invalid the template is deleted again, so a template
     * is never left half set up.
     *
     * @param string[] $orgUuids
     * @param array{phrase_id: int, is_default?: bool, is_required?: bool}[] $phrases
     * @throws \InvalidArgumentException on invalid organisations or phrases (template deleted)
     */
    public function setUpNew(int $id, array $orgUuids, array $phrases): void {
        try {
            if ($orgUuids !== []) {
                $this->setOrgScope($id, $orgUuids);
            }
            if ($phrases !== []) {
                $this->setPhrases($id, $phrases);
            }
        } catch (\Throwable $e) {
            $this->delete($id);
            throw $e;
        }
    }

    /**
     * Rename a template (display name only — the file is left alone).
     *
     * @throws NotFoundException if the template does not exist
     */
    public function rename(int $id, string $name): TemplateEntity {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException($this->l10n->t('Navn skal udfyldes'));
        }
        $entity = $this->get($id);
        $entity->setName($name);
        return $this->templateMapper->update($entity);
    }

    /**
     * @return array<int, int> number of attached phrases per template id
     */
    public function phraseCounts(): array {
        return $this->templatePhraseMapper->countByTemplate();
    }

    /**
     * Phrases attached to a template, in sort order.
     *
     * @return TemplatePhrase[]
     * @throws NotFoundException if the template does not exist
     */
    public function getPhrases(int $id): array {
        $this->get($id);
        return $this->templatePhraseMapper->findByTemplateId($id);
    }

    /**
     * Replace the phrases attached to a template. The list order becomes
     * sort_order; a required phrase is always also a default phrase. Every
     * phrase must match the template's organisations (phraseMatchesOrgs()).
     *
     * @param array{phrase_id: int, is_default?: bool, is_required?: bool}[] $phrases
     * @return TemplatePhrase[]
     * @throws NotFoundException if the template does not exist
     * @throws \InvalidArgumentException on an unknown or duplicated phrase
     */
    public function setPhrases(int $id, array $phrases): array {
        $this->get($id);

        $rows = [];
        $templateOrgs = null;
        foreach (array_values($phrases) as $index => $p) {
            $phraseId = (int)($p['phrase_id'] ?? 0);
            if (isset($rows[$phraseId])) {
                throw new \InvalidArgumentException($this->l10n->t('Frasen %s er valgt mere end én gang', [$phraseId]));
            }
            $phrase = $this->phraseMapper->find($phraseId);
            if ($phrase === null) {
                throw new \InvalidArgumentException($this->l10n->t('Frasen %s findes ikke', [$phraseId]));
            }
            if (!$this->phraseMatchesOrgs($phraseId, $templateOrgs ??= $this->getOrgScope($id))) {
                throw new \InvalidArgumentException($this->l10n->t('Frasen "%s" matcher ikke skabelonens organisationer', [$phrase->getTitle()]));
            }
            $required = filter_var($p['is_required'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $row = new TemplatePhrase();
            $row->setTemplateId($id);
            $row->setPhraseId($phraseId);
            $row->setSortOrder($index);
            $row->setIsRequired($required);
            $row->setIsDefault($required || filter_var($p['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN));
            $rows[$phraseId] = $row;
        }

        $this->templatePhraseMapper->deleteByTemplateId($id);
        foreach ($rows as $row) {
            $this->templatePhraseMapper->insert($row);
        }

        return $this->templatePhraseMapper->findByTemplateId($id);
    }

    /**
     * Organisation uuids a template is limited to (empty = no limit).
     *
     * @return string[]
     * @throws NotFoundException if the template does not exist
     */
    public function getOrgScope(int $id): array {
        $this->get($id);
        return $this->orgValues($this->templateScopeMapper->findByTemplateId($id));
    }

    /**
     * Organisation scope of every template that has one.
     *
     * @return array<int, string[]> template id => organisation uuids
     */
    public function orgScopes(): array {
        return array_map(fn(array $scopes) => $this->orgValues($scopes), $this->templateScopeMapper->findAllGrouped());
    }

    /**
     * Limit a template to organisations (empty = no limit). Attached phrases
     * that no longer match are reported, not removed — see
     * phraseMatchesOrgs(); setPhrases() rejects them.
     *
     * @param string[] $orgUuids
     * @throws NotFoundException if the template does not exist
     * @throws \InvalidArgumentException on an unknown organisation
     */
    public function setOrgScope(int $id, array $orgUuids): void {
        $this->get($id);
        $orgUuids = array_values(array_unique(array_filter(array_map('strval', $orgUuids), fn($u) => $u !== '')));
        foreach ($orgUuids as $uuid) {
            if ($this->organisationMapper->findByUuid($uuid) === null) {
                throw new \InvalidArgumentException($this->l10n->t('Organisationen %s findes ikke', [$uuid]));
            }
        }
        $this->templateScopeMapper->deleteByTemplateId($id);
        foreach ($orgUuids as $uuid) {
            $scope = new TemplateScope();
            $scope->setTemplateId($id);
            $scope->setScopeType('organisation');
            $scope->setScopeValue($uuid);
            $this->templateScopeMapper->insert($scope);
        }
    }

    /**
     * Whether a template may be used for a case in organisation $orgUuid:
     * it has no organisation limit, or the case organisation is (below) one
     * of the template's organisations.
     *
     * @param string[]|null $templateOrgs the template's organisation scope, if already loaded
     */
    public function isAvailableForOrg(int $id, ?string $orgUuid, ?array $templateOrgs = null): bool {
        $templateOrgs ??= $this->orgValues($this->templateScopeMapper->findByTemplateId($id));
        if ($templateOrgs === []) {
            return true;
        }
        if ($orgUuid === null || $orgUuid === '') {
            return false;
        }
        return array_intersect($this->scopeMatcher->resolveOrgAncestorUuids($orgUuid), $templateOrgs) !== [];
    }

    /**
     * Whether a phrase may be attached to a template limited to $templateOrgs:
     * the template has no limit, the phrase has no organisation scope, or one
     * of the phrase's organisations is (above) one of the template's.
     *
     * @param string[] $templateOrgs
     */
    public function phraseMatchesOrgs(int $phraseId, array $templateOrgs): bool {
        if ($templateOrgs === []) {
            return true;
        }
        $phraseOrgs = $this->orgValues($this->phraseScopeMapper->findGroupedByPhraseIds([$phraseId])[$phraseId] ?? []);
        if ($phraseOrgs === []) {
            return true;
        }
        foreach ($templateOrgs as $templateOrg) {
            if (array_intersect($this->scopeMatcher->resolveOrgAncestorUuids($templateOrg), $phraseOrgs) !== []) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<\OCA\OpenCase\Db\TemplateScope|\OCA\OpenCase\Db\PhraseScope> $scopes
     * @return string[] organisation uuids among the scopes
     */
    private function orgValues(array $scopes): array {
        $values = [];
        foreach ($scopes as $scope) {
            if ($scope->getScopeType() === 'organisation') {
                $values[] = $scope->getScopeValue();
            }
        }
        return $values;
    }

    /**
     * Return the absolute physical path to a stored template file. Templates
     * live only in {storage_base_dir}/_templates/ (edits in Nextcloud Office
     * are saved there directly, see BrevdesignerMountProvider), so this is
     * never a temporary copy; releaseLocalCopy() is kept for callers.
     *
     * @throws NotFoundException if the template does not exist, or its stored
     *                            file is missing from disk
     * @return array{path: string, is_temp: bool}
     */
    public function makeLocalCopy(int $id): array {
        $entity = $this->get($id);

        $path = $this->templatesDir . '/' . $entity->getStorageFilename();
        if (!is_file($path)) {
            // Metadata row without its file — nothing can be merged from it
            $this->logger->error('OpenCase: template {id} ({name}) has no file at {path}', [
                'id'   => $id,
                'name' => $entity->getName(),
                'path' => $path,
            ]);
            throw new NotFoundException(
                "Template file is missing on the server for template {$id} ({$entity->getName()})"
            );
        }

        return ['path' => $path, 'is_temp' => false];
    }

    /**
     * Clean up a path returned by makeLocalCopy() if it was a temporary file.
     */
    public function releaseLocalCopy(array $localCopy): void {
        if ($localCopy['is_temp'] && file_exists($localCopy['path'])) {
            @unlink($localCopy['path']);
        }
    }

    /**
     * The Nextcloud file id of the template's stored file, for opening it in
     * Nextcloud Office (/f/{id}). The file is edited in place in
     * {storage_base_dir}/_templates/ through the Brevdesigner mount — no copy
     * is made. Only users who may manage templates have that mount.
     *
     * @throws NotFoundException if the template or its file does not exist
     */
    public function openForEdit(int $id, string $userId): int {
        $entity = $this->get($id);
        if (!is_file($this->templatesDir . '/' . $entity->getStorageFilename())) {
            throw new NotFoundException("Template file missing on disk for template {$id}");
        }
        return $this->storedFileId('_templates', $entity->getStorageFilename(), $userId);
    }

    /**
     * Nextcloud file id of a file in a Brevdesigner storage folder
     * ("_templates" / "_phrases") as seen through the user's mount.
     *
     * @throws NotFoundException if the user has no such mount or file
     */
    public function storedFileId(string $storageFolder, string $storageFilename, string $userId): int {
        try {
            return $this->rootFolder->getUserFolder($userId)
                ->get(BrevdesignerMountProvider::userPath($storageFolder, $storageFilename))
                ->getId();
        } catch (\OCP\Files\NotFoundException|\OCP\Files\NotPermittedException $e) {
            throw new NotFoundException("Stored file {$storageFolder}/{$storageFilename} is not available to {$userId}");
        }
    }

    /**
     * Delete a template by ID.
     *
     * @throws NotFoundException if the template does not exist
     */
    public function delete(int $id): void {
        $entity = $this->templateMapper->findById($id);
        if ($entity === null) {
            throw new NotFoundException("Template {$id} not found");
        }

        $storagePath = $this->templatesDir . '/' . $entity->getStorageFilename();
        if (file_exists($storagePath)) {
            @unlink($storagePath);
        }

        $this->templatePhraseMapper->deleteByTemplateId($id);
        $this->templateScopeMapper->deleteByTemplateId($id);
        $this->templateMapper->delete($entity);
    }

    /**
     * Get a template entity by ID.
     *
     * @throws NotFoundException if the template does not exist
     */
    public function get(int $id): TemplateEntity {
        $entity = $this->templateMapper->findById($id);
        if ($entity === null) {
            throw new NotFoundException("Template {$id} not found");
        }
        return $entity;
    }

    /**
     * Open a readable stream for a template's stored file.
     *
     * @throws NotFoundException if the template or its file does not exist
     * @return resource
     */
    public function openStream(int $id): mixed {
        $entity = $this->get($id);
        $storagePath = $this->templatesDir . '/' . $entity->getStorageFilename();

        if (!file_exists($storagePath)) {
            throw new NotFoundException("Template file missing on disk for template {$id}");
        }

        $stream = fopen($storagePath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("Failed to open template file: {$storagePath}");
        }

        return $stream;
    }

    /**
     * Create a blank template and register it in the database.
     *
     * The file format (docx / odt) is determined by the richdocuments app
     * configuration key "doc_format":
     *   'ooxml' (default) → .docx / application/vnd.openxmlformats-officedocument.wordprocessingml.document
     *   anything else     → .odt  / application/vnd.oasis.opendocument.text
     *
     * The file is written to _templates/ storage only; openForEdit() gives the
     * id for opening it in Nextcloud Office.
     *
     * @throws \RuntimeException on I/O failure
     */
    public function createBlank(string $name, string $userId): TemplateEntity {
        $this->ensureTemplatesDir();

        ['content' => $blankContent, 'ext' => $ext, 'mime_type' => $mimeType] = $this->buildBlankDocument();

        $originalFilename = $name . '.' . $ext;
        $storageFilename  = bin2hex(random_bytes(16)) . '.' . $ext;
        $storagePath      = $this->templatesDir . '/' . $storageFilename;

        if (file_put_contents($storagePath, $blankContent) === false) {
            throw new \RuntimeException("Failed to write blank template to storage: {$storagePath}");
        }

        $entity = new TemplateEntity();
        $entity->setName($name);
        $entity->setOriginalFilename($originalFilename);
        $entity->setStorageFilename($storageFilename);
        $entity->setMimeType($mimeType);
        $entity->setSize(strlen($blankContent));
        $entity->setUploadedBy($userId);
        $entity->setCreatedAt(new \DateTime());

        return $this->templateMapper->insert($entity);
    }

    /**
     * Build a blank text document in the format configured for Nextcloud
     * Office (richdocuments "doc_format"):
     *   'ooxml' (default) → .docx
     *   anything else     → .odt
     *
     * @return array{content: string, ext: string, mime_type: string}
     */
    public function buildBlankDocument(): array {
        if ($this->config->getAppValue('richdocuments', 'doc_format', 'ooxml') === 'ooxml') {
            return [
                'content'   => $this->buildBlankDocx(),
                'ext'       => 'docx',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ];
        }
        return [
            'content'   => $this->buildBlankOdt(),
            'ext'       => 'odt',
            'mime_type' => 'application/vnd.oasis.opendocument.text',
        ];
    }

    private function ensureTemplatesDir(): void {
        if (!is_dir($this->templatesDir)) {
            if (!mkdir($this->templatesDir, 0750, true) && !is_dir($this->templatesDir)) {
                throw new \RuntimeException("Failed to create templates directory: {$this->templatesDir}");
            }
        }
    }

    /**
     * Build the binary content of a minimal blank OOXML Word document (.docx).
     */
    private function buildBlankDocx(): string {
        $tmp = tempnam(sys_get_temp_dir(), 'opencase_tpl_');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', implode("\n", [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">',
            ' <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>',
            ' <Default Extension="xml"  ContentType="application/xml"/>',
            ' <Override PartName="/word/document.xml"',
            '   ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>',
            ' <Override PartName="/word/settings.xml"',
            '   ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>',
            ' <Override PartName="/word/styles.xml"',
            '   ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>',
            '</Types>',
        ]));

        $zip->addFromString('_rels/.rels', implode("\n", [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">',
            ' <Relationship Id="rId1"',
            '   Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"',
            '   Target="word/document.xml"/>',
            '</Relationships>',
        ]));

        $zip->addFromString('word/_rels/document.xml.rels', implode("\n", [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">',
            ' <Relationship Id="rId1"',
            '   Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"',
            '   Target="styles.xml"/>',
            ' <Relationship Id="rId2"',
            '   Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings"',
            '   Target="settings.xml"/>',
            '</Relationships>',
        ]));

        $zip->addFromString('word/document.xml', implode("\n", [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">',
            ' <w:body>',
            '  <w:p/>',
            ' </w:body>',
            '</w:document>',
        ]));

        $zip->addFromString('word/styles.xml', implode("\n", [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>',
        ]));

        $zip->addFromString('word/settings.xml', implode("\n", [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>',
        ]));

        $zip->close();
        $content = file_get_contents($tmp);
        @unlink($tmp);
        return $content ?: '';
    }

    /**
     * Build the binary content of a minimal blank ODF text document (.odt).
     */
    private function buildBlankOdt(): string {
        $tmp = tempnam(sys_get_temp_dir(), 'opencase_tpl_');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);

        $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.text');
        $zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);

        $zip->addFromString('META-INF/manifest.xml', implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2">',
            ' <manifest:file-entry manifest:full-path="/" manifest:version="1.2" manifest:media-type="application/vnd.oasis.opendocument.text"/>',
            ' <manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>',
            ' <manifest:file-entry manifest:full-path="styles.xml"  manifest:media-type="text/xml"/>',
            ' <manifest:file-entry manifest:full-path="meta.xml"    manifest:media-type="text/xml"/>',
            ' <manifest:file-entry manifest:full-path="settings.xml" manifest:media-type="text/xml"/>',
            '</manifest:manifest>',
        ]));

        $zip->addFromString('content.xml', implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<office:document-content',
            '  xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"',
            '  xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"',
            '  office:version="1.2">',
            ' <office:body>',
            '  <office:text><text:p/></office:text>',
            ' </office:body>',
            '</office:document-content>',
        ]));

        $zip->addFromString('styles.xml', '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<office:document-styles xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" office:version="1.2"/>');
        $zip->addFromString('meta.xml', '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<office:document-meta xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" office:version="1.2"/>');
        $zip->addFromString('settings.xml', '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<office:document-settings xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" office:version="1.2"/>');

        $zip->close();
        $content = file_get_contents($tmp);
        @unlink($tmp);
        return $content ?: '';
    }
}
