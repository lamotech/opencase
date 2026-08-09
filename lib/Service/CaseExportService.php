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

    public const NS = 'https://opencase.dk/case';

    // Lookup tables (organisation/status/casetype/insight-level) are keyed by
    // (id, language). There is no active user/session in a background job,
    // so — matching TemplateMergeService::buildMergeVariables() — Danish is
    // used as the fixed system language for resolving display names.
    private const LOOKUP_LANGUAGE = 'da';

    // Status a case is moved to once it has been exported successfully
    // (opencase_casestatus: 3 = Arkiveret / Archived).
    private const STATUS_ARCHIVED = 3;

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
     * files/, writes the audit trail into logs/, and — once everything is on
     * disk — stamps exported_at and moves the case to Arkiveret.
     */
    public function exportCase(CaseEntity $case, string $exportRoot): void {
        $caseFolder = $exportRoot . '/' . $this->sanitizeSegment($case->getCaseNumber());
        $filesFolder = $caseFolder . '/files';
        $logsFolder  = $caseFolder . '/logs';
        $this->ensureDir($caseFolder);
        $this->ensureDir($logsFolder);

        $now = new \DateTime();

        // Archive up front so case.xml records the status the case ends up
        // with. Nothing is persisted unless the whole export succeeds — an
        // exception below leaves the row untouched and it is picked up again
        // on the next run.
        $case->setStatusId(self::STATUS_ARCHIVED);

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
        $case->setUpdatedAt($now);
        $this->caseMapper->update($case);
    }

    // ---------------------------------------------------------------
    // Case-level sections
    // ---------------------------------------------------------------

    /**
     * Build the flat CaseType field map (Id..ExportedAt, matching
     * appinfo/schema/case-export.xsd) for a case. Shared by the closed-case
     * export and the public data API's single-case lookup.
     *
     * @return array<string, string|null>
     */
    public function caseFields(CaseEntity $case, ?\DateTimeInterface $exportedAt): array {
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
            'Summary'                     => $case->getSummary(),
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

        foreach ($this->participantFields($caseId, $roleNames) as $fields) {
            $el = $doc->createElementNS(self::NS, 'Participant');
            $wrapEl->appendChild($el);
            $this->appendFields($doc, $el, $fields);
        }
    }

    /**
     * Build the PartyType field maps for a case's participants, matching
     * appinfo/schema/case-export.xsd. Shared by the closed-case export and
     * the public data API's single-case lookup.
     *
     * @param array<int, string>|null $roleNames
     * @return list<array<string, string|null>>
     */
    public function participantFields(int $caseId, ?array $roleNames = null): array {
        $roleNames ??= $this->participantRoleMapper->getNameMap(self::LOOKUP_LANGUAGE);

        $result = [];
        foreach ($this->caseParticipantMapper->findByCase($caseId) as $p) {
            $result[] = [
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
            ];
        }
        return $result;
    }

    private function appendJournalNotes(DOMDocument $doc, DOMElement $caseEl, int $caseId): void {
        $wrapEl = $doc->createElementNS(self::NS, 'JournalNotes');
        $caseEl->appendChild($wrapEl);

        foreach ($this->journalNoteFields($caseId) as $fields) {
            $el = $doc->createElementNS(self::NS, 'JournalNote');
            $wrapEl->appendChild($el);
            $this->appendFields($doc, $el, $fields);
        }
    }

    /**
     * Build the NoteType field maps for a case's journal notes, matching
     * appinfo/schema/case-export.xsd. Shared by the closed-case export and
     * the public data API's single-case lookup.
     *
     * @return list<array<string, string|null>>
     */
    public function journalNoteFields(int $caseId): array {
        return array_map(
            fn ($note) => $this->journalNoteFieldsFromEntity($note),
            $this->journalNoteMapper->findByCase($caseId),
        );
    }

    /**
     * Build the NoteType field map for a single journal note, matching
     * appinfo/schema/case-export.xsd. Public so the public data API's
     * journal-note-adding endpoint can reuse it for its response shape.
     *
     * @return array<string, string|null>
     */
    public function journalNoteFieldsFromEntity($note): array {
        return [
            'Id'        => (string)$note->getId(),
            'Title'     => $note->getTitle(),
            'Text'      => $note->getText(),
            'CreatedAt' => $this->formatDateTime($note->getCreatedAt()),
            'CreatedBy' => $note->getCreatedBy(),
            'UpdatedAt' => $this->formatDateTime($note->getUpdatedAt()),
            'IsLocked'  => $note->getIsLocked() ? 'true' : 'false',
        ];
    }

    private function appendCaseworkers(DOMDocument $doc, DOMElement $caseEl, int $caseId): void {
        $wrapEl = $doc->createElementNS(self::NS, 'Caseworkers');
        $caseEl->appendChild($wrapEl);

        foreach ($this->caseworkerFields($caseId) as $fields) {
            $el = $doc->createElementNS(self::NS, 'Caseworker');
            $wrapEl->appendChild($el);
            $this->appendFields($doc, $el, $fields);
        }
    }

    /**
     * Build the CaseworkerType field maps for a case, matching
     * appinfo/schema/case-export.xsd. Shared by the closed-case export and
     * the public data API's single-case lookup.
     *
     * @return list<array<string, string|null>>
     */
    public function caseworkerFields(int $caseId): array {
        $result = [];
        foreach ($this->caseWorkerMapper->findByCase($caseId) as $cw) {
            $result[] = [
                'UserId'        => $cw->getUserId(),
                'DisplayName'   => $this->displayName($cw->getUserId()),
                'PriorCanWrite' => $this->intOrNull($cw->getPriorCanWrite()),
            ];
        }
        return $result;
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

        $this->appendFields($doc, $el, $this->documentFieldsFromEntity($document, $documentStatusNames, $documentCategoryNames));

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
            $targetPath = $filesFolder . '/' . $documentId . '/' . $this->sanitizeSegment($file->getOriginalFilename());

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
            $this->appendFields($doc, $el, $this->fileFieldsFromEntity($file, $documentId));
        }
    }

    /** @param array<int, string> $roleNames */
    private function appendContacts(DOMDocument $doc, DOMElement $documentEl, int $documentId, array $roleNames): void {
        $wrapEl = $doc->createElementNS(self::NS, 'Contacts');
        $documentEl->appendChild($wrapEl);

        foreach ($this->documentContactMapper->findByDocument($documentId) as $c) {
            $el = $doc->createElementNS(self::NS, 'Contact');
            $wrapEl->appendChild($el);
            $this->appendFields($doc, $el, $this->contactFieldsFromEntity($c, $roleNames));
        }
    }

    private function appendDocumentNotes(DOMDocument $doc, DOMElement $documentEl, int $documentId): void {
        $wrapEl = $doc->createElementNS(self::NS, 'Notes');
        $documentEl->appendChild($wrapEl);

        foreach ($this->documentNoteMapper->findByDocument($documentId) as $note) {
            $el = $doc->createElementNS(self::NS, 'Note');
            $wrapEl->appendChild($el);
            $this->appendFields($doc, $el, $this->documentNoteFieldsFromEntity($note));
        }
    }

    private function appendWorkflowHistory(DOMDocument $doc, DOMElement $documentEl, int $documentId): void {
        $wrapEl = $doc->createElementNS(self::NS, 'WorkflowHistory');
        $documentEl->appendChild($wrapEl);

        foreach ($this->docWorkflowMapper->findByDocument($documentId) as $workflow) {
            $el = $doc->createElementNS(self::NS, 'Workflow');
            $wrapEl->appendChild($el);
            $this->appendFields($doc, $el, $this->workflowFieldsFromEntity($workflow));

            $stepsEl = $doc->createElementNS(self::NS, 'Steps');
            $el->appendChild($stepsEl);
            foreach ($this->docWorkflowStepMapper->findByWorkflow($workflow->getId()) as $step) {
                $stepEl = $doc->createElementNS(self::NS, 'Step');
                $stepsEl->appendChild($stepEl);
                $this->appendFields($doc, $stepEl, $this->workflowStepFieldsFromEntity($step));
            }
        }
    }

    // ---------------------------------------------------------------
    // Document-level field builders (pure — no disk I/O, no audit log
    // writes). Shared by the closed-case export (which wraps these in DOM
    // via the append* methods above) and the public data API's
    // documents/journal-notes lookups.
    // ---------------------------------------------------------------

    /**
     * Build the DocumentType field maps for a case's documents (Files,
     * Contacts, Notes, WorkflowHistory nested), matching
     * appinfo/schema/case-export.xsd. Unlike the closed-case export, this
     * does not copy files to disk or write audit logs — read-only lookup.
     *
     * @return list<array<string, mixed>>
     */
    public function documentFields(int $caseId): array {
        $documentStatusNames   = $this->documentStatusMapper->getNameMap(self::LOOKUP_LANGUAGE);
        $documentCategoryNames = $this->documentCategoryMapper->getNameMap(self::LOOKUP_LANGUAGE);
        $contactRoleNames      = $this->contactRoleMapper->getNameMap(self::LOOKUP_LANGUAGE);

        return array_map(
            fn ($document) => $this->singleDocumentFields($document, $documentStatusNames, $documentCategoryNames, $contactRoleNames),
            $this->documentMapper->findByCase($caseId),
        );
    }

    /**
     * Build the DocumentType field map (Files, Contacts, Notes,
     * WorkflowHistory nested) for a single document, matching
     * appinfo/schema/case-export.xsd. Shared by documentFields() (a case's
     * document list) and the public data API's single-document lookup.
     *
     * @param array<int, string>|null $documentStatusNames
     * @param array<int, string>|null $documentCategoryNames
     * @param array<int, string>|null $contactRoleNames
     * @return array<string, mixed>
     */
    public function singleDocumentFields(
        Document $document,
        ?array $documentStatusNames = null,
        ?array $documentCategoryNames = null,
        ?array $contactRoleNames = null,
    ): array {
        $documentStatusNames   ??= $this->documentStatusMapper->getNameMap(self::LOOKUP_LANGUAGE);
        $documentCategoryNames ??= $this->documentCategoryMapper->getNameMap(self::LOOKUP_LANGUAGE);
        $contactRoleNames      ??= $this->contactRoleMapper->getNameMap(self::LOOKUP_LANGUAGE);

        $documentId = $document->getId();

        $fields = $this->documentFieldsFromEntity($document, $documentStatusNames, $documentCategoryNames);

        $fields['Files'] = array_map(
            fn ($file) => $this->fileFieldsFromEntity($file, $documentId),
            $this->fileMapper->findByDocument($documentId),
        );
        $fields['Contacts'] = array_map(
            fn ($contact) => $this->contactFieldsFromEntity($contact, $contactRoleNames),
            $this->documentContactMapper->findByDocument($documentId),
        );
        $fields['Notes'] = array_map(
            fn ($note) => $this->documentNoteFieldsFromEntity($note),
            $this->documentNoteMapper->findByDocument($documentId),
        );
        $fields['WorkflowHistory'] = $this->workflowHistoryFieldsForDocument($documentId);

        return $fields;
    }

    /** @return list<array<string, mixed>> */
    private function workflowHistoryFieldsForDocument(int $documentId): array {
        $result = [];
        foreach ($this->docWorkflowMapper->findByDocument($documentId) as $workflow) {
            $fields = $this->workflowFieldsFromEntity($workflow);
            $fields['Steps'] = array_map(
                fn ($step) => $this->workflowStepFieldsFromEntity($step),
                $this->docWorkflowStepMapper->findByWorkflow($workflow->getId()),
            );
            $result[] = $fields;
        }
        return $result;
    }

    /**
     * @param array<int, string> $documentStatusNames
     * @param array<int, string> $documentCategoryNames
     * @return array<string, string|null>
     */
    private function documentFieldsFromEntity(Document $document, array $documentStatusNames, array $documentCategoryNames): array {
        $documentCategoryId = $document->getDocumentCategoryId();
        $insightLevelId     = $document->getInsightLevelId();

        return [
            'Id'                    => (string)$document->getId(),
            'DocumentNumber'        => $document->getDocumentNumber(),
            'Title'                 => $document->getTitle(),
            'DocumentType'          => $document->getDocumentType(),
            'Status'                => (string)$document->getStatus(),
            'StatusName'            => $documentStatusNames[$document->getStatus()] ?? null,
            'DocumentCategoryId'    => $this->intOrNull($documentCategoryId),
            'DocumentCategoryName'  => $documentCategoryId !== null ? ($documentCategoryNames[$documentCategoryId] ?? null) : null,
            'InsightLevelId'        => $this->intOrNull($insightLevelId),
            'InsightLevelName'      => $insightLevelId !== null
                ? ($this->insightLevelMapper->getNamesByIds([$insightLevelId], self::LOOKUP_LANGUAGE)[$insightLevelId] ?? null)
                : null,
            'Uuid'                  => $document->getUuid(),
            'DocumentDate'          => $document->getDocumentDate(),
            'ReceivedDate'          => $document->getReceivedDate(),
            'RegisteredDate'        => $document->getRegisteredDate(),
            'CreatedAt'             => $this->formatDateTime($document->getCreatedAt()),
            'UpdatedAt'             => $this->formatDateTime($document->getUpdatedAt()),
            'CreatedBy'             => $document->getCreatedBy(),
        ];
    }

    /**
     * Build the FileType field map for a single file, matching
     * appinfo/schema/case-export.xsd. Public so the public data API's
     * file-upload endpoints can reuse it for their response shape.
     *
     * @return array<string, string|null>
     */
    public function fileFieldsFromEntity($file, int $documentId): array {
        return [
            'Id'                => (string)$file->getId(),
            'Uuid'              => $file->getUuid(),
            'OriginalFilename'  => $file->getOriginalFilename(),
            'RelativePath'      => 'files/' . $documentId . '/' . $this->sanitizeSegment($file->getOriginalFilename()),
            'MimeType'          => $file->getMimeType(),
            'Size'              => (string)$file->getSize(),
            'Version'           => (string)$file->getVersion(),
            'Checksum'          => $file->getChecksum(),
            'CreatedAt'         => $this->formatDateTime($file->getCreatedAt()),
            'UpdatedAt'         => $this->formatDateTime($file->getUpdatedAt()),
            'CreatedBy'         => $file->getCreatedBy(),
            'LastModifiedBy'    => $file->getLastModifiedBy(),
        ];
    }

    /**
     * @param array<int, string> $roleNames
     * @return array<string, string|null>
     */
    private function contactFieldsFromEntity($contact, array $roleNames): array {
        return [
            'Id'                    => (string)$contact->getId(),
            'RoleId'                => (string)$contact->getContactroleId(),
            'RoleName'              => $roleNames[$contact->getContactroleId()] ?? null,
            'CprCvr'                => $contact->getCprCvr(),
            'Pnumber'               => $contact->getPnumber(),
            'Name'                  => $contact->getName(),
            'Streetname'            => $contact->getStreetname(),
            'Housenumber'           => $contact->getHousenumber(),
            'Floor'                 => $contact->getFloor(),
            'Door'                  => $contact->getDoor(),
            'Zipcode'               => $contact->getZipcode(),
            'Zipdistrict'           => $contact->getZipdistrict(),
            'Phone'                 => $contact->getPhone(),
            'Email'                 => $contact->getEmail(),
            'UpdatedDate'           => $this->formatDateTime($contact->getUpdatedDate()),
            'HasAddressProtection'  => $contact->getHasAddressProtection() ? 'true' : 'false',
        ];
    }

    /** @return array<string, string|null> */
    /**
     * Public so the public data API's document-note-adding endpoint can
     * reuse it for its response shape.
     *
     * @return array<string, string|null>
     */
    public function documentNoteFieldsFromEntity($note): array {
        return [
            'Id'        => (string)$note->getId(),
            'Title'     => $note->getTitle(),
            'Text'      => $note->getText(),
            'CreatedAt' => $this->formatDateTime($note->getCreatedAt()),
            'CreatedBy' => $note->getCreatedBy(),
            'UpdatedAt' => $this->formatDateTime($note->getUpdatedAt()),
        ];
    }

    /** @return array<string, string|null> */
    private function workflowFieldsFromEntity($workflow): array {
        return [
            'Id'        => (string)$workflow->getId(),
            'Type'      => $workflow->getType(),
            'Status'    => $workflow->getStatus(),
            'Deadline'  => $this->formatDateTime($workflow->getDeadline()),
            'CreatedBy' => $workflow->getCreatedBy(),
            'CreatedAt' => $this->formatDateTime($workflow->getCreatedAt()),
            'UpdatedAt' => $this->formatDateTime($workflow->getUpdatedAt()),
        ];
    }

    /** @return array<string, string|null> */
    private function workflowStepFieldsFromEntity($step): array {
        return [
            'Id'          => (string)$step->getId(),
            'UserId'      => $step->getUserId(),
            'DisplayName' => $this->displayName($step->getUserId()),
            'SortOrder'   => (string)$step->getSortOrder(),
            'Status'      => $step->getStatus(),
            'Comment'     => $step->getComment(),
            'Deadline'    => $this->formatDateTime($step->getDeadline()),
            'ActedAt'     => $this->formatDateTime($step->getActedAt()),
        ];
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
    public function appendFields(DOMDocument $doc, DOMElement $parent, array $fields): void {
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
