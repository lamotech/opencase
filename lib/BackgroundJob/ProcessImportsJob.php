<?php

declare(strict_types=1);

namespace OCA\OpenCase\BackgroundJob;

use OCA\OpenCase\Db\AccessProfileMapper;
use OCA\OpenCase\Db\CaseEntity;
use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\ClassificationFacetRepository;
use OCA\OpenCase\Db\ClassificationSubjectMapper;
use OCA\OpenCase\Db\ConfigMapper;
use OCA\OpenCase\Db\Document;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\FileEntity;
use OCA\OpenCase\Db\FileMapper;
use OCA\OpenCase\Db\ImportItemRepository;
use OCA\OpenCase\Db\ImportLocationRepository;
use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Db\SensitivityMapper;
use OCA\OpenCase\Db\SeparationSheet;
use OCA\OpenCase\Db\SeparationSheetMapper;
use OCA\OpenCase\Service\CaseNumberService;
use OCA\OpenCase\Service\CaseService;
use OCA\OpenCase\Service\PrivilegeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use setasign\Fpdi\Fpdi;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;
use Zxing\QrReader;

/**
 * Processes configured import locations (basic/community edition).
 *
 * For each row in oc_opencase_importlocations with type="folder" and expired=0:
 *  1. Scans folderpath for files matching file_extension_filter (all files if empty).
 *  2. Moves each matching file to the "processing" subfolder and registers it in
 *     oc_opencase_importitems (identification = full file path, status "Processing").
 *  3. For PDF files: renders every page and looks for a QR code matching a
 *     known separation sheet (Separationsark). A separation sheet of type
 *     "existing case"/"new case"/"inbox case" starts a new document on the
 *     resolved/created case; the pages that follow (up to the next marker)
 *     become that document's first file. A separation sheet of type
 *     "attachment" starts a new file on the CURRENT document instead. A PDF
 *     with no recognised markers — and any non-PDF file — is imported as a
 *     single document/file on the system inbox case (same target as
 *     ProcessDigitalPostReceivementsJob).
 *  4. Moves the file to the "imported" subfolder and sets status "Processed"
 *     (or "Failed" on error).
 *
 * For each row with type="mailbox" and expired=0: connects over IMAP,
 * looks at unseen messages in INBOX, and imports each attachment matching
 * file_extension_filter the same way as a matching file in a folder
 * location (including PDF/QR separation-sheet splitting). Already-imported
 * attachments (same location + message-id + filename) are skipped on
 * re-scans. Every unseen message is flagged \Seen once considered,
 * regardless of whether any attachment matched.
 */
class ProcessImportsJob extends TimedJob {

    private const DOCUMENT_CATEGORY_INBOUND = 1;
    private const DEFAULT_KLE_CODE = '00.01.00';

    private string $baseDir;
    private string $inboxOrganisationUuid;
    private string $inboxSensitivity;
    private string $inboxKleCode;
    private string $inboxFacet;
    private int    $inboxInsightLevel;

    public function __construct(
        ITimeFactory $time,
        private ImportLocationRepository $importLocationRepository,
        private ImportItemRepository $importItemRepository,
        private SeparationSheetMapper $separationSheetMapper,
        private CaseMapper $caseMapper,
        private DocumentMapper $documentMapper,
        private FileMapper $fileMapper,
        private AccessProfileMapper $accessProfileMapper,
        private ClassificationSubjectMapper $classificationSubjectMapper,
        private ClassificationFacetRepository $classificationFacetRepository,
        private SensitivityMapper $sensitivityMapper,
        private OrganisationMapper $organisationMapper,
        private CaseNumberService $caseNumberService,
        private ConfigMapper $configMapper,
        private IConfig $config,
        private PrivilegeService $privilegeService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(5 * 60);

        $dataDir       = rtrim($config->getSystemValueString('datadirectory', ''), '/');
        $defaultBase   = $dataDir . '/opencase_storage';
        $this->baseDir = rtrim($config->getAppValue('opencase', 'storage_base_dir', $defaultBase), '/');

        $this->inboxOrganisationUuid = $configMapper->get('incoming_organisation', '');
        $this->inboxSensitivity      = $configMapper->get('incoming_sensitivity', '31c09910-e011-46a5-86fb-254374421fe8');
        $this->inboxKleCode          = $configMapper->get('incoming_kle', self::DEFAULT_KLE_CODE);
        $this->inboxFacet            = $configMapper->get('incoming_facet', '');
        $this->inboxInsightLevel     = (int)$configMapper->get('incoming_insight_level', '3');
    }

