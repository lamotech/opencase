<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\CaseEntity;
use OCA\OpenCase\Db\Phrase;
use OCA\OpenCase\Db\PhraseCategoryMapper;
use OCA\OpenCase\Db\PhraseMapper;
use OCA\OpenCase\Db\PhraseScope;
use OCA\OpenCase\Db\PhraseScopeMapper;
use OCA\OpenCase\Db\TemplatePhraseMapper;
use OCP\IConfig;
use OCP\IL10N;

/**
 * Business logic for the Brevdesigner phrase library (opencase_phrases and
 * opencase_phrases_scope).
 *
 * A phrase's content is a text document stored on disk under
 * {storage_base_dir}/_phrases/ with a random filename, like templates under
 * _templates/ (see TemplateService). Nextcloud Office edits that file in
 * place through the Brevdesigner mount (BrevdesignerMountProvider) — users
 * never get their own copies.
 */
class PhraseService {

    /** File types accepted as phrase content (formatted text documents). */
    public const ALLOWED_EXTENSIONS = ['docx', 'odt'];

    private string $phrasesDir;

    public function __construct(
        private PhraseMapper $phraseMapper,
        private PhraseScopeMapper $scopeMapper,
        private PhraseCategoryMapper $categoryMapper,
        private TemplatePhraseMapper $templatePhraseMapper,
        private TemplateService $templateService,
        private CaseService $caseService,
        private CaseScopeMatcher $scopeMatcher,
        private IL10N $l10n,
        IConfig $config,
    ) {
        $defaultDir = rtrim($config->getSystemValueString('datadirectory', ''), '/') . '/opencase_storage';
        $baseDir = $config->getAppValue('opencase', 'storage_base_dir', $defaultDir);
        $this->phrasesDir = rtrim($baseDir, '/') . '/_phrases';
    }

    /**
     * @return array{phrase: Phrase, scopes: PhraseScope[]}[]
     */
    public function listWithScopes(): array {
        $phrases = $this->phraseMapper->findAll();
        $scopesByPhrase = $this->scopeMapper->findGroupedByPhraseIds(
            array_map(fn(Phrase $p) => $p->getId(), $phrases)
        );
        return array_map(fn(Phrase $p) => [
            'phrase' => $p,
            'scopes' => $scopesByPhrase[$p->getId()] ?? [],
        ], $phrases);
    }

    /**
     * @return array{phrase: Phrase, scopes: PhraseScope[]}
     */
    public function getWithScopes(int $id): array {
        return [
            'phrase' => $this->get($id),
            'scopes' => $this->scopeMapper->findGroupedByPhraseIds([$id])[$id] ?? [],
        ];
    }

    public function get(int $id): Phrase {
        $phrase = $this->phraseMapper->find($id);
        if ($phrase === null) {
            throw new NotFoundException("Phrase {$id} not found");
        }
        return $phrase;
    }

    /**
     * @param array{title?: string, description?: ?string, category_id?: ?int, status?: string,
     *              scopes?: array{scope_type: string, scope_value: string}[]} $data
     */
    public function create(array $data, ?string $userId): Phrase {
        // Validate the scopes first, so an invalid one doesn't leave a phrase behind
        $this->validateScopes($data['scopes'] ?? []);

        $phrase = new Phrase();
        $phrase->setStatus('draft');
        $phrase->setSize(0);
        $phrase->setCreatedAt(new \DateTime());
        $phrase->setCreatedBy($userId);
        $this->applyChanges($phrase, $data + ['title' => '']);
        $phrase->setUpdatedAt(new \DateTime());
        $phrase->setUpdatedBy($userId);
        $phrase = $this->phraseMapper->insert($phrase);

        $this->replaceScopes($phrase->getId(), $data['scopes'] ?? []);

        return $phrase;
    }

