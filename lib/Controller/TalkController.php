<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Service\DocumentService;
use OCA\OpenCase\Service\FileService;
use OCA\OpenCase\Service\LibreOfficeService;
use OCA\OpenCase\Service\NotFoundException;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Handles saving a Nextcloud Talk chat transcript into an OpenCase document.
 *
 * Routes:
 *   POST /api/v1/talk/save-chat — Create a document + text file from a chat transcript
 */
class TalkController extends ApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private DocumentService $documentService,
        private FileService $fileService,
        private LibreOfficeService $libreOfficeService,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Save a chat transcript as a new document inside an OpenCase case.
     *
     * Request body (JSON):
     *   caseId             — Required. ID of the target case.
     *   documentCategoryId — Required. Document category ID.
     *   title              — Required. Document title (e.g. "Chat – Room (2026-03-22)").
     *   content            — Required. Plain-text transcript body.
     *   filename           — Optional. Filename for the attachment (default: chat.txt).
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/talk/save-chat')]
    public function saveChat(): DataResponse {
        $params             = $this->request->getParams();
        $caseId             = (int)($params['caseId'] ?? 0);
        $documentCategoryId = isset($params['documentCategoryId']) && $params['documentCategoryId'] !== ''
            ? (int)$params['documentCategoryId']
            : null;
        $title    = trim($params['title'] ?? '');
        $content  = $params['content'] ?? '';
        $filename = trim($params['filename'] ?? '') ?: 'chat.txt';

        if ($caseId <= 0 || $documentCategoryId === null || $title === '') {
            return new DataResponse(
                ['error' => "Fields 'caseId', 'documentCategoryId' and 'title' are required"],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $document = $this->documentService->create(
                $caseId,
                $title,
                'chat',
                $this->userId,
                documentCategoryId: $documentCategoryId,
            );

            /*
            $pdfFilename = pathinfo($filename, PATHINFO_FILENAME) . '.pdf';
            $pdfPath     = $this->convertHtmlToPdf($content);

            try {
                $stream = fopen($pdfPath, 'rb');

                $file = $this->fileService->upload(
                    $document->getId(),
                    $pdfFilename,
                    'application/pdf',
                    $stream,
                    $this->userId,
                );

                fclose($stream);
            } finally {
                @unlink($pdfPath);
            }
                */

            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $content);
            rewind($stream);

            $file = $this->fileService->upload(
                $document->getId(),
                $filename,
                'text/html',
                $stream,
                $this->userId,
            );

            fclose($stream);

            return new DataResponse(
                ['document_id' => $document->getId(), 'file_id' => $file->getId()],
                Http::STATUS_CREATED
            );
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        } catch (NotFoundException $e) {
            return new DataResponse(
                ['error' => 'Case not found or access denied'],
                Http::STATUS_NOT_FOUND
            );
        } catch (\RuntimeException $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Convert an HTML chat transcript to PDF using LibreOffice headless.
     *
     * @return string Path to the generated PDF file (caller is responsible for deleting it).
     */
    private function convertHtmlToPdf(string $html): string {
        $tmpDir = sys_get_temp_dir() . '/opencase_talk_' . bin2hex(random_bytes(8));
        mkdir($tmpDir, 0700, true);

        try {
            $htmlPath = $tmpDir . '/chat.html';
            file_put_contents($htmlPath, $html);

            $pdfPath = $this->libreOfficeService->convertToPdf($htmlPath, $tmpDir);

            $finalPath = sys_get_temp_dir() . '/opencase_talk_' . bin2hex(random_bytes(8)) . '.pdf';
            rename($pdfPath, $finalPath);

            return $finalPath;
        } finally {
            @unlink($tmpDir . '/chat.html');
            @rmdir($tmpDir);
        }
    }
}