    // ---------------------------------------------------------------
    // Entry point
    // ---------------------------------------------------------------

    protected function run($argument): void {
        $folderLocations = $this->importLocationRepository->findActiveByType('folder');
        foreach ($folderLocations as $location) {
            $this->processLocation($location);
        }

        $mailboxLocations = $this->importLocationRepository->findActiveByType('mailbox');
        foreach ($mailboxLocations as $location) {
            $this->processMailboxLocation($location);
        }
    }

    // ---------------------------------------------------------------
    // Per-location processing
    // ---------------------------------------------------------------

    private function processLocation(array $location): void {
        $folderPath = rtrim((string)($location['folderpath'] ?? ''), '/');
        if ($folderPath === '' || !is_dir($folderPath)) {
            $this->logger->warning(
                "ProcessImportsJob: import location {$location['id']} — folder not found: {$folderPath}",
                ['app' => 'opencase']
            );
            return;
        }

        $extensions = $this->parseExtensions((string)($location['file_extension_filter'] ?? ''));
        $files      = $this->findMatchingFiles($folderPath, $extensions);

        foreach ($files as $filePath) {
            $this->processFile((int)$location['id'], $folderPath, $filePath);
        }
    }

    private function parseExtensions(string $filter): array {
        if (trim($filter) === '') {
            return [];
        }
        $parts = preg_split('/[,;\s]+/', strtolower($filter), -1, PREG_SPLIT_NO_EMPTY);
        return array_map(static fn(string $e) => ltrim($e, '.'), $parts);
    }