    /**
     * Apply the keys present in $changes (title, description, category_id,
     * status, scopes — scopes replace all existing scopes).
     */
    public function update(int $id, array $changes, ?string $userId): Phrase {
        $phrase = $this->get($id);
        if (array_key_exists('scopes', $changes) && is_array($changes['scopes'])) {
            // Before anything is saved — replaceScopes() would otherwise drop
            // the existing scopes and stop at the invalid one
            $this->validateScopes($changes['scopes']);
        }
        $this->applyChanges($phrase, $changes);
        $phrase->setUpdatedAt(new \DateTime());
        $phrase->setUpdatedBy($userId);
        $phrase = $this->phraseMapper->update($phrase);

        if (array_key_exists('scopes', $changes) && is_array($changes['scopes'])) {
            $this->replaceScopes($id, $changes['scopes']);
        }

        return $phrase;
    }

    public function delete(int $id): void {
        $phrase = $this->get($id);
        $this->removeStoredFile($phrase);
        $this->scopeMapper->deleteByPhraseId($id);
        $this->templatePhraseMapper->deleteByPhraseId($id);
        $this->phraseMapper->delete($phrase);
    }

    /**
     * Current size of the phrase's stored file (0 without a file) — it
     * changes when the file is edited in Nextcloud Office.
     */
    public function fileSize(Phrase $phrase): int {
        if (!$phrase->hasFile()) {
            return 0;
        }
        $size = @filesize($this->phrasesDir . '/' . $phrase->getStorageFilename());
        return $size !== false ? $size : $phrase->getSize();
    }

    /**
     * The templates a phrase is attached to, with how it is attached and
     * whether the phrase's organisation scope still matches the template's
     * organisations (TemplateService::phraseMatchesOrgs()).
     *
     * @return array<int, array{template: \OCA\OpenCase\Db\TemplateEntity, is_required: bool, is_default: bool, org_uuids: string[], matches_orgs: bool}>
     */
    public function templatesUsing(int $phraseId): array {
        $this->get($phraseId);
        $result = [];
        foreach ($this->templatePhraseMapper->findByPhraseId($phraseId) as $row) {
            try {
                $template = $this->templateService->get($row->getTemplateId());
            } catch (NotFoundException) {
                continue;
            }
            $orgs = $this->templateService->getOrgScope($template->getId());
            $result[] = [
                'template'     => $template,
                'is_required'  => $row->getIsRequired(),
                'is_default'   => $row->getIsDefault(),
                'org_uuids'    => $orgs,
                'matches_orgs' => $this->templateService->phraseMatchesOrgs($phraseId, $orgs),
            ];
        }
        usort($result, fn($a, $b) => strcasecmp($a['template']->getName(), $b['template']->getName()));
        return $result;
    }

    /**
     * Store an uploaded file as the phrase's content, replacing any previous
     * file.
     *
     * @param resource $stream
     */
    public function uploadFile(int $id, string $originalFilename, string $mimeType, mixed $stream, ?string $userId): Phrase {
        $phrase = $this->get($id);

        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \InvalidArgumentException($this->l10n->t('Kun .docx- og .odt-filer kan bruges som fraser'));
        }

        $this->ensurePhrasesDir();
        $storageFilename = bin2hex(random_bytes(16)) . '.' . $extension;
        $storagePath = $this->phrasesDir . '/' . $storageFilename;

        $dest = fopen($storagePath, 'wb');
        if ($dest === false) {
            throw new \RuntimeException("Failed to open phrase storage path: {$storagePath}");
        }
        $size = stream_copy_to_stream($stream, $dest);
        fclose($dest);
        if ($size === false) {
            @unlink($storagePath);
            throw new \RuntimeException('Failed to write phrase file to disk');
        }

