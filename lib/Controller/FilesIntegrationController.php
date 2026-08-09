<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Service\DocumentService;
use OCA\OpenCase\Service\FileService;
use OCA\OpenCase\Service\NotFoundException;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\IRequest;

/**
 * Handles saving a Nextcloud Files file into an OpenCase document.
 *
 * Routes:
 *   POST /api/v1/files/save-to-case — Create a document + copy a NC file into it
 */
class FilesIntegrationController extends ApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private IRootFolder $rootFolder,
        private DocumentService $documentService,
        private FileService $fileService,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Save a Nextcloud file as a new document inside an OpenCase case.
     *
     * Request body (JSON):
     *   fileId             — Required. Nextcloud file ID (node ID from the filecache).
     *   caseId             — Required. ID of the target case.
     *   documentCategoryId — Required. Document category ID.
     *   title              — Optional. Document title (defaults to filename without extension).
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/files/save-to-case')]
    public function saveToCase(): DataResponse {
        $params = $this->request->getParams();
        $fileId             = (int)($params['fileId'] ?? 0);
        $caseId             = (int)($params['caseId'] ?? 0);
        $documentCategoryId = isset($params['documentCategoryId']) && $params['documentCategoryId'] !== ''
            ? (int)$params['documentCategoryId']
            : null;
        $title  = trim($params['title'] ?? '');

        if ($fileId <= 0 || $caseId <= 0 || $documentCategoryId === null) {
            return new DataResponse(
                ['error' => "Fields 'fileId', 'caseId' and 'documentCategoryId' are required"],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($this->userId);
            $nodes = $userFolder->getById($fileId);

            if (empty($nodes)) {
                return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
            }

            $node = $nodes[0];
            $filename = $node->getName();
            $mimeType = $node->getMimeType();

            if ($title === '') {
                $title = pathinfo($filename, PATHINFO_FILENAME);
            }

            $document = $this->documentService->create(
                $caseId,
                $title,
                'document',
                $this->userId,
                documentCategoryId: $documentCategoryId,
            );

            $stream = $node->fopen('r');

            $file = $this->fileService->upload(
                $document->getId(),
                $filename,
                $mimeType,
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
        } catch (FilesNotFoundException $e) {
            return new DataResponse(
                ['error' => 'File not found'],
                Http::STATUS_NOT_FOUND
            );
        }
    }
}
