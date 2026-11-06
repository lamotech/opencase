<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Service\DigitalPostAlreadySentException;
use OCA\OpenCase\Service\DigitalPostService;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\PermissionService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;

/**
 * REST endpoints for Digital Post.
 *
 * Routes:
 *   GET  /api/v1/digital-post/check?cpr_cvr=<number>
 *        — Check if a CPR/CVR number has an active Digital Post subscription.
 *
 *   POST /api/v1/documents/{documentId}/digital-post/shipments
 *        — Create a Digital Post shipment for a document.
 */
class DigitalPostController extends ApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private DigitalPostService $digitalPostService,
        private PermissionService $permissionService,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    // ---------------------------------------------------------------
    // Digital Post subscription check
    // ---------------------------------------------------------------

    #[NoAdminRequired]
    public function check(): DataResponse {
        $cprCvr = trim((string)$this->request->getParam('cpr_cvr', ''));

        if ($cprCvr === '') {
            return new DataResponse(['error' => 'cpr_cvr is required'], Http::STATUS_BAD_REQUEST);
        }

        if (!$this->digitalPostService->canCheckSubscriptions()) {
            return new DataResponse(
                ['error' => 'Digital Post er ikke aktiveret på denne instans.'],
                Http::STATUS_NOT_IMPLEMENTED,
            );
        }

        try {
            return new DataResponse(['has_subscription' => $this->digitalPostService->hasSubscription($cprCvr)]);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ---------------------------------------------------------------
    // Create shipment
    // ---------------------------------------------------------------

    #[NoAdminRequired]
    public function createShipment(int $documentId): DataResponse {
        if (!$this->digitalPostService->isEnabled()) {
            return new DataResponse(
                ['error' => 'Digital Post er ikke aktiveret på denne instans.'],
                Http::STATUS_NOT_IMPLEMENTED,
            );
        }

        $params = $this->request->getParams();

        try {
            $shipment = $this->digitalPostService->createShipment(
                $documentId,
                $this->userId ?? '',
                (bool)($params['can_be_replied'] ?? true),
                (bool)($params['include_attachments'] ?? false),
                isset($params['main_file_id']) ? (int)$params['main_file_id'] : null,
                is_array($params['receivers'] ?? null) ? $params['receivers'] : [],
            );
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (DigitalPostAlreadySentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        }

        return new DataResponse(['shipment_id' => $shipment->getId()], Http::STATUS_CREATED);
    }

    // ---------------------------------------------------------------
    // Latest shipment status
    // ---------------------------------------------------------------

    #[NoAdminRequired]
    public function latestShipment(int $documentId): DataResponse {
        if (!$this->permissionService->userHasReadAccessToDocument($this->userId ?? '', $documentId)) {
            return new DataResponse(['error' => 'Document not found'], Http::STATUS_NOT_FOUND);
        }

        $latest = $this->digitalPostService->latest($documentId);
        if ($latest === null) {
            return new DataResponse(['shipment' => null]);
        }
        $shipment = $latest['shipment'];

        return new DataResponse([
            'shipment' => [
                'id'                  => $shipment->getId(),
                'status'              => $shipment->getStatus(),
                'can_be_replied'      => $shipment->getCanBeReplied(),
                'include_attachments' => $shipment->getIncludeAttachments(),
                'created_at'          => $shipment->getCreatedAt()?->format('c'),
                'updated_at'          => $shipment->getUpdatedAt()?->format('c'),
            ],
            'files' => array_map(fn($f) => [
                'id'               => $f->getId(),
                'file_name'        => $f->getFileName(),
                'is_main'          => $f->getIsMain(),
                'has_pdf'          => $f->getStorageFilename() !== null,
            ], $latest['files']),
            'receivers' => array_map(fn($r) => [
                'id'            => $r->getId(),
                'receiver_name' => $r->getReceiverName(),
                'cpr_cvr'       => $r->getCprCvr(),
                'status'        => $r->getStatus(),
                'has_pdf'       => $r->getStorageFilename() !== null,
                'message_id'    => $r->getMessageId(),
            ], $latest['receivers']),
        ]);
    }

    // ---------------------------------------------------------------
    // PDF downloads
    // ---------------------------------------------------------------

    #[NoAdminRequired]
    public function downloadShipmentFile(int $id): DataDownloadResponse|DataResponse {
        try {
            $path = $this->digitalPostService->shipmentFilePdf($id, $this->userId ?? '');
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
        return new DataDownloadResponse(file_get_contents($path), basename($path), 'application/pdf');
    }

    #[NoAdminRequired]
    public function downloadReceiverFile(int $id): DataDownloadResponse|DataResponse {
        try {
            $path = $this->digitalPostService->receiverPdf($id, $this->userId ?? '');
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
        return new DataDownloadResponse(file_get_contents($path), basename($path), 'application/pdf');
    }
}
