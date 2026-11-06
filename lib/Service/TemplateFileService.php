<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\FileEntity;

/**
 * Create a file on a document from a template: case/document metadata merged
 * into the placeholders and the chosen phrases into {{fraser.indhold}}.
 * Shared by the web API (FileController) and the desktop API.
 */
class TemplateFileService {

    public function __construct(
        private TemplateService $templateService,
        private TemplateMergeService $templateMergeService,
        private PhraseService $phraseService,
        private FileService $fileService,
        private DocumentMapper $documentMapper,
        private CaseMapper $caseMapper,
        private PermissionService $permissionService,
    ) {
    }

    /**
     * @param string     $filename  name for the new file ('' = the template's file
     *                              name); the template's extension is always kept
     * @param int[]|null $phraseIds the template's phrases to merge; required ones
     *                              are always included. null = the pre-selected ones
     *
     * @throws NotFoundException         template, document or case not found, or no write access
     * @throws \InvalidArgumentException template not available for the case's
     *                                   organisation, or an invalid file name
     * @throws \RuntimeException         merging or storing the file failed
     */
    public function createFile(int $documentId, int $templateId, string $filename, ?array $phraseIds, string $userId): FileEntity {
        $template = $this->templateService->get($templateId);

        $document = $this->documentMapper->findById($documentId);
        if ($document === null || $document->isSoftDeleted()) {
            throw new NotFoundException('Document not found');
        }
        // Checked again on upload — this just saves the merge work
        if (!$this->permissionService->userHasWriteAccessToDocument($userId, $documentId)) {
            throw new NotFoundException('Access denied');
        }
        $case = $this->caseMapper->findById($document->getCaseId());
        if ($case === null) {
            throw new NotFoundException('Case not found');
        }
        if (!$this->templateService->isAvailableForOrg($templateId, $case->getOrgUuid())) {
            throw new \InvalidArgumentException('The template is not available for this case\'s organisation');
        }

        // The user may name the new file; the template's extension is kept.
        $targetName = $template->getOriginalFilename();
        if (trim($filename) !== '') {
            $targetName = FileService::sanitiseFilename(trim($filename), $targetName);
        }

        $tempPath    = null;
        $localCopy   = null;
        $stream      = null;
        $phraseFiles = [];
        try {
            $values      = $this->templateMergeService->buildValues($case, $document);
            $localCopy   = $this->templateService->makeLocalCopy($templateId);
            $phraseFiles = $this->phraseService->exportToTempFiles(
                $this->phraseService->selectForTemplate($templateId, $phraseIds, $case),
            );
            $tempPath    = $this->templateMergeService->mergeToTempFile(
                $localCopy['path'],
                $template->getMimeType(),
                $values,
                $phraseFiles,
                $this->templateMergeService->logoForCase($case),
            );

            $stream = fopen($tempPath, 'rb');
            if ($stream === false) {
                throw new \RuntimeException("Failed to open merged temp file: {$tempPath}");
            }

            return $this->fileService->upload($documentId, $targetName, $template->getMimeType(), $stream, $userId);
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
            $this->phraseService->removeTempFiles($phraseFiles);
        }
    }
}
