<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\TemplateEntity;
use OCA\OpenCase\Db\TemplateMapper;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Business logic for document template management.
 *
 * Templates are stored on disk under {storage_base_dir}/_templates/
 * using UUID-based filenames to avoid collisions.
 */
class TemplateService {

    private string $templatesDir;

    public function __construct(
        private TemplateMapper $templateMapper,
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
        $this->ensureTemplatesDir();

        $extension       = pathinfo($originalFilename, PATHINFO_EXTENSION);
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
        $entity->setMimeType($mimeType ?: 'application/octet-stream');
        $entity->setSize((int) $size);
        $entity->setUploadedBy($uploadedBy);
        $entity->setCreatedAt(new \DateTime());

        return $this->templateMapper->insert($entity);
    }

    /**
     * Return the absolute physical path to a stored template file.
     *
     * For NC-backed templates (nc_file_id is set) a temporary copy is extracted
     * from the Nextcloud VFS and returned.  The caller MUST pass the returned
     * array to releaseLocalPath() when done to clean up any temporary file.
     *
     * @throws NotFoundException if the template does not exist
     * @return array{path: string, is_temp: bool}
     */
    public function makeLocalCopy(int $id): array {
        $entity = $this->get($id);

        if ($entity->getNcFileId() !== null && $entity->getNcOwner() !== null) {
            $stream = $this->openNcStream($entity);
            $tmp    = tempnam(sys_get_temp_dir(), 'opencase_tpl_');
            $dest   = fopen($tmp, 'wb');
            if ($dest === false) {
                throw new \RuntimeException("Failed to create temp file for template {$id}");
            }
            stream_copy_to_stream($stream, $dest);
            fclose($stream);
            fclose($dest);
            return ['path' => $tmp, 'is_temp' => true];
        }

        return ['path' => $this->templatesDir . '/' . $entity->getStorageFilename(), 'is_temp' => false];
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
     * Ensure the template has a live copy in the requesting user's Nextcloud
     * home folder (OpenCase/Skabeloner/) and return its NC file ID.
     *
     * - If the template already has nc_file_id owned by this user the existing
     *   file ID is returned (no copy is made).
     * - Otherwise the current template content is read (from _templates/ or the
     *   previous owner's NC copy) and written to this user's home, then the
     *   entity is updated so openStream() picks up the new location.
     *
     * @throws NotFoundException if the template does not exist
     * @throws \RuntimeException  on I/O failure
     */
    public function openForEdit(int $id, string $userId): int {
        $entity = $this->get($id);

        // Re-use existing NC copy if it still belongs to this user
        if ($entity->getNcOwner() === $userId && $entity->getNcFileId() !== null) {
            try {
                $userFolder = $this->rootFolder->getUserFolder($userId);
                $nodes      = $userFolder->getById($entity->getNcFileId());
                if (!empty($nodes)) {
                    return $entity->getNcFileId();
                }
            } catch (\Throwable) {
                // File gone — fall through and create a fresh copy
            }
        }

        // Read current content
        $stream  = $this->openStream($id);
        $content = stream_get_contents($stream);
        fclose($stream);

        // Write to user's NC home, preserving the original file extension
        $ncFileId = $this->writeToUserHome($content, $entity->getOriginalFilename(), $userId);

        // Persist the new link
        $entity->setNcFileId($ncFileId);
        $entity->setNcOwner($userId);
        $this->templateMapper->update($entity);

        return $ncFileId;
    }

    /**
     * Return the absolute physical path to a stored template file.
     *
     * @throws NotFoundException if the template does not exist
     * @deprecated Use makeLocalCopy() for NC-aware path resolution
     */
    public function getStoragePath(int $id): string {
        $entity = $this->get($id);
        return $this->templatesDir . '/' . $entity->getStorageFilename();
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
     * For NC-backed templates (nc_file_id set) the stream is read directly from
     * the Nextcloud VFS so it always reflects the latest version edited in
     * Nextcloud Office.
     *
     * @throws NotFoundException if the template or its file does not exist
     * @return resource
     */
    public function openStream(int $id): mixed {
        $entity = $this->get($id);

        if ($entity->getNcFileId() !== null && $entity->getNcOwner() !== null) {
            return $this->openNcStream($entity);
        }

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
     * A copy is written to _templates/ storage and an identical copy is placed
     * in the user's Nextcloud home at OpenCase/Skabeloner/<name>.<ext> so it
     * can be opened for editing in Nextcloud Office immediately.
     *
     * @throws \RuntimeException on I/O failure
     */
    public function createBlank(string $name, string $userId): TemplateEntity {
        $this->ensureTemplatesDir();

        $useOoxml = $this->config->getAppValue('richdocuments', 'doc_format', 'ooxml') === 'ooxml';

        if ($useOoxml) {
            $blankContent     = $this->buildBlankDocx();
            $ext              = 'docx';
            $mimeType         = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        } else {
            $blankContent     = $this->buildBlankOdt();
            $ext              = 'odt';
            $mimeType         = 'application/vnd.oasis.opendocument.text';
        }

        $originalFilename = $name . '.' . $ext;
        $storageFilename  = bin2hex(random_bytes(16)) . '.' . $ext;
        $storagePath      = $this->templatesDir . '/' . $storageFilename;

        if (file_put_contents($storagePath, $blankContent) === false) {
            throw new \RuntimeException("Failed to write blank template to storage: {$storagePath}");
        }

        $ncFileId = $this->writeToUserHome($blankContent, $originalFilename, $userId);

        $entity = new TemplateEntity();
        $entity->setName($name);
        $entity->setOriginalFilename($originalFilename);
        $entity->setStorageFilename($storageFilename);
        $entity->setMimeType($mimeType);
        $entity->setSize(strlen($blankContent));
        $entity->setUploadedBy($userId);
        $entity->setCreatedAt(new \DateTime());
        $entity->setNcFileId($ncFileId);
        $entity->setNcOwner($userId);

        return $this->templateMapper->insert($entity);
    }

    private function ensureTemplatesDir(): void {
        if (!is_dir($this->templatesDir)) {
            if (!mkdir($this->templatesDir, 0750, true) && !is_dir($this->templatesDir)) {
                throw new \RuntimeException("Failed to create templates directory: {$this->templatesDir}");
            }
        }
    }

    /**
     * Open a readable PHP stream for an NC-backed template entity.
     *
     * @return resource
     * @throws NotFoundException
     */
    private function openNcStream(TemplateEntity $entity): mixed {
        $ncFileId = $entity->getNcFileId();
        $owner    = $entity->getNcOwner();

        try {
            $userFolder = $this->rootFolder->getUserFolder($owner);
            $nodes      = $userFolder->getById($ncFileId);
            if (empty($nodes)) {
                throw new NotFoundException("NC file {$ncFileId} not found for template {$entity->getId()}");
            }
            /** @var \OCP\Files\File $file */
            $file   = $nodes[0];
            $stream = $file->fopen('rb');
            if ($stream === false) {
                throw new \RuntimeException("Failed to open NC file {$ncFileId}");
            }
            return $stream;
        } catch (\OCP\Files\NotFoundException $e) {
            throw new NotFoundException("NC file {$ncFileId} not found: " . $e->getMessage());
        }
    }

    /**
     * Write $content to the user's Nextcloud home at OpenCase/Skabeloner/<filename>.
     *
     * $filename must include the extension (e.g. "Contract.docx").
     * A numeric suffix is appended when the name is already taken.
     * Returns the NC file ID of the created file.
     */
    private function writeToUserHome(string $content, string $filename, string $userId): int {
        $userFolder = $this->rootFolder->getUserFolder($userId);
        $folderPath = 'OpenCase/Skabeloner';

        if (!$userFolder->nodeExists($folderPath)) {
            if (!$userFolder->nodeExists('OpenCase')) {
                $userFolder->newFolder('OpenCase');
            }
            $userFolder->newFolder($folderPath);
        }

        /** @var \OCP\Files\Folder $folder */
        $folder   = $userFolder->get($folderPath);
        $baseName = pathinfo($filename, PATHINFO_FILENAME);
        $ext      = pathinfo($filename, PATHINFO_EXTENSION);
        $suffix   = $ext !== '' ? '.' . $ext : '';

        $unique  = $filename;
        $counter = 1;
        while ($folder->nodeExists($unique)) {
            $unique = $baseName . ' (' . $counter++ . ')' . $suffix;
        }

        $file = $folder->newFile($unique, $content);
        return $file->getId();
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
