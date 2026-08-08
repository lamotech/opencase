<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\DpFile;
use OCA\OpenCase\Db\DpFileMapper;
use OCA\OpenCase\Db\DpReceiver;
use OCA\OpenCase\Db\DpReceiverMapper;
use OCA\OpenCase\Db\DpShipment;
use OCA\OpenCase\Db\DpShipmentMapper;
use OCA\OpenCase\Db\FileMapper;
use OCA\OpenCase\Service\CaseService;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\Serviceplatformen\PostForespoergClient;
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
        private Configuration $configuration,
        private DocumentMapper $documentMapper,
        private CaseMapper $caseMapper,
        private FileMapper $fileMapper,
        private DpShipmentMapper $dpShipmentMapper,
        private DpFileMapper $dpFileMapper,
        private DpReceiverMapper $dpReceiverMapper,
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

        // PostForespoergClient is an Enterprise-only component (not present
        // in the public App Store package) — guard against the class simply
        // not being present on disk, rather than injecting it directly in
        // the constructor (which would break every route on this controller
        // if the class doesn't exist).
        if ($this->configuration->getConfigValue('enterprise_version', '0') !== '1' || !class_exists(PostForespoergClient::class)) {
            return new DataResponse(
                ['error' => 'Digital Post-abonnementstjek er en Enterprise-funktion og er ikke aktiveret på denne instans.'],
                Http::STATUS_NOT_IMPLEMENTED,
            );
        }

        try {
            $postForespoergClient = \OC::$server->get(PostForespoergClient::class);
            $hasSubscription = $postForespoergClient->hasDigitalPostSubsciption($cprCvr);
            return new DataResponse(['has_subscription' => $hasSubscription]);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ---------------------------------------------------------------
    // Create shipment
    // ---------------------------------------------------------------

    #[NoAdminRequired]
    public function createShipment(int $documentId): DataResponse {
        $params = $this->request->getParams();

        $document = $this->documentMapper->findById($documentId);
        if ($document === null) {
            return new DataResponse(['error' => 'Document not found'], Http::STATUS_NOT_FOUND);
        }

        $case = $this->caseMapper->findById($document->getCaseId());
        if ($case === null) {
            return new DataResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
        }

        // Ensure both case and document have UUIDs (lazy generation for old rows)
        if ($case->getUuid() === null) {
            $case->setUuid(CaseService::generateUuid());
            $this->caseMapper->update($case);
        }
        if ($document->getUuid() === null) {
            $document->setUuid(CaseService::generateUuid());
            $this->documentMapper->update($document);
        }

        $canBeReplied = (bool)($params['can_be_replied'] ?? true);
        $mainFileId   = isset($params['main_file_id']) ? (int)$params['main_file_id'] : null;
        $receiversIn  = is_array($params['receivers'] ?? null) ? $params['receivers'] : [];

        // --- Create shipment record ---
        $shipment = new DpShipment();
        $shipment->setDocumentId($documentId);
        $shipment->setCanBeReplied($canBeReplied);
        $shipment->setCaseUuid($case->getUuid());
        $shipment->setDocumentUuid($document->getUuid());
        $shipment->setCreatedAt(new \DateTime());
        $shipment->setUpdatedAt(new \DateTime());
        $shipment->setCreatedBy($this->userId ?? '');
        $shipment->setStatus('Pending');

        $shipment = $this->dpShipmentMapper->insert($shipment);
        $shipmentId = $shipment->getId();

        // --- Create dp_files records (all files for the document) ---
        $files = $this->fileMapper->findByDocument($documentId);
        foreach ($files as $file) {
            $dpFile = new DpFile();
            $dpFile->setDpShipmentId($shipmentId);
            $dpFile->setFileId($file->getId());
            $dpFile->setFileName($file->getVirtualFilename() ?: $file->getOriginalFilename());
            $dpFile->setStorageFilename(null);
            $dpFile->setIsMain($mainFileId !== null && $file->getId() === $mainFileId);
            $this->dpFileMapper->insert($dpFile);
        }

        // --- Create dp_receivers records ---
        $documentTitle = $document->getTitle();
        foreach ($receiversIn as $r) {
            $dpReceiver = new DpReceiver();
            $dpReceiver->setDpShipmentId($shipmentId);
            $dpReceiver->setTitle($documentTitle);
            $dpReceiver->setCprCvr($r['cpr_cvr'] ?? null);
            $dpReceiver->setReceiverName($r['receiver_name'] ?? null);
            $dpReceiver->setDpSubscription((bool)($r['dp_subscription'] ?? false));
            $dpReceiver->setDocumentContactId(isset($r['document_contact_id']) ? (int)$r['document_contact_id'] : null);
            $dpReceiver->setStorageFilename(null);
            $dpReceiver->setMessageId(null);
            $dpReceiver->setMessageCreatedAt(null);
            $dpReceiver->setStatus('Pending');
            $dpReceiver->setMessageAdvisId(null);
            $dpReceiver->setMessageAdvisTekst(null);
            $this->dpReceiverMapper->insert($dpReceiver);
        }

        return new DataResponse(['shipment_id' => $shipmentId], Http::STATUS_CREATED);
    }

    // ---------------------------------------------------------------
    // Latest shipment status
    // ---------------------------------------------------------------

    #[NoAdminRequired]
    public function latestShipment(int $documentId): DataResponse {
        $shipments = $this->dpShipmentMapper->findByDocument($documentId);
        if (empty($shipments)) {
            return new DataResponse(['shipment' => null]);
        }

        $shipment   = $shipments[0]; // already ordered DESC by id
        $shipmentId = $shipment->getId();

        $files     = $this->dpFileMapper->findByShipment($shipmentId);
        $receivers = $this->dpReceiverMapper->findByShipment($shipmentId);

        return new DataResponse([
            'shipment' => [
                'id'             => $shipmentId,
                'status'         => $shipment->getStatus(),
                'can_be_replied' => $shipment->getCanBeReplied(),
                'created_at'     => $shipment->getCreatedAt()?->format('c'),
                'updated_at'     => $shipment->getUpdatedAt()?->format('c'),
            ],
            'files' => array_map(fn($f) => [
                'id'               => $f->getId(),
                'file_name'        => $f->getFileName(),
                'is_main'          => $f->getIsMain(),
                'has_pdf'          => $f->getStorageFilename() !== null,
            ], $files),
            'receivers' => array_map(fn($r) => [
                'id'            => $r->getId(),
                'receiver_name' => $r->getReceiverName(),
                'cpr_cvr'       => $r->getCprCvr(),
                'status'        => $r->getStatus(),
                'has_pdf'       => $r->getStorageFilename() !== null,
                'message_id'    => $r->getMessageId(),
            ], $receivers),
        ]);
    }

    // ---------------------------------------------------------------
    // PDF downloads
    // ---------------------------------------------------------------

    #[NoAdminRequired]
    public function downloadShipmentFile(int $id): DataDownloadResponse|DataResponse {
        $dpFile = $this->dpFileMapper->findById($id);
        if ($dpFile === null || $dpFile->getStorageFilename() === null) {
            return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
        }
        $path = $dpFile->getStorageFilename();
        if (!file_exists($path)) {
            return new DataResponse(['error' => 'PDF not found on disk'], Http::STATUS_NOT_FOUND);
        }
        return new DataDownloadResponse(
            file_get_contents($path),
            basename($path),
            'application/pdf'
        );
    }

    #[NoAdminRequired]
    public function downloadReceiverFile(int $id): DataDownloadResponse|DataResponse {
        $dpReceiver = $this->dpReceiverMapper->findById($id);
        if ($dpReceiver === null || $dpReceiver->getStorageFilename() === null) {
            return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
        }
        $path = $dpReceiver->getStorageFilename();
        if (!file_exists($path)) {
            return new DataResponse(['error' => 'PDF not found on disk'], Http::STATUS_NOT_FOUND);
        }
        return new DataDownloadResponse(
            file_get_contents($path),
            basename($path),
            'application/pdf'
        );
    }
}
