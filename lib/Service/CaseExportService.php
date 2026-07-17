<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use DOMDocument;
use DOMElement;
use OCA\OpenCase\Db\AccessProfileMapper;
use OCA\OpenCase\Db\AuditLogEntry;
use OCA\OpenCase\Db\AuditLogMapper;
use OCA\OpenCase\Db\CaseEntity;
use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\CaseParticipantMapper;
use OCA\OpenCase\Db\CaseStatusMapper;
use OCA\OpenCase\Db\CaseTypeMapper;
use OCA\OpenCase\Db\CaseWorkerMapper;
use OCA\OpenCase\Db\ClassificationFacetRepository;
use OCA\OpenCase\Db\ClassificationSubjectMapper;
use OCA\OpenCase\Db\ContactRoleMapper;
use OCA\OpenCase\Db\Document;
use OCA\OpenCase\Db\DocumentCategoryMapper;
use OCA\OpenCase\Db\DocumentContactMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\DocumentNoteMapper;
use OCA\OpenCase\Db\DocumentStatusMapper;
use OCA\OpenCase\Db\DocWorkflowMapper;
use OCA\OpenCase\Db\DocWorkflowStepMapper;
use OCA\OpenCase\Db\ExportLogEntry;
use OCA\OpenCase\Db\ExportLogMapper;
use OCA\OpenCase\Db\FileMapper;
use OCA\OpenCase\Db\InsightLevelMapper;
use OCA\OpenCase\Db\JournalNoteMapper;
use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Db\ParticipantRoleMapper;
use OCA\OpenCase\Db\SensitivityMapper;
use OCP\IConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Exports closed cases to XML + physical files on disk.
 *
 * Called hourly by ExportClosedCasesJob (and on demand via the
 * `opencase:export-closed-cases` command). For each closed case whose
 * exported_at is still null, writes:
 *
 *   <export_folder>/<case_number>/case.xml         — case + document metadata
 *   <export_folder>/<case_number>/files/<doc_id>/…  — copied document files
 *   <export_folder>/<case_number>/logs/case.xml     — case-level audit log
 *   <export_folder>/<case_number>/logs/document_<id>.xml — per-document audit log
 *
 * case.xml validates against appinfo/schema/case-export.xsd
 * (namespace https://opencase.dk/case).
 */
class CaseExportService {

    private const NS = 'https://opencase.dk/case';

    // Lookup tables (organisation/status/casetype/insight-level) are keyed by
    // (id, language). There is no active user/session in a background job,
    // so — matching TemplateMergeService::buildMergeVariables() — Danish is
    // used as the fixed system language for resolving display names.
    private const LOOKUP_LANGUAGE = 'da';

    private string $storageBaseDir;

    public function __construct(
        private CaseMapper $caseMapper,
        private CaseParticipantMapper $caseParticipantMapper,
        private JournalNoteMapper $journalNoteMapper,
        private CaseWorkerMapper $caseWorkerMapper,
        private DocumentMapper $documentMapper,
        private FileMapper $fileMapper,
        private DocumentContactMapper $documentContactMapper,
        private DocumentNoteMapper $documentNoteMapper,
        private DocWorkflowMapper $docWorkflowMapper,
        private DocWorkflowStepMapper $docWorkflowStepMapper,
        private AuditLogMapper $auditLogMapper,
        private OrganisationMapper $organisationMapper,
        private CaseStatusMapper $caseStatusMapper,
        private CaseTypeMapper $caseTypeMapper,
        private InsightLevelMapper $insightLevelMapper,
        private AccessProfileMapper $accessProfileMapper,
        private ClassificationSubjectMapper $classificationSubjectMapper,
        private ClassificationFacetRepository $classificationFacetRepository,
        private SensitivityMapper $sensitivityMapper,
        private ParticipantRoleMapper $participantRoleMapper,
        private ContactRoleMapper $contactRoleMapper,
        private DocumentStatusMapper $documentStatusMapper,
        private DocumentCategoryMapper $documentCategoryMapper,
        private ExportLogMapper $exportLogMapper,
        private Configuration $configuration,
        private IConfig $config,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {
        // Same base-dir resolution as FileService — the physical location
        // where document files actually live on disk.
        $defaultStorageDir = rtrim($config->getSystemValueString('datadirectory', ''), '/') . '/opencase_storage';
        $this->storageBaseDir = rtrim($config->getAppValue('opencase', 'storage_base_dir', $defaultStorageDir), '/');
    }

    /**
     * Export a batch of closed-but-not-yet-exported cases.
     *
     * @return array{count: int, exported: int, failed: int}
     */
    public function exportPendingCases(int $batchSize = 50): array {
        $exportRoot = rtrim($this->configuration->getConfigValue('export_folder', '') ?? '', '/');
        if ($exportRoot === '') {
            $this->logger->warning('CaseExportService: export_folder is not configured, skipping export run', ['app' => 'opencase']);
            $this->writeExportLog(0, 0, 0);
            return ['count' => 0, 'exported' => 0, 'failed' => 0];
        }

        $cases    = $this->caseMapper->findClosedUnexported($batchSize);
        $exported = 0;
        $failed   = 0;

        foreach ($cases as $case) {
            try {
                $this->exportCase($case, $exportRoot);
                $exported++;
            } catch (\Throwable $e) {
                $failed++;
                $this->logger->error(
                    'CaseExportService: failed to export case ' . $case->getId() . ' (' . $case->getCaseNumber() . '): ' . $e->getMessage(),
                    ['app' => 'opencase', 'exception' => $e]
                );
            }
        }

        $count = count($cases);
        $this->writeExportLog($count, $exported, $failed);

        return ['count' => $count, 'exported' => $exported, 'failed' => $failed];
    }

    private function writeExportLog(int $count, int $exported, int $failed): void {
        $entry = new ExportLogEntry();
        $entry->setSyncTime(new \DateTime());
        $entry->setCount($count);
        $entry->setExported($exported);
        $entry->setFailed($failed);
        $this->exportLogMapper->insert($entry);
    }

    /**
     * Export a single case: writes case.xml, copies document files into
     * files/, writes the audit trail into logs/, and stamps exported_at.
     */
    public function exportCase(CaseEntity $case, string $exportRoot): void {
        $caseFolder = $exportRoot . '/' . $this->sanitizeSegment($case->getCaseNumber());
        $filesFolder = $caseFolder . '/files';
        $logsFolder  = $caseFolder . '/logs';
        $this->ensureDir($caseFolder);
        $this->ensureDir($logsFolder);

        $now = new \DateTime();

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $caseEl = $doc->createElementNS(self::NS, 'Case');
        $doc->appendChild($caseEl);

        $participantRoleNames = $this->participantRoleMapper->getNameMap(self::LOOKUP_LANGUAGE);
        $contactRoleNames     = $this->contactRoleMapper->getNameMap(self::LOOKUP_LANGUAGE);
        $documentStatusNames  = $this->documentStatusMapper->getNameMap(self::LOOKUP_LANGUAGE);
        $documentCategoryNames = $this->documentCategoryMapper->getNameMap(self::LOOKUP_LANGUAGE);

        $this->appendFields($doc, $caseEl, $this->caseFields($case, $now));
        $this->appendParticipants($doc, $caseEl, $case->getId(), $participantRoleNames);
        $this->appendJournalNotes($doc, $caseEl, $case->getId());
        $this->appendCaseworkers($doc, $caseEl, $case->getId());

        $documentsEl = $doc->createElementNS(self::NS, 'Documents');
        $caseEl->appendChild($documentsEl);
        foreach ($this->documentMapper->findByCase($case->getId()) as $document) {
            $this->appendDocument(
                $doc,
                $documentsEl,
                $document,
                $case,
                $filesFolder,
                $logsFolder,
                $contactRoleNames,
                $documentStatusNames,
                $documentCategoryNames,
            );
        }

        $doc->save($caseFolder . '/case.xml');

        $this->writeAuditLog($this->auditLogMapper->findAllByCase($case->getId()), $logsFolder . '/case.xml');

        $case->setExportedAt($now);
        $this->caseMapper->update($case);
    }

    // ---------------------------------------------------------------
    // Case-level sections
    // ---------------------------------------------------------------

    /** @return array<string, string|null> */
    private function caseFields(CaseEntity $case, \DateTime $exportedAt): array {
        $responsibleUserId = $case->getResponsibleUserId();

        $orgName = $this->organisationMapper->findByUuid($case->getOrgUuid())?->getOrgName();

        $statusNames = $this->caseStatusMapper->getNamesByIds([$case->getStatusId()], self::LOOKUP_LANGUAGE);
        $statusName  = $statusNames[$case->getStatusId()]['name'] ?? null;

        $casetypeId   = $case->getCasetypeId();
        $casetypeName = $casetypeId !== null
            ? ($this->caseTypeMapper->getNamesByIds([$casetypeId], self::LOOKUP_LANGUAGE)[$casetypeId] ?? null)
            : null;

        $insightLevelId   = $case->getInsightLevelId();
        $insightLevelName = $insightLevelId !== null
            ? ($this->insightLevelMapper->getNamesByIds([$insightLevelId], self::LOOKUP_LANGUAGE)[$insightLevelId] ?? null)
            : null;

        $classificationCodeUuid = null;
        $classificationCode     = null;
        $sensitivityUuid        = null;
        $sensitivityName        = null;
        $profile = $this->accessProfileMapper->findById($case->getAccessProfileId());
        if ($profile !== null) {
            $classificationCodeUuid = $profile->getClassSubjectUuid();
            $classificationCode     = $this->classificationSubjectMapper->findByUuid($classificationCodeUuid)?->getCode();
            $sensitivityUuid        = $profile->getSensitivityUuid();
            $sensitivityName        = $this->sensitivityMapper->findByUuid($sensitivityUuid)?->getTitle();
        }

        $classificationFacetUuid = $case->getClassificationFacetUuid();
        $classificationFacetCode = $classificationFacetUuid !== null
            ? ($this->classificationFacetRepository->find($classificationFacetUuid)['code'] ?? null)
            : null;

        return [
            'Id'                          => (string)$case->getId(),
            'CaseNumber'                  => $case->getCaseNumber(),
            'Title'                       => $case->getTitle(),
            'Uuid'                        => $case->getUuid(),
            'OrgUuid'                     => $case->getOrgUuid(),
            'OrganisationName'            => $orgName,
            'Year'                        => $case->getYear(),
            'StatusId'                    => (string)$case->getStatusId(),
            'StatusName'                  => $statusName,
            'CasetypeId'                  => $this->intOrNull($casetypeId),
            'CasetypeName'                => $casetypeName,
            'InsightLevelId'              => $this->intOrNull($insightLevelId),
            'InsightLevelName'            => $insightLevelName,
            'ClassificationCodeUuid'      => $classificationCodeUuid,
            'ClassificationCode'          => $classificationCode,
            'ClassificationFacetUuid'     => $classificationFacetUuid,
            'ClassificationFacetCode'     => $classificationFacetCode,
            'SensitivityUuid'             => $sensitivityUuid,
            'SensitivityName'             => $sensitivityName,
            'CreatedAt'                   => $this->formatDateTime($case->getCreatedAt()),
            'UpdatedAt'                   => $this->formatDateTime($case->getUpdatedAt()),
            'CreatedBy'                   => $case->getCreatedBy(),
            'ResponsibleUserId'           => $responsibleUserId,
            'ResponsibleUserDisplayName'  => $responsibleUserId !== null ? $this->displayName($responsibleUserId) : null,
            'ExportedAt'                  => $this->formatDateTime($exportedAt),
        ];
    }

    /** @param array<int, string> $roleNames */
    private function appendParticipants(DOMDocument $doc, DOMElement $caseEl, int $caseId, array $roleNames): void {
        $wrapEl = $doc->createElementNS(self::NS, 'Participants');
        $caseEl->appendChild($wrapEl);

        foreach ($this->caseParticipantMapper->findByCase($caseId) as $p) {
            $el = $doc->createElementNS(self::NS, 'Participant');
            $wrapEl->appendChild($el);
            $this->appendFields($doc, $el, [
                'Id'                    => (string)$p->getId(),
                'RoleId'                => (string)$p->getParticipantroleId(),
                'RoleName'              => $roleNames[$p->getParticipantroleId()] ?? null,
                'CprCvr'                => $p->getCprCvr(),
                'Pnumber'               => $p->getPnumber(),
                'Name'                  => $p->getName(),
                'Streetname'            => $p->getStreetname(),
                'Housenumber'           => $p->getHousenumber(),
                'Floor'                 => $p->getFloor(),
                'Door'                  => $p->getDoor(),
                'Zipcode'               => $p->getZipcode(),
                'Zipdistrict'           => $p->getZipdistrict(),
                'Phone'                 => $p->getPhone(),
                'Email'                 => $p->getEmail(),
                'UpdatedDate'           => $this->formatDateTime($p->getUpdatedDate()),
                'HasAddressProtection'  => $p->getHasAddressProtection() ? 'true' : 'false',
            ]);
        }
    }

    private function appendJournalNotes(DOMDocument $doc, DOMElement $caseEl, int $caseId): void {
        $wrapEl = $doc->createElementNS(self::NS, 'JournalNotes');
        $caseEl->appendChild($wrapEl);

        foreach ($this->journalNoteMapper->findByCase($caseId) as $note) {
            $el = $doc->createElementNS(self::NS, 'JournalNote');
            $wrapEl->appendChild($el);
            $this->appendFields($doc, $el, [
                'Id'        => (string)$note->getId(),
                'Title'     => $note->getTitle(),
                'Text'      => $note->getText(),
                'CreatedAt' => $this->formatDateTime($note->getCreatedAt()),
                'CreatedBy' => $note->getCreatedBy(),
                'UpdatedAt' => $this->formatDateTime($note->getUpdatedAt()),
                'IsLocked'  => $note->getIsLocked() ? 'true' : 'false',
            ]);
        }
    }

    private function appendCaseworkers(DOMDocument $doc, DOMElement $caseEl, int $caseId): void {
        $wrapEl = $doc->createElementNS(self::NS, 'Caseworkers');
        $caseEl->appendChild($wrapEl);

        foreach ($this->caseWorkerMapper->findByCase($caseId) as $cw) {
            $el = $doc->createElementNS(self::NS, 'Caseworker');
            $wrapEl->appendChild($el);
            $this->appendFields($doc, $el, [
                'UserId'        => $cw->getUserId(),
                'DisplayName'   => $this->displayName($cw->getUserId()),
                'PriorCanWrite' => $this->intOrNull($cw->getPriorCanWrite()),
            ]);
        }
    }

    // ---------------------------------------------------------------
    // Document-level sections
    // ---------------------------------------------------------------

    /**
     * @param array<int, string> $contactRoleNames
     * @param array<int, string> $documentStatusNames
     * @param array<int, string> $documentCategoryNames
     */
    private function appendDocument(
        DOMDocument $doc,
        DOMElement $documentsEl,
        Document $document,
        CaseEntity $case,
        string $filesFolder,
        string $logsFolder,
        array $contactRoleNames,
        array $documentStatusNames,
        array $documentCategoryNames,
    ): void {
        $documentId = $document->getId();

        $el = $doc->createElementNS(self::NS, 'Document');
        $documentsEl->appendChild($el);

        $documentCategoryId = $document->getDocumentCategoryId();
        $insightLevelId     = $document->getInsightLevelId();

        $this->appendFields($doc, $el, [
            'Id'                  => (string)$documentId,
            'DocumentNumber'      => $document->getDocumentNumber(),
            'Title'               => $document->getTitle(),
            'DocumentType'        => $document->getDocumentType(),
            'Status'              => (string)$document->getStatus(),
            'StatusName'          => $documentStatusNames[$document->getStatus()] ?? null,
            'DocumentCategoryId'  => $this->intOrNull($documentCategoryId),
            'DocumentCategoryName' => $documentCategoryId !== null ? ($documentCategoryNames[$documentCategoryId] ?? null) : null,
            'InsightLevelId'      => $this->intOrNull($insightLevelId),
            'InsightLevelName'    => $insightLevelId !== null
                ? ($this->insightLevelMapper->getNamesByIds([$insightLevelId], self::LOOKUP_LANGUAGE)[$insightLevelId] ?? null)
                : null,
            'Uuid'                => $document->getUuid(),
            'DocumentDate'        => $document->getDocumentDate(),
            'ReceivedDate'        => $document->getReceivedDate(),
            'RegisteredDate'      => $document->getRegisteredDate(),
            'CreatedAt'           => $this->formatDateTime($document->getCreatedAt()),
            'UpdatedAt'           => $this->formatDateTime($document->getUpdatedAt()),
            'CreatedBy'           => $document->getCreatedBy(),
        ]);

        $this->appendFiles($doc, $el, $documentId, $case, $filesFolder);
        $this->appendContacts($doc, $el, $documentId, $contactRoleNames);
        $this->appendDocumentNotes($doc, $el, $documentId);
        $this->appendWorkflowHistory($doc, $el, $documentId);

        $this->writeAuditLog(
            $this->auditLogMapper->findAllByDocument($documentId),
            $logsFolder . '/document_' . $documentId . '.xml'
        );
    }

    private function appendFiles(DOMDocument $doc, DOMElement $documentEl, int $documentId, CaseEntity $case, string $filesFolder): void {
        $wrapEl = $doc->createElementNS(self::NS, 'Files');
        $documentEl->appendChild($wrapEl);

        $year  = $case->getCreatedYear();
        $month = $case->getCreatedMonth();

        foreach ($this->fileMapper->findByDocument($documentId) as $file) {
            $relativePath = 'files/' . $documentId . '/' . $this->sanitizeSegment($file->getOriginalFilename());
            $targetPath   = $filesFolder . '/' . $documentId . '/' . $this->sanitizeSegment($file->getOriginalFilename());

            if ($year !== '') {
                $sourcePath = sprintf(
                    '%s/%s/%s/%d/%d/%s',
                    $this->storageBaseDir,
                    $year,
                    $month,
                    $file->getCaseId(),
                    $file->getDocumentId(),
                    $file->getStorageFilename()
                );

                if (is_file($sourcePath)) {
                    $this->ensureDir(dirname($targetPath));
                    if (!copy($sourcePath, $targetPath)) {
                        $this->logger->warning("CaseExportService: failed to copy file {$sourcePath} to {$targetPath}", ['app' => 'opencase']);
                    }
                } else {
                    $this->logger->warning("CaseExportService: source file not found on disk: {$sourcePath}", ['app' => 'opencase']);
                }
            }

            $el = $doc->createElementNS(self::NS, 'File');
            $wrapEl->appendChild($el);
            $this->appendFields($doc, $el, [
                'Id'                => (string)$file->getId(),
                'OriginalFilename'  => $file->getOriginalFilename(),
                'RelativePath'      => $relativePath,
                'MimeType'          => $file->getMimeType(),
                'Size'              => (string)$file->getSize(),
                'Version'           => (string)$file->getVersion(),
                'Checksum'          => $file->getChecksum(),
                'CreatedAt'         => $this->formatDateTime($file->getCreatedAt()),
                'UpdatedAt'         => $this->formatDateTime($file->getUpdatedAt()),
                'CreatedBy'         => $file->getCreatedBy(),
                'LastModifiedBy'    => $file->getLastModifiedBy(),
            ]);
        }
    }

    /** @param array<int, string> $roleNames */
    private function appendContacts(DOMDocument $doc, DOMElement $documentEl, int $documentId, array $roleNames): void {
        $wrapEl = $doc->createElementNS(self::NS, 'Contacts');
        $documentEl->appendChild($wrapEl);

        foreach ($this->documentContactMapper->findByDocument($documentId) as $c) {
            $el = $doc->createElementNS(self::NS, 'Contact');
            $wrapEl->appendChild($el);
            $this->appendFields($doc, $el, [
                'Id'                    => (string)$c->getId(),
                'RoleId'                => (string)$c->getContactroleId(),
                'RoleName'              => $roleNames[$c->getContactroleId()] ?? null,
                'CprCvr'                => $c->getCprCvr(),
                'Pnumber'               => $c->getPnumber(),
                'Name'                  => $c->getName(),
                'Streetname'            => $c->getStreetname(),
                'Housenumber'           => $c->getHousenumber(),
                'Floor'                 => $c->getFloor(),
                'Door'                  => $c->getDoor(),
                'Zipcode'               => $c->getZipcode(),
                'Zipdistrict'           => $c->getZipdistrict(),
                'Phone'                 => $c->getPhone(),
                'Email'                 => $c->getEmail(),
                'UpdatedDate'           => $this->formatDateTime($c->getUpdatedDate()),
                'HasAddressProtection'  => $c->getHasAddressProtection() ? 'true' : 'false',
            ]);
        }
    }

    private function appendDocumentNotes(DOMDocument $doc, DOMElement $documentEl, int $documentId): void {
        $wrapEl = $doc->createElementNS(self::NS, 'Notes');
        $documentEl->appendChild($wrapEl);

        foreach ($this->documentNoteMapper->findByDocument($documentId) as $note) {
            $el = $doc->createElementNS(self::NS, 'Note');
            $wrapEl->appendChild($el);
            $this->appendFields($doc, $el, [
                'Id'        => (string)$note->getId(),
                'Title'     => $note->getTitle(),
                'Text'      => $note->getText(),
                'CreatedAt' => $this->formatDateTime($note->getCreatedAt()),
                'CreatedBy' => $note->getCreatedBy(),
                'UpdatedAt' => $this->formatDateTime($note->getUpdatedAt()),
            ]);
        }
    }

    private function appendWorkflowHistory(DOMDocument $doc, DOMElement $documentEl, int $documentId): void {
        $wrapEl = $doc->createElementNS(self::NS, 'WorkflowHistory');
        $documentEl->appendChild($wrapEl);

        foreach ($this->docWorkflowMapper->findByDocument($documentId) as $workflow) {
            $el = $doc->createElementNS(self::NS, 'Workflow');
            $wrapEl->appendChild($el);
            $this->appendFields($doc, $el, [
                'Id'        => (string)$workflow->getId(),
                'Type'      => $workflow->getType(),
                'Status'    => $workflow->getStatus(),
                'Deadline'  => $this->formatDateTime($workflow->getDeadline()),
                'CreatedBy' => $workflow->getCreatedBy(),
                'CreatedAt' => $this->formatDateTime($workflow->getCreatedAt()),
                'UpdatedAt' => $this->formatDateTime($workflow->getUpdatedAt()),
            ]);

            $stepsEl = $doc->createElementNS(self::NS, 'Steps');
            $el->appendChild($stepsEl);
            foreach ($this->docWorkflowStepMapper->findByWorkflow($workflow->getId()) as $step) {
                $stepEl = $doc->createElementNS(self::NS, 'Step');
                $stepsEl->appendChild($stepEl);
                $this->appendFields($doc, $stepEl, [
                    'Id'          => (string)$step->getId(),
                    'UserId'      => $step->getUserId(),
                    'DisplayName' => $this->displayName($step->getUserId()),
                    'SortOrder'   => (string)$step->getSortOrder(),
                    'Status'      => $step->getStatus(),
                    'Comment'     => $step->getComment(),
                    'Deadline'    => $this->formatDateTime($step->getDeadline()),
                    'ActedAt'     => $this->formatDateTime($step->getActedAt()),
                ]);
            }
        }
    }

    // ---------------------------------------------------------------
    // Audit log
    // ---------------------------------------------------------------

    /** @param AuditLogEntry[] $entries */
    private function writeAuditLog(array $entries, string $filePath): void {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $rootEl = $doc->createElementNS(self::NS, 'AuditLog');
        $doc->appendChild($rootEl);

        foreach ($entries as $entry) {
            $el = $doc->createElementNS(self::NS, 'Entry');
            $rootEl->appendChild($el);
            $this->appendFields($doc, $el, [
                'Id'         => (string)$entry->getId(),
                'CaseId'     => (string)$entry->getCaseId(),
                'DocumentId' => $this->intOrNull($entry->getDocumentId()),
                'FileId'     => $this->intOrNull($entry->getFileId()),
                'UserId'     => $entry->getUserId(),
                'EventType'  => $entry->getEventType(),
                'Details'    => $entry->getDetails(),
                'CreatedAt'  => $this->formatDateTime($entry->getCreatedAt()),
            ]);
        }

        $doc->save($filePath);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Append one child element per non-null entry, in map order.
     *
     * @param array<string, string|null> $fields
     */
    private function appendFields(DOMDocument $doc, DOMElement $parent, array $fields): void {
        foreach ($fields as $name => $value) {
            if ($value === null) {
                continue;
            }
            $el = $doc->createElementNS(self::NS, $name);
            $el->appendChild($doc->createTextNode($value));
            $parent->appendChild($el);
        }
    }

    private function formatDateTime(?\DateTimeInterface $dt): ?string {
        return $dt?->format(\DateTimeInterface::ATOM);
    }

    private function intOrNull(?int $value): ?string {
        return $value !== null ? (string)$value : null;
    }

    private function displayName(string $userId): string {
        return $this->userManager->get($userId)?->getDisplayName() ?? $userId;
    }

    /**
     * Strip path-traversal and separator characters from a value used as a
     * single filesystem path segment (case number, filename, …).
     */
    private function sanitizeSegment(string $value): string {
        $value = str_replace(['/', '\\'], '_', $value);
        $value = preg_replace('/[\x00-\x1F]/', '', $value) ?? $value;
        return trim($value) !== '' ? $value : '_';
    }

    private function ensureDir(string $path): void {
        if (!is_dir($path) && !mkdir($path, 0770, true) && !is_dir($path)) {
            throw new \RuntimeException("Failed to create export directory {$path} — check ownership/permissions of export_folder");
        }
    }
}
