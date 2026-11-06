<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\CaseEntity;
use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\Document;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\DocumentStatusMapper;
use OCA\OpenCase\Db\DpFile;
use OCA\OpenCase\Db\DpFileMapper;
use OCA\OpenCase\Db\DpReceiver;
use OCA\OpenCase\Db\DpReceiverMapper;
use OCA\OpenCase\Db\DpShipment;
use OCA\OpenCase\Db\DpShipmentMapper;
use OCA\OpenCase\Db\FileMapper;
use OCA\OpenCase\Service\Serviceplatformen\PostForespoergClient;
use OCP\IDBConnection;

/**
 * Digital Post shipments of a document, shared by the web API
 * (DigitalPostController), the desktop API and the automatic process step
 * (ProcessWorker).
 *
 * A shipment is queued here (status "Pending") and sent by
 * DispatchDigitalPostJob, which records the result per receiver in the
 * document's audit log. Queuing is recorded too (digital_post_queued).
 */
class DigitalPostService {

    /** Shipment statuses while the job is still working on it. */
    public const IN_PROGRESS_STATUSES = ['Pending', 'Generating PDFs', 'Sending'];

    public function __construct(
        private Configuration $configuration,
        private DocumentMapper $documentMapper,
        private DocumentStatusMapper $documentStatusMapper,
        private CaseMapper $caseMapper,
        private FileMapper $fileMapper,
        private DpShipmentMapper $dpShipmentMapper,
        private DpFileMapper $dpFileMapper,
        private DpReceiverMapper $dpReceiverMapper,
        private DocumentRelationService $documentRelationService,
        private PermissionService $permissionService,
        private AuditService $auditService,
        private IDBConnection $db,
    ) {
    }

    /**
     * Digital Post is enabled with feature_digital-post; the Serviceplatformen
     * clients ship only in the Enterprise package.
     */
    public function isEnabled(): bool {
        return $this->configuration->getConfigValue('feature_digital-post', '0') === '1';
    }

    /**
     * Enabled and the subscription lookup (Enterprise component) installed.
     */
    public function canCheckSubscriptions(): bool {
        return $this->isEnabled() && class_exists(PostForespoergClient::class);
    }

    /**
     * Whether the user may send the document with Digital Post: they can
     * read it, and either may change it or it is final (as on the document page).
     */
    public function canSend(int $documentId, string $userId): bool {
        $document = $this->documentMapper->findById($documentId);
        if ($document === null || $document->isSoftDeleted()
            || !$this->permissionService->userHasReadAccessToDocument($userId, $documentId)) {
            return false;
        }
        $isFinal = (bool)($this->documentStatusMapper->getIsFinalMap()[$document->getStatus()] ?? false);
        return $isFinal || $this->permissionService->userHasWriteAccessToDocument($userId, $documentId);
    }