        return $this->replaceFile($phrase, $originalFilename, $storageFilename, $mimeType ?: 'application/octet-stream', (int)$size, $userId);
    }

    /**
     * Give the phrase a blank document as content (stored in _phrases/ only);
     * openForEdit() gives the id for opening it in Nextcloud Office.
     */
    public function createBlankFile(int $id, string $userId): Phrase {
        $phrase = $this->get($id);

        ['content' => $content, 'ext' => $ext, 'mime_type' => $mimeType] = $this->templateService->buildBlankDocument();

        $this->ensurePhrasesDir();
        $storageFilename = bin2hex(random_bytes(16)) . '.' . $ext;
        $storagePath = $this->phrasesDir . '/' . $storageFilename;
        if (file_put_contents($storagePath, $content) === false) {
            throw new \RuntimeException("Failed to write blank phrase to storage: {$storagePath}");
        }

        $originalFilename = $this->safeFilename($phrase->getTitle()) . '.' . $ext;
        return $this->replaceFile($phrase, $originalFilename, $storageFilename, $mimeType, strlen($content), $userId);
    }

    /**
     * The Nextcloud file id of the phrase's stored file, for opening it in
     * Nextcloud Office — edited in place in _phrases/ (see
     * TemplateService::openForEdit()).
     *
     * @throws NotFoundException if the phrase has no file
     */
    public function openForEdit(int $id, string $userId): int {
        $phrase = $this->get($id);
        if (!$phrase->hasFile() || !is_file($this->phrasesDir . '/' . $phrase->getStorageFilename())) {
            throw new NotFoundException("Phrase {$id} has no file");
        }
        return $this->templateService->storedFileId('_phrases', $phrase->getStorageFilename(), $userId);
    }

    /**
     * Open a readable stream for the phrase's stored file.
     *
     * @return resource
     * @throws NotFoundException if the phrase or its file does not exist
     */
    public function openStream(int $id): mixed {
        $phrase = $this->get($id);
        if (!$phrase->hasFile()) {
            throw new NotFoundException("Phrase {$id} has no file");
        }
        $storagePath = $this->phrasesDir . '/' . $phrase->getStorageFilename();
        if (!is_file($storagePath)) {
            throw new NotFoundException("Phrase file missing on disk for phrase {$id}");
        }
        $stream = fopen($storagePath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("Failed to open phrase file: {$storagePath}");
        }
        return $stream;
    }

    /**
     * The phrases to merge into a file created from a template, in the
     * template's phrase order.
     *
     * $phraseIds is the user's selection; phrases not attached to the
     * template are ignored, and required phrases are always included.
     * null selects the template's defaults (required + pre-selected).
     *
     * Only phrases usable in a letter for $case are returned — see
     * usableForCase(): active, with content, and matching the case.
     *
     * @param int[]|null $phraseIds
     * @return Phrase[]
     */
    public function selectForTemplate(int $templateId, ?array $phraseIds, ?CaseEntity $case = null): array {
        $selected = $phraseIds !== null ? array_flip(array_map('intval', $phraseIds)) : null;
        $usable = $this->usableForCase($templateId, $case);

        $result = [];
        foreach ($this->templatePhraseMapper->findByTemplateId($templateId) as $row) {
            $wanted = $row->getIsRequired()
                || ($selected === null ? $row->getIsDefault() : isset($selected[$row->getPhraseId()]));
            if ($wanted && isset($usable[$row->getPhraseId()])) {
                $result[] = $usable[$row->getPhraseId()];
            }
        }
        return $result;
    }

    /**
     * The template's phrases that can go into a letter for $case:
     *   - status "active" (drafts and archived phrases are never merged),
     *   - with content (a file, or rich text — Phrase::hasContent()),
     *   - and, when $case is given, whose scopes match the case's case type,
     *     organisation, KLE number and Emneplan item — like custom field scopes
     *     (CaseScopeMatcher; a phrase without scopes matches every case).
     *
     * A required phrase that is not usable is left out too.
     *
     * @return array<int, Phrase> phrase id => phrase
     */
    public function usableForCase(int $templateId, ?CaseEntity $case): array {
        $phraseIds = array_map(fn($row) => $row->getPhraseId(), $this->templatePhraseMapper->findByTemplateId($templateId));
        $scopes = $this->scopeMapper->findGroupedByPhraseIds($phraseIds);
        $inScope = $case !== null ? $this->caseScopeFilter($case) : null;

        $usable = [];
        foreach ($phraseIds as $phraseId) {
            $phrase = $this->phraseMapper->find($phraseId);
            if ($phrase === null || $phrase->getStatus() !== 'active' || !$phrase->hasContent()) {
                continue;
            }
            if ($inScope !== null && !$inScope($scopes[$phraseId] ?? [])) {
                continue;
            }
            $usable[$phraseId] = $phrase;
        }
        return $usable;
    }

    /**
     * A check of a phrase's scopes against the case (CaseScopeMatcher::matches()).
     *
     * @return \Closure(PhraseScope[]): bool
     */
    public function caseScopeFilter(CaseEntity $case): \Closure {
        $kleCode = $this->caseService->getClassificationCodeForCase($case);
        $orgAncestors = $case->getOrgUuid() !== '' ? $this->scopeMatcher->resolveOrgAncestorUuids($case->getOrgUuid()) : [];
        return fn(array $scopes): bool => $this->scopeMatcher->matches($scopes, $case->getCasetypeId(), $orgAncestors, $kleCode, $case->getClassPlanUuid());
    }

    /**
     * Write the current content of each phrase to a temp file, for
     * TemplateMergeService::mergeToTempFile(). A phrase without a file is
     * written as its rich text (text/html, see PhraseHtml). Phrases whose
     * file cannot be read are skipped. The caller must pass the result to
     * removeTempFiles().
     *
     * @param Phrase[] $phrases
     * @return array{path: string, mime_type: string}[]
     */
    public function exportToTempFiles(array $phrases): array {
        $files = [];
        foreach ($phrases as $phrase) {
            if (!$phrase->hasFile()) {
                // Rich text, rendered by PhraseComposer
                if (PhraseHtml::hasText($phrase->getContent())) {
                    $path = sys_get_temp_dir() . '/opencase_phrase_' . bin2hex(random_bytes(8)) . '.html';
                    if (file_put_contents($path, (string)$phrase->getContent()) !== false) {
                        $files[] = ['path' => $path, 'mime_type' => 'text/html'];
                    }
                }
                continue;
            }
            try {
                $stream = $this->openStream($phrase->getId());
            } catch (NotFoundException) {
                continue;
            }
            $ext = pathinfo((string)$phrase->getOriginalFilename(), PATHINFO_EXTENSION);
            $path = sys_get_temp_dir() . '/opencase_phrase_' . bin2hex(random_bytes(8)) . ($ext !== '' ? '.' . $ext : '');
            $dest = fopen($path, 'wb');
            if ($dest === false) {
                fclose($stream);
                continue;
            }
            stream_copy_to_stream($stream, $dest);
            fclose($stream);
            fclose($dest);
            $files[] = ['path' => $path, 'mime_type' => $this->mimeTypeOf($phrase)];
        }
        return $files;
    }

    /**
     * @param array{path: string, mime_type: string}[] $files from exportToTempFiles()
     */
    public function removeTempFiles(array $files): void {
        foreach ($files as $file) {
            if (is_file($file['path'])) {
                @unlink($file['path']);
            }
        }
    }

    /**
     * The phrase file's MIME type — derived from the extension when the
     * browser sent a generic one on upload.
     */
    private function mimeTypeOf(Phrase $phrase): string {
        return match (strtolower(pathinfo((string)$phrase->getOriginalFilename(), PATHINFO_EXTENSION))) {
            'docx'  => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'odt'   => 'application/vnd.oasis.opendocument.text',
            default => (string)$phrase->getMimeType(),
        };
    }

    private function applyChanges(Phrase $phrase, array $changes): void {
        if (array_key_exists('title', $changes)) {
            $title = trim((string)$changes['title']);
            if ($title === '') {
                throw new \InvalidArgumentException($this->l10n->t('Titel skal udfyldes'));
            }
            $phrase->setTitle($title);
        }
        if (array_key_exists('description', $changes)) {
            $description = trim((string)($changes['description'] ?? ''));
            $phrase->setDescription($description === '' ? null : $description);
        }
        if (array_key_exists('content', $changes)) {
            $content = PhraseHtml::sanitize((string)($changes['content'] ?? ''));
            $phrase->setContent($content === '' ? null : $content);
        }
        if (array_key_exists('category_id', $changes)) {
            $categoryId = $changes['category_id'] === null || $changes['category_id'] === '' ? null : (int)$changes['category_id'];
            if ($categoryId !== null && $this->categoryMapper->find($categoryId) === null) {
                throw new \InvalidArgumentException($this->l10n->t('Frasekategorien %s findes ikke', [$categoryId]));
            }
            $phrase->setCategoryId($categoryId);
        }
        if (array_key_exists('status', $changes)) {
            $status = (string)$changes['status'];
            if (!in_array($status, Phrase::STATUSES, true)) {
                throw new \InvalidArgumentException($this->l10n->t('Ugyldig status "%s"', [$status]));
            }
            $phrase->setStatus($status);
        }
    }

    private function replaceFile(
        Phrase $phrase,
        string $originalFilename,
        string $storageFilename,
        string $mimeType,
        int $size,
        ?string $userId,
    ): Phrase {
        $this->removeStoredFile($phrase);

        $phrase->setOriginalFilename($originalFilename);
        $phrase->setStorageFilename($storageFilename);
        $phrase->setMimeType($mimeType);
        $phrase->setSize($size);
        $phrase->setUpdatedAt(new \DateTime());
        $phrase->setUpdatedBy($userId);
        return $this->phraseMapper->update($phrase);
    }

    private function removeStoredFile(Phrase $phrase): void {
        if (!$phrase->hasFile()) {
            return;
        }
        $path = $this->phrasesDir . '/' . $phrase->getStorageFilename();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @param array{scope_type: string, scope_value: string}[] $scopes
     * @throws \InvalidArgumentException on an unknown scope type
     */
    private function validateScopes(array $scopes): void {
        foreach ($scopes as $s) {
            if (trim((string)($s['scope_value'] ?? '')) !== ''
                && !in_array($s['scope_type'] ?? '', CaseScopeMatcher::SCOPE_TYPES, true)) {
                throw new \InvalidArgumentException($this->l10n->t('Ugyldig afgrænsningstype "%s"', [$s['scope_type'] ?? '']));
            }
        }
    }

    /**
     * @param array{scope_type: string, scope_value: string}[] $scopes
     */
    private function replaceScopes(int $phraseId, array $scopes): void {
        $this->scopeMapper->deleteByPhraseId($phraseId);
        foreach ($scopes as $s) {
            $scopeType = $s['scope_type'] ?? '';
            $scopeValue = trim((string)($s['scope_value'] ?? ''));
            if ($scopeValue === '') {
                continue;
            }
            if (!in_array($scopeType, CaseScopeMatcher::SCOPE_TYPES, true)) {
                throw new \InvalidArgumentException($this->l10n->t('Ugyldig afgrænsningstype "%s"', [$scopeType]));
            }
            $entity = new PhraseScope();
            $entity->setPhrasesId($phraseId);
            $entity->setScopeType($scopeType);
            $entity->setScopeValue($scopeValue);
            $this->scopeMapper->insert($entity);
        }
    }

    private function ensurePhrasesDir(): void {
        if (!is_dir($this->phrasesDir)) {
            if (!mkdir($this->phrasesDir, 0750, true) && !is_dir($this->phrasesDir)) {
                throw new \RuntimeException("Failed to create phrases directory: {$this->phrasesDir}");
            }
        }
    }

    /**
     * A phrase title usable as a Nextcloud filename (without extension).
     */
    private function safeFilename(string $title): string {
        $name = trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]+/', ' ', $title) ?? '');
        return $name !== '' ? mb_substr($name, 0, 200) : 'Frase';
    }
}
