<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\AccessDecisionMapper;
use OCA\OpenCase\Db\AccessRedactionMapper;
use OCA\OpenCase\Db\AccessRequestItemMapper;
use OCA\OpenCase\Db\AccessRequestMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\FileMapper;
use OCP\Files\IRootFolder;
use OCP\IConfig;

/**
 * Generates the export package for an access request.
 *
 * MVP: zip archive containing:
 *   - 00_afgoerelse.txt     cover letter (decision text)
 *   - 00_aktliste.txt       document index with include/exclude notes
 *   - included files (originals)
 *
 * Text-replacement redactions are applied to plain-text files only.
 * Binary files (PDF, DOCX) are included as-is with a redaction note in the index.
 */
class AccessExportService {

    public function __construct(
        private AccessRequestMapper $requestMapper,
        private AccessRequestItemMapper $itemMapper,
        private AccessRedactionMapper $redactionMapper,
        private AccessDecisionMapper $decisionMapper,
        private DocumentMapper $documentMapper,
        private FileMapper $fileMapper,
        private IRootFolder $rootFolder,
        private FileService $fileService,
        private AuditService $auditService,
        private PermissionService $permissionService,
        private IConfig $config,
    ) {
    }

    /**
     * Build a zip archive and return its path on the server temp dir.
     * Caller is responsible for streaming and deleting the file.
     *
     * The archive bundles every included file on the request, assembled with
     * FileService::downloadInternal() — which deliberately skips per-user
     * checks because it is meant for server-side work. That makes this the one
     * place the caller's access to the underlying case has to be established,
     * or the endpoint is a single-request dump of any case's documents.
     *
     * @throws NotFoundException if the caller cannot read the request's case
     */
    public function buildZip(int $requestId, string $userId): string {
        $req      = $this->requestMapper->findById($requestId);

        if ($userId === '' || !$this->permissionService->userHasReadAccessToCase($userId, $req->getCaseId())) {
            throw new NotFoundException('Access denied');
        }

        $items    = $this->itemMapper->findByRequest($requestId);
        $decision = $this->decisionMapper->findByRequest($requestId);

        $tmpPath = sys_get_temp_dir() . '/opencase_access_' . $requestId . '_' . time() . '.zip';
        $zip     = new \ZipArchive();
        if ($zip->open($tmpPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create zip archive');
        }

        // Cover letter
        $coverLetter = $this->buildCoverLetter($req, $decision);
        $zip->addFromString('00_afgoerelse.txt', $coverLetter);

        // Document index
        $index = $this->buildIndex($req, $items);
        $zip->addFromString('00_aktliste.txt', $index);

        // Included files
        foreach ($items as $item) {
            if (!in_array($item->getDecision(), ['include', 'partly_include'], true)) {
                continue;
            }
            if ($item->getSourceType() !== 'document') {
                continue;
            }
            $this->addDocumentFiles($zip, $requestId, $item->getSourceId(), $item->getId());
        }

        $zip->close();

        $this->auditService->logAccessRequestSent($req->getCaseId(), $userId, $requestId, 'export');

        return $tmpPath;
    }

    private function addDocumentFiles(\ZipArchive $zip, int $requestId, int $documentId, int $itemId): void {
        try {
            $files = $this->fileMapper->findByDocument($documentId);
            foreach ($files as $file) {
                try {
                    $maskedPath = $this->maskedPath($requestId, $itemId, $file->getId(), $file->getOriginalFilename());

                    if (file_exists($maskedPath)) {
                        $content = file_get_contents($maskedPath);
                    } else {
                        $result  = $this->fileService->downloadInternal($file->getId());
                        $stream  = $result['stream'];
                        $content = stream_get_contents($stream);
                        fclose($stream);

                        $redactions = $this->redactionMapper->findByItem($itemId);
                        if (str_starts_with($file->getMimeType() ?? '', 'text/') && !empty($redactions)) {
                            foreach ($redactions as $r) {
                                if ($r->getOriginalText()) {
                                    $content = str_replace(
                                        $r->getOriginalText(),
                                        $r->getReplacementText(),
                                        $content,
                                    );
                                }
                            }
                        }
                    }

                    $filename = $this->safeFilename($documentId, $file->getOriginalFilename());
                    $zip->addFromString($filename, $content);
                } catch (\Throwable) {
                    // Skip unreadable files
                }
            }
        } catch (\Throwable) {
            // Skip missing documents
        }
    }

    private function maskedPath(int $requestId, int $itemId, int $fileId, string $filename): string {
        $safe    = preg_replace('/[^a-zA-Z0-9æøåÆØÅ._\-]/', '_', basename($filename));
        $dataDir = rtrim($this->config->getSystemValueString('datadirectory', ''), '/');
        return "{$dataDir}/opencase_storage/_accessrequests/{$requestId}/{$itemId}_{$fileId}_{$safe}";
    }

    private function buildCoverLetter(\OCA\OpenCase\Db\AccessRequest $req, ?\OCA\OpenCase\Db\AccessDecision $decision): string {
        $lines = [];
        $lines[] = 'AFGØRELSE PÅ AKTINDSIGTSANMODNING';
        $lines[] = str_repeat('=', 50);
        $lines[] = '';
        $lines[] = 'Anmodning modtaget: ' . $req->getReceivedAt()?->format('d-m-Y');
        $lines[] = 'Anmoder:            ' . ($req->getRequesterName() ?? '');
        $lines[] = 'Emne:               ' . $req->getSubject();
        $lines[] = '';

        if ($decision !== null) {
            $lines[] = $decision->getDecisionText();
            $lines[] = '';
            if ($decision->getComplaintGuidance()) {
                $lines[] = 'KLAGEVEJLEDNING';
                $lines[] = $decision->getComplaintGuidance();
            }
        }

        return implode("\n", $lines);
    }

    private function buildIndex(\OCA\OpenCase\Db\AccessRequest $req, array $items): string {
        $lines = [];
        $lines[] = 'AKTLISTE';
        $lines[] = str_repeat('=', 50);
        $lines[] = 'Sag: ' . $req->getSubject();
        $lines[] = '';
        $lines[] = sprintf('%-5s %-50s %-20s %s', 'Nr.', 'Dokument', 'Beslutning', 'Begrundelse');
        $lines[] = str_repeat('-', 100);

        $nr = 1;
        foreach ($items as $item) {
            $decisionLabel = match ($item->getDecision()) {
                'include'       => 'Medtaget',
                'exclude'       => 'Udeladt',
                'partly_include' => 'Delvist medtaget',
                default         => 'Afventer',
            };
            $reason = $item->getExclusionReason() ?? ($item->getLegalReference() ?? '');
            $lines[] = sprintf('%-5d %-50s %-20s %s', $nr++, mb_substr($item->getTitle(), 0, 49), $decisionLabel, $reason);

            $redactions = $this->redactionMapper->findByItem($item->getId());
            foreach ($redactions as $r) {
                $lines[] = sprintf('      → Maskering [%s]: %s', $r->getRedactionType(), $r->getReason() ?? $r->getLegalReference() ?? '');
            }
        }

        return implode("\n", $lines);
    }

    private function safeFilename(int $documentId, string $original): string {
        $safe = preg_replace('/[^a-zA-Z0-9æøåÆØÅ._\-]/', '_', $original);
        return "dok_{$documentId}_{$safe}";
    }
}