    /**
     * Whether a CPR/CVR number receives Digital Post; otherwise the
     * shipment is sent as physical post.
     *
     * @throws \InvalidArgumentException when the lookup isn't available, or
     *         Serviceplatformen rejects the request (HTTP 401/403: certificate or
     *         service agreement) — retrying won't help until the setup is fixed
     * @throws \RuntimeException when the lookup fails otherwise
     */
    public function hasSubscription(string $cprCvr): bool {
        if (!$this->canCheckSubscriptions()) {
            throw new \InvalidArgumentException('Digital Post er ikke aktiveret på denne instans.');
        }
        try {
            // Resolved at call time: the class only exists in the Enterprise package
            return (bool)\OC::$server->get(PostForespoergClient::class)->hasDigitalPostSubsciption($cprCvr);
        } catch (\Throwable $e) {
            // PostForespoergClient reports "... failed with HTTP <code>: <body>"
            if (preg_match('/HTTP (401|403)\b/', $e->getMessage(), $m) === 1) {
                throw new \InvalidArgumentException(
                    "Serviceplatformen afviste opslaget af Digital Post (HTTP {$m[1]}). Kontrollér certifikatet og serviceaftalen for PostForespørg under Administration → OpenCase → Serviceplatformen.",
                    0,
                    $e,
                );
            }
            throw new \RuntimeException('Opslag af Digital Post fejlede: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Queue a shipment of the document: all its files (plus the files of
     * its Bilag when $includeAttachments), to the given receivers. A document
     * is sent once; the row lock keeps two requests at once from both passing.
     *
     * @param array{cpr_cvr?: ?string, receiver_name?: ?string, dp_subscription?: bool, document_contact_id?: ?int}[] $receivers
     * @throws NotFoundException when the document or case is missing, or the user may not send it
     * @throws DigitalPostAlreadySentException when the document already has a shipment
     */
    public function createShipment(
        int $documentId,
        string $userId,
        bool $canBeReplied,
        bool $includeAttachments,
        ?int $mainFileId,
        array $receivers,
    ): DpShipment {
        $document = $this->documentMapper->findById($documentId);
        if ($document === null || !$this->canSend($documentId, $userId)) {
            throw new NotFoundException('Document not found');
        }
        $case = $this->caseMapper->findById($document->getCaseId());
        if ($case === null) {
            throw new NotFoundException('Case not found');
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

        $this->db->beginTransaction();
        try {
            $this->db->executeQuery('SELECT `id` FROM `*PREFIX*opencase_documents` WHERE `id` = ? FOR UPDATE', [$documentId])->closeCursor();
            if ($this->dpShipmentMapper->findByDocument($documentId) !== []) {
                throw new DigitalPostAlreadySentException('Dokumentet er allerede sendt med Digital Post.');
            }
            $shipment = $this->insertShipment($document, $case, $userId, $canBeReplied, $includeAttachments, $mainFileId, $receivers);
            $this->db->commit();
            return $shipment;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function insertShipment(
        Document $document,
        CaseEntity $case,
        string $userId,
        bool $canBeReplied,
        bool $includeAttachments,
        ?int $mainFileId,
        array $receivers,
    ): DpShipment {
        $documentId = $document->getId();

        $shipment = new DpShipment();
        $shipment->setDocumentId($documentId);
        $shipment->setCanBeReplied($canBeReplied);
        $shipment->setIncludeAttachments($includeAttachments);
        $shipment->setCaseUuid($case->getUuid());
        $shipment->setDocumentUuid($document->getUuid());
        $shipment->setCreatedAt(new \DateTime());
        $shipment->setUpdatedAt(new \DateTime());
        $shipment->setCreatedBy($userId);
        $shipment->setStatus('Pending');
        $shipment = $this->dpShipmentMapper->insert($shipment);
        $shipmentId = $shipment->getId();

        // All files of the document, plus the files of its attachments
        $files = $this->fileMapper->findByDocument($documentId);
        if ($includeAttachments) {
            foreach ($this->documentRelationService->readableAttachmentIds($documentId, $userId) as $attachmentId) {
                array_push($files, ...$this->fileMapper->findByDocument($attachmentId));
            }
        }

        // The dispatch job writes every additional file as <name>.pdf into
        // one folder, so display names must be unique per shipment.
        $usedNames = [];
        $fileNames = [];
        foreach ($files as $file) {
            $dpFile = new DpFile();
            $dpFile->setDpShipmentId($shipmentId);
            $dpFile->setFileId($file->getId());
            $dpFile->setFileName($this->uniqueFileName($file->getVirtualFilename() ?: $file->getOriginalFilename(), $usedNames));
            $dpFile->setStorageFilename(null);
            $dpFile->setIsMain($mainFileId !== null && $file->getId() === $mainFileId && $file->getDocumentId() === $documentId);
            $this->dpFileMapper->insert($dpFile);
            $fileNames[] = $dpFile->getFileName();
        }

        $documentTitle = $document->getTitle();
        $receiverNames = [];
        foreach ($receivers as $r) {
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
            $receiverNames[] = (string)($r['receiver_name'] ?? '');
        }

        $this->auditService->logDigitalPostQueued($case->getId(), $documentId, $userId, $shipmentId, $receiverNames, $fileNames);

        return $shipment;
    }

    /**
     * The document's latest shipment with its files and receivers, or null.
     *
     * @return array{shipment: DpShipment, files: DpFile[], receivers: DpReceiver[]}|null
     */
    public function latest(int $documentId): ?array {
        $shipments = $this->dpShipmentMapper->findByDocument($documentId);
        if (empty($shipments)) {
            return null;
        }
        $shipment = $shipments[0]; // ordered newest first
        return [
            'shipment'  => $shipment,
            'files'     => $this->dpFileMapper->findByShipment($shipment->getId()),
            'receivers' => $this->dpReceiverMapper->findByShipment($shipment->getId()),
        ];
    }

    /**
     * Path of a generated PDF of a shipment file (is_main = false).
     *
     * @throws NotFoundException when missing, or the user can't read the document
     */
    public function shipmentFilePdf(int $dpFileId, string $userId): string {
        $dpFile = $this->dpFileMapper->findById($dpFileId);
        if ($dpFile === null) {
            throw new NotFoundException('File not found');
        }
        return $this->readablePdf($dpFile->getDpShipmentId(), $dpFile->getStorageFilename(), $userId);
    }

    /**
     * Path of the generated PDF of a receiver's main document.
     *
     * @throws NotFoundException when missing, or the user can't read the document
     */
    public function receiverPdf(int $dpReceiverId, string $userId): string {
        $receiver = $this->dpReceiverMapper->findById($dpReceiverId);
        if ($receiver === null) {
            throw new NotFoundException('File not found');
        }
        return $this->readablePdf($receiver->getDpShipmentId(), $receiver->getStorageFilename(), $userId);
    }

    private function readablePdf(int $shipmentId, ?string $path, string $userId): string {
        $shipment = $this->dpShipmentMapper->findById($shipmentId);
        if ($shipment === null || $path === null
            || !$this->permissionService->userHasReadAccessToDocument($userId, $shipment->getDocumentId())) {
            throw new NotFoundException('File not found');
        }
        if (!file_exists($path)) {
            throw new NotFoundException('PDF not found on disk');
        }
        return $path;
    }

    /**
     * Return $name, or "<base> (2).<ext>", "<base> (3).<ext>", … if the base
     * name (case-insensitive, extension ignored) is already in $used.
     *
     * @param array<string, true> $used
     */
    private function uniqueFileName(string $name, array &$used): string {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $ext  = pathinfo($name, PATHINFO_EXTENSION);
        $candidate = $name;
        for ($i = 2; isset($used[mb_strtolower(pathinfo($candidate, PATHINFO_FILENAME))]); $i++) {
            $candidate = "{$base} ({$i})" . ($ext !== '' ? ".{$ext}" : '');
        }
        $used[mb_strtolower(pathinfo($candidate, PATHINFO_FILENAME))] = true;
        return $candidate;
    }
}