    private function findMatchingFiles(string $folderPath, array $extensions): array {
        $entries = scandir($folderPath);
        if ($entries === false) {
            return [];
        }

        $matches = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $fullPath = $folderPath . '/' . $entry;
            if (!is_file($fullPath)) {
                continue;
            }
            if (!empty($extensions)) {
                $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (!in_array($ext, $extensions, true)) {
                    continue;
                }
            }
            $matches[] = $fullPath;
        }
        sort($matches);
        return $matches;
    }

    // ---------------------------------------------------------------
    // Per-mailbox-location processing
    // ---------------------------------------------------------------

    private function processMailboxLocation(array $location): void {
        $locationId = (int)$location['id'];
        $server     = trim((string)($location['mailbox_server'] ?? ''));
        $user       = trim((string)($location['mailbox_user'] ?? ''));

        if ($server === '' || $user === '') {
            $this->logger->warning(
                "ProcessImportsJob: mailbox location {$locationId} — missing server/user",
                ['app' => 'opencase']
            );
            return;
        }

        $port     = (int)($location['mailbox_port'] ?? 993) ?: 993;
        $password = (string)($location['mailbox_password'] ?? '');
        $useSsl   = (bool)((int)($location['mailbox_use_ssl'] ?? 1));

        // Port 993 is implicit TLS (encrypted from the first byte); any other
        // port (typically 143) with encryption requested means STARTTLS —
        // connect in plaintext, then upgrade. Sending an implicit-TLS
        // handshake to a plaintext port (or vice versa) fails the connection.
        $encryption = $useSsl ? ($port === 993 ? 'ssl' : 'starttls') : false;

        $extensions = $this->parseExtensions((string)($location['file_extension_filter'] ?? ''));

        try {
            $clientManager = new ClientManager();
            $client = $clientManager->make([
                'host'           => $server,
                'port'           => $port,
                'protocol'       => 'imap',
                'encryption'     => $encryption,
                'validate_cert'  => true,
                'username'       => $user,
                'password'       => $password,
            ]);
            $client->connect();

            try {
                $folder = $client->getFolder('INBOX');
                if ($folder === null) {
                    throw new \RuntimeException('INBOX folder not found');
                }

                $messages = $folder->messages()->whereUnseen()->get();

                foreach ($messages as $message) {
                    $this->processMailboxMessage($locationId, $message, $extensions);
                    try {
                        $message->setFlag('Seen');
                    } catch (\Throwable $e) {
                        $this->logger->warning(
                            "ProcessImportsJob: mailbox location {$locationId} — failed to mark message as seen: {$e->getMessage()}",
                            ['app' => 'opencase']
                        );
                    }
                }
            } finally {
                $client->disconnect();
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                "ProcessImportsJob: mailbox location {$locationId} failed — {$e->getMessage()}",
                ['app' => 'opencase', 'exception' => $e]
            );
        }
    }

    /**
     * Imports the attachments of a single email that match the location's
     * extension filter — one importitems row + document/file per attachment,
     * created the same way as a matching file found in a folder location.
     * Already-imported attachments (same location + message-id + filename)
     * are skipped, since unlike folder files, messages aren't moved out of
     * the mailbox once processed — only flagged \Seen.
     */
    private function processMailboxMessage(int $locationId, Message $message, array $extensions): void {
        // Attribute::first() returns false (not null) when the header is
        // missing, so a plain ?? fallback would not catch it — check explicitly.
        $rawMessageId = $message->message_id->first();
        $messageId    = (!empty($rawMessageId)) ? (string)$rawMessageId : (string)$message->getUid();

        foreach ($message->getAttachments() as $attachment) {
            $filename = (string)$attachment->getName();
            if ($filename === '') {
                continue;
            }
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (!empty($extensions) && !in_array($ext, $extensions, true)) {
                continue;
            }

            $identification = "mailbox:{$locationId}:{$messageId}:{$filename}";
            if ($this->importItemRepository->existsForLocation($locationId, $identification)) {
                continue;
            }

            $tmpPath = sys_get_temp_dir() . '/opencase_mail_' . bin2hex(random_bytes(8)) . '_' . $filename;

            $itemId = $this->importItemRepository->insert($locationId, $identification, 'Processing');

            try {
                if (file_put_contents($tmpPath, $attachment->getContent()) === false) {
                    throw new \RuntimeException("Failed to write mailbox attachment to temp file: {$tmpPath}");
                }

                if ($ext === 'pdf') {
                    $stats = $this->importPdf($itemId, $filename, $identification, $tmpPath);
                } else {
                    $stats = $this->importWholeFile($itemId, $filename, $identification, $tmpPath);
                }
                $this->importItemRepository->setFileStats($itemId, $this->buildStatsXml($stats));

                $this->importItemRepository->updateStatus($itemId, 'Processed');

            } catch (\Throwable $e) {
                $this->importItemRepository->updateStatus($itemId, 'Failed');
                $this->logger->error(
                    "ProcessImportsJob: failed to import mailbox attachment {$identification} — {$e->getMessage()}",
                    ['app' => 'opencase', 'exception' => $e]
                );
            } finally {
                if (file_exists($tmpPath)) {
                    @unlink($tmpPath);
                }
            }
        }
    }

    // ---------------------------------------------------------------
    // Per-file processing
    // ---------------------------------------------------------------

    private function processFile(int $locationId, string $folderPath, string $filePath): void {
        $originalFilename = basename($filePath);
        $identification    = $filePath;

        $processingDir = $folderPath . '/processing';
        if (!is_dir($processingDir) && !mkdir($processingDir, 0770, true) && !is_dir($processingDir)) {
            $this->logger->error(
                "ProcessImportsJob: failed to create processing dir — {$processingDir}",
                ['app' => 'opencase']
            );
            return;
        }

        $processingPath = $processingDir . '/' . $originalFilename;
        if (!rename($filePath, $processingPath)) {
            $this->logger->error(
                "ProcessImportsJob: failed to move file to processing — {$filePath}",
                ['app' => 'opencase']
            );
            return;
        }

        $itemId = $this->importItemRepository->insert($locationId, $identification, 'Processing');

        try {
            $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
            if ($extension === 'pdf') {
                $stats = $this->importPdf($itemId, $originalFilename, $identification, $processingPath);
            } else {
                $stats = $this->importWholeFile($itemId, $originalFilename, $identification, $processingPath);
            }
            $this->importItemRepository->setFileStats($itemId, $this->buildStatsXml($stats));

            $this->moveToImported($folderPath, $processingPath, $originalFilename);
            $this->importItemRepository->updateStatus($itemId, 'Processed');

        } catch (\Throwable $e) {
            $this->importItemRepository->updateStatus($itemId, 'Failed');
            $this->logger->error(
                "ProcessImportsJob: failed to import {$identification} — {$e->getMessage()}",
                ['app' => 'opencase', 'exception' => $e]
            );
        }
    }

    private function moveToImported(string $folderPath, string $processingPath, string $originalFilename): void {
        $importedDir = $folderPath . '/imported';
        if (!is_dir($importedDir) && !mkdir($importedDir, 0770, true) && !is_dir($importedDir)) {
            throw new \RuntimeException("Failed to create imported dir: {$importedDir}");
        }

        $importedPath = $importedDir . '/' . $originalFilename;
        if (!rename($processingPath, $importedPath)) {
            throw new \RuntimeException("Failed to move file to imported: {$processingPath}");
        }
    }

    private function detectMimeType(string $path): string {
        $mimeType = @mime_content_type($path);
        return $mimeType !== false ? $mimeType : 'application/octet-stream';
    }

    // ---------------------------------------------------------------
    // Whole-file import (non-PDF, or a PDF with no separation-sheet markers)
    // ---------------------------------------------------------------

    /**
     * @return array{documents: array<int, array{id: int, case_id: int, separation_sheet: ?string, separation_sheet_type: ?string, files: array<int, array{id: int, pages: ?int}>}>}
     */
    private function importWholeFile(int $itemId, string $originalFilename, string $identification, string $processingPath): array {
        $content = file_get_contents($processingPath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read file: {$processingPath}");
        }

        $case     = $this->getOrCreateInboxCase();
        $document = $this->createDocument($case, $this->buildImportedTitle($originalFilename), $this->inboxInsightLevel);
        $this->importItemRepository->setDocumentId($itemId, $document->getId());
        $file = $this->storeFile($case, $document, $originalFilename, $content, $this->detectMimeType($processingPath));

        $this->logger->info(
            "ProcessImportsJob: imported {$identification} → document {$document->getId()} on case {$case->getId()}",
            ['app' => 'opencase']
        );

        $pages = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION)) === 'pdf'
            ? $this->safeCountPdfPages($processingPath)
            : null;

        return [
            'documents' => [[
                'id'                     => $document->getId(),
                'case_id'                => $case->getId(),
                'separation_sheet'       => null,
                'separation_sheet_type'  => null,
                'files'                  => [['id' => $file->getId(), 'pages' => $pages]],
            ]],
        ];
    }

    // ---------------------------------------------------------------
    // PDF import with separation-sheet (QR) page splitting
    // ---------------------------------------------------------------

    /**
     * @return array{documents: array<int, array{id: int, case_id: int, separation_sheet: ?string, separation_sheet_type: ?string, files: array<int, array{id: int, pages: ?int}>}>}
     * @throws \Throwable
     */
    private function importPdf(int $itemId, string $originalFilename, string $identification, string $processingPath): array {
        $pageCount = $this->countPdfPages($processingPath);

        /** @var array<int, SeparationSheet> $markers */
        $markers = [];
        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $qrText = $this->detectQrOnPage($processingPath, $pageNo);
            if ($qrText === null) {
                continue;
            }
            $sheet = $this->separationSheetMapper->findByName($qrText);
            if ($sheet !== null) {
                $markers[$pageNo] = $sheet;
            }
        }

        if (empty($markers)) {
            // No separation-sheet QR codes found — import the whole PDF as a
            // single document/file on the inbox case, same as any other file.
            return $this->importWholeFile($itemId, $originalFilename, $identification, $processingPath);
        }

        /** @var ?CaseEntity $currentCase */
        $currentCase = null;
        /** @var ?Document $currentDocument */
        $currentDocument = null;
        $pendingPages     = [];
        $fileIndex        = 0;
        $documentIds      = [];
        /** @var array<int, array{id: int, case_id: int, separation_sheet: ?string, separation_sheet_type: ?string, files: array<int, array{id: int, pages: ?int}>}> $documentsStats */
        $documentsStats = [];

        $flush = function () use (&$pendingPages, &$currentCase, &$currentDocument, &$fileIndex, &$documentsStats, $processingPath, $originalFilename, $identification): void {
            if (empty($pendingPages)) {
                return;
            }
            if ($currentDocument === null || $currentCase === null) {
                $this->logger->warning(
                    "ProcessImportsJob: {$identification} — dropping page(s) " . implode(',', $pendingPages) . ' with no active document',
                    ['app' => 'opencase']
                );
                $pendingPages = [];
                return;
            }

            $fileIndex++;
            $pageCountForFile = count($pendingPages);
            $pdfContent       = $this->extractPdfPages($processingPath, $pendingPages);
            $fileName         = sprintf('%s-%d.pdf', pathinfo($originalFilename, PATHINFO_FILENAME), $fileIndex);
            $file             = $this->storeFile($currentCase, $currentDocument, $fileName, $pdfContent, 'application/pdf');

            $lastIndex = count($documentsStats) - 1;
            $documentsStats[$lastIndex]['files'][] = ['id' => $file->getId(), 'pages' => $pageCountForFile];

            $pendingPages = [];
        };

        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            if (isset($markers[$pageNo])) {
                $flush();
                $sheet = $markers[$pageNo];

                if ($sheet->getType() === 'attachment') {
                    // Starts a new file on the CURRENT document — case/document unchanged.
                    if ($currentDocument === null) {
                        $this->logger->warning(
                            "ProcessImportsJob: {$identification} — 'attachment' marker on page {$pageNo} with no active document",
                            ['app' => 'opencase']
                        );
                    }
                    continue;
                }

                // "existing case" / "new case" / "inbox case" — starts a new document.
                $currentCase     = $this->resolveCaseForMarker($sheet);
                $currentDocument = $this->createDocument(
                    $currentCase,
                    $this->buildImportedTitle($originalFilename),
                    $currentCase->getInsightLevelId(),
                );
                $documentIds[]    = $currentDocument->getId();
                $documentsStats[] = [
                    'id'                    => $currentDocument->getId(),
                    'case_id'               => $currentCase->getId(),
                    'separation_sheet'      => $sheet->getName(),
                    'separation_sheet_type' => $sheet->getType(),
                    'files'                 => [],
                ];
                $fileIndex = 0;
                continue;
            }

            $pendingPages[] = $pageNo;
        }
        $flush();

        if (empty($documentIds)) {
            throw new \RuntimeException('No document could be created from separation sheet markers');
        }

        // The import item can only reference a single document — set it when
        // the markers produced exactly one (the common case). With multiple
        // documents there is no single value to store, so it stays null.
        if (count($documentIds) === 1) {
            $this->importItemRepository->setDocumentId($itemId, $documentIds[0]);
        }

        $this->logger->info(
            "ProcessImportsJob: imported {$identification} → documents [" . implode(', ', $documentIds) . '] via separation sheets',
            ['app' => 'opencase']
        );

        return ['documents' => $documentsStats];
    }

    private function resolveCaseForMarker(SeparationSheet $sheet): CaseEntity {
        return match ($sheet->getType()) {
            'existing case' => $this->resolveExistingCase($sheet),
            'new case'      => $this->createNewCaseFromSheet($sheet),
            'inbox case'    => $this->getOrCreateInboxCaseForOrg(
                $sheet->getOrgUuid(),
                $sheet->getClassSubjectUuid(),
                $sheet->getSensitivityUuid(),
                $sheet->getClassificationFacetUuid(),
                $sheet->getInsightLevelId(),
            ),
            default => throw new \RuntimeException("Separation sheet {$sheet->getName()} has unsupported type: {$sheet->getType()}"),
        };
    }

    private function resolveExistingCase(SeparationSheet $sheet): CaseEntity {
        $caseNumber = $sheet->getCaseNumber();
        if ($caseNumber === null || $caseNumber === '') {
            throw new \RuntimeException("Separation sheet {$sheet->getName()} has no case_number");
        }
        $case = $this->caseMapper->findByCaseNumber($caseNumber);
        if ($case === null) {
            throw new \RuntimeException("Case not found for separation sheet {$sheet->getName()}: {$caseNumber}");
        }
        return $case;
    }

    private function createNewCaseFromSheet(SeparationSheet $sheet): CaseEntity {
        $orgUuid          = $sheet->getOrgUuid();
        $sensitivityUuid  = $sheet->getSensitivityUuid();
        $classSubjectUuid = $sheet->getClassSubjectUuid();
        if ($orgUuid === null || $sensitivityUuid === null || $classSubjectUuid === null) {
            throw new \RuntimeException("Separation sheet {$sheet->getName()} is missing organisation/KLE/sensitivity");
        }

        $org = $this->organisationMapper->findByUuid($orgUuid);
        if ($org === null) {
            throw new \RuntimeException("Organisation not found: {$orgUuid}");
        }
        $sensitivity = $this->sensitivityMapper->findByUuid($sensitivityUuid);
        if ($sensitivity === null) {
            throw new \RuntimeException("Sensitivity not found: {$sensitivityUuid}");
        }
        $classSubject = $this->classificationSubjectMapper->findByUuid($classSubjectUuid);
        if ($classSubject === null) {
            throw new \RuntimeException("Classification subject not found: {$classSubjectUuid}");
        }

        $profile = $this->accessProfileMapper->findOrCreate(
            $org->getOrgUuid(),
            $classSubject->getUuid(),
            $sensitivity->getUuid()
        );
        $this->privilegeService->grantPrivilegedUsersToProfile(
            $profile->getId(),
            $org->getOrgUuid(),
            $classSubject->getUuid(),
            $sensitivity->getUuid()
        );

        $caseNumber = $this->caseNumberService->generateCaseNumber();
        $year       = $this->extractYear($caseNumber);

        $entity = new CaseEntity();
        $entity->setCaseNumber($caseNumber);
        $entity->setTitle($sheet->getTitle() ?: ('Sag ' . $caseNumber));
        $entity->setAccessProfileId($profile->getId());
        $entity->setOrgUuid($org->getOrgUuid());
        $entity->setYear($year);
        $entity->setStatusId(1);
        $entity->setCreatedAt(new \DateTime());
        $entity->setUpdatedAt(new \DateTime());
        $entity->setCreatedBy('system');
        $entity->setResponsibleUserId($sheet->getResponsibleUserId() ?: 'system');
        $entity->setInsightLevelId($sheet->getInsightLevelId());
        $entity->setClassificationFacetUuid($sheet->getClassificationFacetUuid());
        $entity->setUuid(CaseService::generateUuid());

        return $this->caseMapper->insert($entity);
    }

    // ---------------------------------------------------------------
    // Inbox case
    // ---------------------------------------------------------------

    private function getOrCreateInboxCase(): CaseEntity {
        $classSubject = $this->classificationSubjectMapper->findOrCreateByCode($this->inboxKleCode);
        return $this->getOrCreateInboxCaseForOrg(
            $this->inboxOrganisationUuid,
            $classSubject->getUuid(),
            $this->inboxSensitivity,
            $this->inboxFacet,
            $this->inboxInsightLevel,
        );
    }

    private function getOrCreateInboxCaseForOrg(
        ?string $orgUuid,
        ?string $classSubjectUuid,
        ?string $sensitivityUuid,
        ?string $facetUuid,
        ?int $insightLevelId,
    ): CaseEntity {
        if ($orgUuid !== null && $orgUuid !== '') {
            $org = $this->organisationMapper->findByUuid($orgUuid);
        } else {
            $org = $this->organisationMapper->findTopLevel();
        }
        if ($org === null) {
            throw new \RuntimeException('No organisation found — cannot create inbox case');
        }

        $existing = $this->caseMapper->findInboxByOrgUuid($org->getOrgUuid());
        if ($existing !== null) {
            return $existing;
        }

        $resolvedSensitivityUuid = ($sensitivityUuid !== null && $sensitivityUuid !== '')
            ? $sensitivityUuid
            : '31c09910-e011-46a5-86fb-254374421fe8';
        $sensitivity = $this->sensitivityMapper->findByUuid($resolvedSensitivityUuid);
        if ($sensitivity === null) {
            throw new \RuntimeException('Inbox sensitivity not found: ' . $resolvedSensitivityUuid);
        }

        $classSubject = ($classSubjectUuid !== null && $classSubjectUuid !== '')
            ? $this->classificationSubjectMapper->findByUuid($classSubjectUuid)
            : $this->classificationSubjectMapper->findOrCreateByCode(self::DEFAULT_KLE_CODE);
        if ($classSubject === null) {
            throw new \RuntimeException('Inbox classification subject not found: ' . $classSubjectUuid);
        }

        $profile = $this->accessProfileMapper->findOrCreate(
            $org->getOrgUuid(),
            $classSubject->getUuid(),
            $sensitivity->getUuid()
        );

        $this->privilegeService->grantPrivilegedUsersToProfile(
            $profile->getId(),
            $org->getOrgUuid(),
            $classSubject->getUuid(),
            $sensitivity->getUuid()
        );

        $resolvedFacetUuid = ($facetUuid !== null && $facetUuid !== '')
            ? $facetUuid
            : ($this->classificationFacetRepository->findByCode('A00')['uuid'] ?? 'A00');

        $caseNumber = $this->caseNumberService->generateCaseNumber();
        $year       = $this->extractYear($caseNumber);

        $entity = new CaseEntity();
        $entity->setCaseNumber($caseNumber);
        $entity->setTitle('Indbakke for ' . $org->getOrgName());
        $entity->setAccessProfileId($profile->getId());
        $entity->setOrgUuid($org->getOrgUuid());
        $entity->setYear($year);
        $entity->setStatusId(1);
        $entity->setCreatedAt(new \DateTime());
        $entity->setUpdatedAt(new \DateTime());
        $entity->setCreatedBy('system');
        $entity->setIsInbox(true);
        $entity->setClassificationFacetUuid($resolvedFacetUuid);
        $entity->setInsightLevelId($insightLevelId);
        $entity->setUuid(CaseService::generateUuid());

        return $this->caseMapper->insert($entity);
    }

    // ---------------------------------------------------------------
    // Document creation
    // ---------------------------------------------------------------

    private function createDocument(CaseEntity $case, string $title, ?int $insightLevelId): Document {
        $documentNumber = $this->caseNumberService->generateDocumentNumber(
            $case->getId(),
            $case->getCaseNumber()
        );

        $entity = new Document();
        $entity->setCaseId($case->getId());
        $entity->setDocumentNumber($documentNumber);
        $entity->setTitle($title);
        $entity->setDocumentType('Import');
        $entity->setStatus(3); // Final
        $entity->setInsightLevelId($insightLevelId);
        $entity->setDocumentDate((new \DateTime())->format('Y-m-d'));
        $entity->setReceivedDate((new \DateTime())->format('Y-m-d'));
        $entity->setDocumentCategoryId(self::DOCUMENT_CATEGORY_INBOUND);
        $entity->setCreatedAt(new \DateTime());
        $entity->setUpdatedAt(new \DateTime());
        $entity->setCreatedBy('system');
        $entity->setUuid(CaseService::generateUuid());

        $document = $this->documentMapper->insert($entity);

        $case->setUpdatedAt(new \DateTime());
        $this->caseMapper->update($case);

        return $document;
    }

    // ---------------------------------------------------------------
    // File storage
    // ---------------------------------------------------------------

    private function storeFile(
        CaseEntity $case,
        Document $document,
        string $originalFilename,
        string $content,
        string $mimeType,
    ): FileEntity {
        $extension       = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $storageFilename = $this->generateUuid() . ($extension !== '' ? '.' . $extension : '');
        $virtualFilename = $this->fileMapper->generateUniqueVirtualFilename($case->getId(), $originalFilename);

        $physicalPath = $this->buildFilePath($case, $document->getId(), $storageFilename);
        $physicalDir  = dirname($physicalPath);

        if (!is_dir($physicalDir) && !mkdir($physicalDir, 0770, true) && !is_dir($physicalDir)) {
            throw new \RuntimeException("Failed to create directory: {$physicalDir}");
        }

        if (file_put_contents($physicalPath, $content) === false) {
            throw new \RuntimeException("Failed to write file: {$physicalPath}");
        }

        $entity = new FileEntity();
        $entity->setDocumentId($document->getId());
        $entity->setCaseId($case->getId());
        $entity->setOriginalFilename($originalFilename);
        $entity->setStorageFilename($storageFilename);
        $entity->setVirtualFilename($virtualFilename);
        $entity->setMimeType($mimeType);
        $entity->setSize((int)filesize($physicalPath));
        $entity->setVersion(1);
        $entity->setChecksum(hash_file('sha256', $physicalPath) ?: null);
        $entity->setCreatedAt(new \DateTime());
        $entity->setUpdatedAt(new \DateTime());
        $entity->setCreatedBy('system');
        $entity->setLastModifiedBy('system');
        $entity->setUuid(CaseService::generateUuid());

        $entity = $this->fileMapper->insert($entity);

        $document->setUpdatedAt(new \DateTime());
        $this->documentMapper->update($document);

        return $entity;
    }

    // ---------------------------------------------------------------
    // PDF helpers (page count, QR detection, page extraction)
    // ---------------------------------------------------------------

    private function countPdfPages(string $path): int {
        $fpdi = new Fpdi();
        return $fpdi->setSourceFile($path);
    }

    /**
     * Same as countPdfPages(), but never throws — used when the page count is
     * only needed for stats and a failure there shouldn't fail an otherwise
     * successful import.
     */
    private function safeCountPdfPages(string $path): ?int {
        try {
            return $this->countPdfPages($path);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function detectQrOnPage(string $pdfPath, int $pageNo): ?string {
        $tmpImage = tempnam(sys_get_temp_dir(), 'opencase_qr_') . '.png';
        try {
            $imagick = new \Imagick();
            $imagick->setResolution(150, 150);
            $imagick->readImage($pdfPath . '[' . ($pageNo - 1) . ']');
            $imagick->setImageFormat('png');
            $imagick->writeImage($tmpImage);
            $imagick->clear();

            $reader = new QrReader($tmpImage);
            $text   = $reader->text();

            return ($text !== false && $text !== '') ? $text : null;
        } catch (\Throwable $e) {
            $this->logger->warning(
                "ProcessImportsJob: QR detection failed on page {$pageNo} — {$e->getMessage()}",
                ['app' => 'opencase']
            );
            return null;
        } finally {
            if (file_exists($tmpImage)) {
                @unlink($tmpImage);
            }
        }
    }

    /**
     * @param int[] $pageNumbers 1-based page numbers, in the order they should appear
     */
    private function extractPdfPages(string $sourcePath, array $pageNumbers): string {
        $pdf = new Fpdi();
        $pdf->setSourceFile($sourcePath);
        foreach ($pageNumbers as $pageNo) {
            $tplId = $pdf->importPage($pageNo);
            $size  = $pdf->getTemplateSize($tplId);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tplId);
        }
        return $pdf->Output('S');
    }

    // ---------------------------------------------------------------
    // Import stats (file_stats XML)
    // ---------------------------------------------------------------

    /**
     * Build the file_stats XML for an import item: which separation sheet(s)
     * were recognized, the resulting case/document/file ids, and how many
     * pages went into each file.
     *
     * @param array{documents: array<int, array{id: int, case_id: int, separation_sheet: ?string, separation_sheet_type: ?string, files: array<int, array{id: int, pages: ?int}>}>} $stats
     */
    private function buildStatsXml(array $stats): string {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('import-stats');
        $dom->appendChild($root);

        $documentsEl = $dom->createElement('documents');
        $root->appendChild($documentsEl);

        foreach ($stats['documents'] as $docStat) {
            $docEl = $dom->createElement('document');
            $docEl->setAttribute('id', (string)$docStat['id']);
            $docEl->setAttribute('case_id', (string)$docStat['case_id']);
            if ($docStat['separation_sheet'] !== null) {
                $docEl->setAttribute('separation_sheet', $docStat['separation_sheet']);
            }
            if ($docStat['separation_sheet_type'] !== null) {
                $docEl->setAttribute('separation_sheet_type', $docStat['separation_sheet_type']);
            }

            $filesEl = $dom->createElement('files');
            foreach ($docStat['files'] as $fileStat) {
                $fileEl = $dom->createElement('file');
                $fileEl->setAttribute('id', (string)$fileStat['id']);
                if ($fileStat['pages'] !== null) {
                    $fileEl->setAttribute('pages', (string)$fileStat['pages']);
                }
                $filesEl->appendChild($fileEl);
            }
            $docEl->appendChild($filesEl);

            $documentsEl->appendChild($docEl);
        }

        return $dom->saveXML();
    }

    // ---------------------------------------------------------------
    // Path & utility helpers
    // ---------------------------------------------------------------

    private function buildFilePath(CaseEntity $case, int $documentId, string $storageFilename): string {
        $year  = $case->getCreatedYear();
        $month = $case->getCreatedMonth();
        if ($year === '') {
            throw new \RuntimeException("Case {$case->getId()} has no creation date");
        }
        return sprintf(
            '%s/%s/%s/%d/%d/%s',
            $this->baseDir,
            $year,
            $month,
            $case->getId(),
            $documentId,
            $storageFilename,
        );
    }

    private function buildImportedTitle(string $originalFilename): string {
        return sprintf('%s importeret %s', $originalFilename, (new \DateTime())->format('Y-m-d'));
    }

    private function extractYear(string $caseNumber): string {
        if (preg_match('/^(\d{4})/', $caseNumber, $m)) {
            return $m[1];
        }
        return (string)date('Y');
    }

    private function generateUuid(): string {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
