<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\Document;

/**
 * Converts a document's files to PDF for sending, the way Digital Post
 * does it for additional files (DispatchDigitalPostJob): the case and
 * document merge fields are filled in, then LibreOffice converts the file.
 * PDF files are passed on as they are.
 */
class PdfExportService {

    public function __construct(
        private FileService $fileService,
        private CaseMapper $caseMapper,
        private TemplateMergeService $templateMergeService,
        private LibreOfficeService $libreOfficeService,
    ) {
    }

    /**
     * @param int[] $fileIds files of $document (others are skipped)
     * @return array<int, array{filename: string, mimeType: string, content: string}>
     * @throws NotFoundException when a file is missing or not readable for the user
     * @throws \RuntimeException when a file cannot be converted (the message names it)
     */
    public function filesAsPdf(Document $document, array $fileIds, string $userId): array {
        $case = $this->caseMapper->findById($document->getCaseId());
        if ($case === null) {
            throw new NotFoundException('Case not found');
        }
        $values = $this->templateMergeService->buildValues($case, $document);
        $logo   = $this->templateMergeService->logoForCase($case);

        $workDir = sys_get_temp_dir() . '/opencase_pdf_' . bin2hex(random_bytes(8));
        if (!mkdir($workDir, 0700, true)) {
            throw new \RuntimeException('Failed to create a temporary directory');
        }

        $result = [];
        $used   = [];
        try {
            foreach ($fileIds as $fileId) {
                $download = $this->fileService->download((int)$fileId, $userId);
                $entity   = $download['entity'];
                $content  = stream_get_contents($download['stream']);
                fclose($download['stream']);
                if ($entity->getDocumentId() !== $document->getId()) {
                    continue;
                }

                $name     = $entity->getVirtualFilename() ?: $entity->getOriginalFilename();
                $pdfName  = $this->uniqueName(pathinfo($name, PATHINFO_FILENAME) . '.pdf', $used);
                $mimeType = $entity->getMimeType() ?: 'application/octet-stream';

                if ($mimeType === 'application/pdf') {
                    $result[] = ['filename' => $pdfName, 'mimeType' => 'application/pdf', 'content' => $content];
                    continue;
                }

                $ext    = pathinfo($name, PATHINFO_EXTENSION);
                $source = $workDir . '/' . bin2hex(random_bytes(8)) . ($ext !== '' ? '.' . $ext : '');
                file_put_contents($source, $content);
                $merged = $this->templateMergeService->mergeToTempFile($source, $mimeType, $values, [], $logo);
                try {
                    $pdfPath = $this->libreOfficeService->convertToPdf($merged, $workDir);
                } catch (\RuntimeException $e) {
                    throw new \RuntimeException("Filen \"{$name}\" kunne ikke konverteres til PDF", 0, $e);
                } finally {
                    @unlink($merged);
                }
                $result[] = ['filename' => $pdfName, 'mimeType' => 'application/pdf', 'content' => (string)file_get_contents($pdfPath)];
            }
        } finally {
            foreach (glob($workDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($workDir);
        }

        return $result;
    }

    /**
     * @param array<string, true> $used
     */
    private function uniqueName(string $name, array &$used): string {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $candidate = $name;
        for ($i = 2; isset($used[mb_strtolower($candidate)]); $i++) {
            $candidate = "{$base} ({$i}).pdf";
        }
        $used[mb_strtolower($candidate)] = true;
        return $candidate;
    }
}
