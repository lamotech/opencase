<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\AccessProfileMapper;
use OCA\OpenCase\Db\CaseEntity;
use OCA\OpenCase\Db\CaseStatusMapper;
use OCA\OpenCase\Db\ClassificationFacetRepository;
use OCA\OpenCase\Db\ClassificationSubjectMapper;
use OCA\OpenCase\Db\Document;
use OCA\OpenCase\Db\DocumentCategoryMapper;
use OCA\OpenCase\Db\DocumentStatusMapper;
use OCA\OpenCase\Db\InsightLevelMapper;
use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Db\SensitivityMapper;
use OCA\OpenCase\Service\AccessProfile\AccessGroupNaming;
use OCA\OpenCase\Db\UserInfoMapper;
use OCP\IUserManager;

/**
 * Merges case and document metadata into a template file.
 *
 * Supported formats (all ZIP-based):
 *   .docx  — application/vnd.openxmlformats-officedocument.wordprocessingml.document
 *   .xlsx  — application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
 *   .odt   — application/vnd.oasis.opendocument.text
 *   .ods   — application/vnd.oasis.opendocument.spreadsheet
 *
 * Placeholder syntax: {{name}} (case-insensitive, Danish and English forms).
 *
 * Supported placeholders:
 *   {{sag.nummer}}       / {{case.number}}       — Case number
 *   {{sag.titel}}        / {{case.title}}        — Case title
 *   {{sag.organisation}} / {{case.organisation}} — Organisation name
 *   {{sag.ansvarlig}}   / {{case.responsible}}  — Responsible user display name
 *   {{sag.ansvarlig.email}}   / {{case.responsible.email}}    — … e-mail (opencase_userinfo)
 *   {{sag.ansvarlig.telefon}} / {{case.responsible.phone}}    — … phone (opencase_userinfo)
 *   {{sag.ansvarlig.adresse}} / {{case.responsible.location}} — … location (opencase_userinfo)
 *   {{sag.kle}}          / {{case.kle}}          — KLE classification code
 *   {{sag.handlingsfacet}} / {{case.actionfacet}} — Action facet ("code title")
 *   {{sag.følsomhed}}    / {{case.sensitivity}}  — Sensitivity title
 *   {{sag.gruppe}}       / {{case.group}}        — Access group name (Grupper)
 *   {{sag.indsigtsgrad}} / {{case.insightlevel}} — Insight level name
 *   {{sag.status}}       / {{case.status}}       — Case status name
 *   {{sag.oprettet}}     / {{case.created}}      — Case creation date (dd.mm.yyyy)
 *   {{organisation.email}}                       — The case organisation's e-mail
 *   {{organisation.telefon}} / {{organisation.phone}}    — … phone
 *   {{organisation.adresse}} / {{organisation.location}} — … location
 *   {{organisation.logo}}                        — The organisation's logo (see LogoComposer;
 *                                                  {{organisation.logo}} still work)
 *   {{dokument.nummer}}  / {{document.number}}   — Document number
 *   {{dokument.type}}    / {{document.type}}     — Document type
 *   {{dokument.titel}}   / {{document.title}}    — Document title
 *   {{dokument.kategori}} / {{document.category}} — Document category name
 *   {{dokument.oprettet}}/ {{document.created}}  — Document creation date (dd.mm.yyyy)
 *   {{dokument.dokumentdato}}    / {{document.documentdate}}   — Document date (dd.mm.yyyy)
 *   {{dokument.modtagetdato}}    / {{document.receiveddate}}   — Received date (dd.mm.yyyy)
 *   {{dokument.registreretdato}} / {{document.registereddate}} — Registered date (dd.mm.yyyy)
 *   {{dokument.indsigtsgrad}}    / {{document.insightlevel}}   — Insight level name
 *   {{fraser.indhold}}   / {{phrases.content}}   — Content of the selected phrases (see PhraseComposer)
 *
 * Split-run fix: DOCX (and XLSX) stores text as XML runs. Spellcheckers and
 * editors sometimes split a placeholder like {{CASE.NUMBER}} across multiple
 * <w:r> elements. The merge step fixes this by stripping intermediate XML tags
 * from within a matched {{...}} pattern before substituting values.
 */
class TemplateMergeService {

    public function __construct(
        private OrganisationMapper $organisationMapper,
        private AccessProfileMapper $accessProfileMapper,
        private ClassificationSubjectMapper $classificationSubjectMapper,
        private SensitivityMapper $sensitivityMapper,
        private CaseStatusMapper $caseStatusMapper,
        private DocumentStatusMapper $documentStatusMapper,
        private IUserManager $userManager,
        private AccessGroupNaming $accessGroupNaming,
        private UserInfoMapper $userInfoMapper,
        private InsightLevelMapper $insightLevelMapper,
        private DocumentCategoryMapper $documentCategoryMapper,
        private ClassificationFacetRepository $classificationFacetRepository,
        private PhraseComposer $phraseComposer,
        private LogoComposer $logoComposer,
        private LogoService $logoService,
    ) {}

    // ---------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------

    /**
     * Build the flat placeholder → value map for a case + document.
     *
     * @return array<string, string>  Keys are lowercase placeholder names (without {{ }}).
     */
    public function buildValues(CaseEntity $case, Document $document): array {
        // Resolve organisation name and contact details
        $org     = $this->organisationMapper->findByUuid($case->getOrgUuid());
        $orgName = $org?->getOrgName() ?? '';

        // Resolve responsible user display name
        $responsibleUid  = $case->getResponsibleUserId() ?? '';
        $responsibleName = $responsibleUid !== ''
            ? ($this->userManager->get($responsibleUid)?->getDisplayName() ?? $responsibleUid)
            : '';

        // Resolve KLE code and sensitivity title (the case's own or its access
        // profile's) and group name (Grupper)
        $profile          = $this->accessProfileMapper->findById($case->getAccessProfileId());
        $classSubjectUuid = $case->effectiveClassSubjectUuid($profile);
        $sensitivityUuid  = $case->effectiveSensitivityUuid($profile);
        $groupId          = $profile?->getGroupId();

        $kleCode = $classSubjectUuid !== null
            ? ($this->classificationSubjectMapper->findByUuid($classSubjectUuid)?->getCode() ?? '')
            : '';
        $sensitivityTitle = $sensitivityUuid !== null
            ? ($this->sensitivityMapper->findByUuid($sensitivityUuid)?->getTitle() ?? '')
            : '';
        $groupName = $groupId !== null
            ? $this->accessGroupNaming->displayName($groupId)
            : '';

        // Resolve case status name (Danish)
        $caseStatusNames = $this->caseStatusMapper->getNamesByIds([$case->getStatusId()], 'da');
        $caseStatusName  = $caseStatusNames[$case->getStatusId()]['name'] ?? '';

        // Resolve document status name (Danish)
        $docStatusMap   = $this->documentStatusMapper->getNameMap('da');
        $docStatusName  = $docStatusMap[$document->getStatus()] ?? '';

        // Responsible user's contact details (opencase_userinfo)
        $responsibleInfo = $responsibleUid !== ''
            ? ($this->userInfoMapper->findByUserId($responsibleUid) ?? $this->userInfoMapper->findByUsername($responsibleUid))
            : null;

        // Insight levels (case and document) and document category (Danish)
        $insightNames = $this->insightLevelMapper->getNamesByIds(
            array_values(array_filter([$case->getInsightLevelId(), $document->getInsightLevelId()])),
            'da',
        );
        $caseInsight = $insightNames[$case->getInsightLevelId()] ?? '';
        $docInsight  = $insightNames[$document->getInsightLevelId()] ?? '';
        $docCategory = $document->getDocumentCategoryId() !== null
            ? ($this->documentCategoryMapper->getNameMap('da')[$document->getDocumentCategoryId()] ?? '')
            : '';

        // Action facet ("handlingsfacet") as "code title"
        $facet = $case->getClassificationFacetUuid() !== null
            ? $this->classificationFacetRepository->find($case->getClassificationFacetUuid())
            : null;
        $facetText = $facet !== null ? trim(($facet['code'] ?? '') . ' ' . ($facet['title'] ?? '')) : '';

        $caseCreated = $case->getCreatedAt()?->format('d.m.Y') ?? '';
        $docDate       = $this->formatDate($document->getDocumentDate());
        $docReceived   = $this->formatDate($document->getReceivedDate());
        $docRegistered = $this->formatDate($document->getRegisteredDate());

        $orgEmail    = $org?->getEmail() ?? '';
        $orgPhone    = $org?->getPhone() ?? '';
        $orgLocation = $org?->getLocation() ?? '';
        $docCreated  = $document->getCreatedAt()?->format('d.m.Y') ?? '';

        return [
            // Danish forms
            'sag.nummer'         => $case->getCaseNumber(),
            'sag.titel'          => $case->getTitle(),
            'sag.organisation'   => $orgName,
            'sag.ansvarlig'      => $responsibleName,
            'sag.kle'            => $kleCode,
            'sag.følsomhed'      => $sensitivityTitle,
            'sag.gruppe'         => $groupName,
            'sag.status'         => $caseStatusName,
            'sag.oprettet'       => $caseCreated,
            'dokument.nummer'    => $document->getDocumentNumber() ?? '',
            'dokument.type'      => $document->getDocumentType() ?? '',
            'dokument.titel'     => $document->getTitle(),
            'dokument.oprettet'  => $docCreated,
            'dokument.status'    => $docStatusName,
            'sag.ansvarlig.email'        => $responsibleInfo?->getEmail() ?? '',
            'sag.ansvarlig.telefon'      => $responsibleInfo?->getPhone() ?? '',
            'sag.ansvarlig.adresse'      => $responsibleInfo?->getLocation() ?? '',
            'sag.indsigtsgrad'           => $caseInsight,
            'sag.handlingsfacet'         => $facetText,
            'dokument.kategori'          => $docCategory,
            'dokument.dokumentdato'      => $docDate,
            'dokument.modtagetdato'      => $docReceived,
            'dokument.registreretdato'   => $docRegistered,
            'dokument.indsigtsgrad'      => $docInsight,
            'organisation.email'         => $orgEmail,
            'organisation.telefon'       => $orgPhone,
            'organisation.adresse'       => $orgLocation,

            // English forms (same values)
            'case.number'        => $case->getCaseNumber(),
            'case.title'         => $case->getTitle(),
            'case.organisation'  => $orgName,
            'case.responsible'   => $responsibleName,
            'case.kle'           => $kleCode,
            'case.sensitivity'   => $sensitivityTitle,
            'case.group'         => $groupName,
            'case.status'        => $caseStatusName,
            'case.created'       => $caseCreated,
            'document.number'    => $document->getDocumentNumber() ?? '',
            'document.type'      => $document->getDocumentType() ?? '',
            'document.title'     => $document->getTitle(),
            'document.created'   => $docCreated,
            'document.status'    => $docStatusName,
            'case.responsible.email'    => $responsibleInfo?->getEmail() ?? '',
            'case.responsible.phone'    => $responsibleInfo?->getPhone() ?? '',
            'case.responsible.location' => $responsibleInfo?->getLocation() ?? '',
            'case.insightlevel'        => $caseInsight,
            'case.actionfacet'         => $facetText,
            'document.category'        => $docCategory,
            'document.documentdate'    => $docDate,
            'document.receiveddate'    => $docReceived,
            'document.registereddate'  => $docRegistered,
            'document.insightlevel'    => $docInsight,
            'organisation.phone'       => $orgPhone,
            'organisation.location'    => $orgLocation,
        ];
    }

    /**
     * A stored date ("YYYY-MM-DD", possibly with a time) as dd.mm.yyyy.
     */
    private function formatDate(?string $date): string {
        if ($date === null || trim($date) === '') {
            return '';
        }
        $parsed = \DateTime::createFromFormat('!Y-m-d', substr(trim($date), 0, 10));
        return $parsed !== false ? $parsed->format('d.m.Y') : $date;
    }

    /**
     * The logo to merge into a file for this case: the case organisation's,
     * or its nearest ancestor's (null when none has a logo).
     *
     * @return array{path: string, mime_type: string, width: int, height: int, org_uuid: string, org_name: string}|null
     */
    public function logoForCase(CaseEntity $case): ?array {
        return $this->logoService->resolve($case->getOrgUuid());
    }

    /**
     * Copy the template to a temp file, replace all placeholders, and return
     * the path to the resulting file.
     *
     * $phrases are the phrase documents merged into {{fraser.indhold}} /
     * {{phrases.content}}, in order. Placeholders inside the phrases are
     * merged too. $logo (see logoForCase()) is inserted at
     * {{organisation.logo}}; without one that placeholder is removed.
     *
     * The caller is responsible for deleting the temp file after use.
     *
     * @param array<string, string> $values  From buildValues()
     * @param array{path: string, mime_type: string}[] $phrases
     * @param array{path: string, mime_type: string, width: int, height: int, org_name: string}|null $logo
     */
    public function mergeToTempFile(string $sourcePath, string $mimeType, array $values, array $phrases = [], ?array $logo = null): string {
        $ext      = pathinfo($sourcePath, PATHINFO_EXTENSION);
        $tempPath = sys_get_temp_dir()
            . '/opencase_tpl_' . bin2hex(random_bytes(8))
            . ($ext !== '' ? '.' . $ext : '');

        if (!copy($sourcePath, $tempPath)) {
            throw new \RuntimeException("Failed to copy template to temp file: {$sourcePath}");
        }

        if ($this->isZipFormat($mimeType)) {
            $this->mergeZip($tempPath, $mimeType, $values, $phrases, $logo);
        }
        // Non-ZIP formats (PDF, plain text, etc.) are returned as-is.

        return $tempPath;
    }

    // ---------------------------------------------------------------
    // ZIP-based merging
    // ---------------------------------------------------------------

    /**
     * @param array{path: string, mime_type: string}[] $phrases
     * @param array{path: string, mime_type: string, width: int, height: int, org_name: string}|null $logo
     */
    private function mergeZip(string $path, string $mimeType, array $values, array $phrases, ?array $logo): void {
        $zip = new \ZipArchive();
        $result = $zip->open($path);
        if ($result !== true) {
            throw new \RuntimeException("Failed to open template as ZIP: {$path} (error {$result})");
        }

        // The main body entry gets phrase paragraphs; anywhere else (or inline
        // in a paragraph with other text) the phrases are filled in as text.
        $bodyEntry = match (true) {
            $this->isDocxMime($mimeType)             => 'word/document.xml',
            str_contains($mimeType, 'opendocument.text') => 'content.xml',
            default                                  => null,
        };
        $phraseText = null;
        // XML parts changed by the composers (name => XML; null marks a binary
        // part already added to the ZIP). Each part is written once at the
        // end — reading back a part replaced earlier in the same ZipArchive
        // session is not reliable.
        $parts = [];
        // Logo placeholders left after the logo composer (e.g. spreadsheets) are emptied.
        $values = array_fill_keys(LogoComposer::PLACEHOLDERS, '') + $values;

        foreach ($this->getXmlEntries($mimeType, $zip) as $entry) {
            $xml = $parts[$entry] ?? $zip->getFromName($entry);
            if ($xml === false) {
                continue;
            }
            $xml = $this->fixSplitRuns($xml);

            // Phrase placeholders first, so fields inside phrase text are merged too.
            $entryValues = $values;
            if ($this->phraseComposer->containsPlaceholder($xml)) {
                if ($entry === $bodyEntry) {
                    $xml = $this->isDocxMime($mimeType)
                        ? $this->phraseComposer->composeDocx($zip, $xml, $phrases, $parts)
                        : $this->phraseComposer->composeOdt($zip, $xml, $phrases, $parts);
                }
                $phraseText ??= $this->phraseComposer->plainText($phrases);
                $entryValues = array_fill_keys(PhraseComposer::PLACEHOLDERS, $phraseText) + $values;
            }

            // After the phrases, so a logo placeholder inside a phrase is filled too.
            if ($this->logoComposer->containsPlaceholder($xml)) {
                if ($this->isDocxMime($mimeType)) {
                    $xml = $this->logoComposer->composeDocx($zip, $entry, $xml, $logo, $parts);
                } elseif (str_contains($mimeType, 'opendocument.text')) {
                    $xml = $this->logoComposer->composeOdt($zip, $xml, $logo, $parts);
                }
            }

            $parts[$entry] = $this->applyValues($xml, $entryValues, $mimeType);
        }

        foreach ($parts as $name => $xml) {
            if ($xml !== null) {
                $zip->addFromString($name, $xml);
            }
        }

        $zip->close();
    }

    /**
     * Return the ZIP entry names (XML files) that should be searched for placeholders.
     *
     * @return string[]
     */
    private function getXmlEntries(string $mimeType, \ZipArchive $zip): array {
        if ($this->isDocxMime($mimeType)) {
            return $this->docxEntries($zip);
        }

        if ($this->isXlsxMime($mimeType)) {
            return $this->xlsxEntries($zip);
        }

        // ODT / ODS (OpenDocument)
        if ($this->isOpenDocumentMime($mimeType)) {
            return $this->openDocumentEntries($zip);
        }

        return [];
    }

    /** @return string[] */
    private function docxEntries(\ZipArchive $zip): array {
        $entries = ['word/document.xml'];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^word/(header|footer)\d+\.xml$#', $name)) {
                $entries[] = $name;
            }
        }
        return $entries;
    }

    /** @return string[] */
    private function xlsxEntries(\ZipArchive $zip): array {
        $entries = [];
        // Shared strings (cell text references)
        if ($zip->locateName('xl/sharedStrings.xml') !== false) {
            $entries[] = 'xl/sharedStrings.xml';
        }
        // Worksheets (inline strings / formula strings)
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                $entries[] = $name;
            }
        }
        return $entries;
    }

    /** @return string[] */
    private function openDocumentEntries(\ZipArchive $zip): array {
        $entries = [];
        // content.xml holds body text; styles.xml holds headers/footers
        foreach (['content.xml', 'styles.xml'] as $name) {
            if ($zip->locateName($name) !== false) {
                $entries[] = $name;
            }
        }
        return $entries;
    }

    // ---------------------------------------------------------------
    // Placeholder substitution
    // ---------------------------------------------------------------

    /**
     * Fix split runs, then substitute all {{placeholder}} values in an XML string.
     *
     * "Split runs" problem: DOCX/XLSX editors sometimes break a run like
     *   <w:t>{{case.</w:t></w:r><w:r><w:t>number}}</w:t>
     * into multiple XML runs. The fixSplitRuns step reconstructs them by
     * stripping intra-placeholder XML tags before substitution.
     *
     * @param array<string, string> $values
     */
    private function applyValues(string $xml, array $values, string $mimeType): string {
        $xml = $this->fixSplitRuns($xml);

        // Line breaks and tabs in a value (multi-line phrase text) as markup.
        $breaks = match (true) {
            $this->isDocxMime($mimeType) => [
                "\n" => '</w:t><w:br/><w:t xml:space="preserve">',
                "\t" => '</w:t><w:tab/><w:t xml:space="preserve">',
            ],
            str_contains($mimeType, 'opendocument.text') => ["\n" => '<text:line-break/>', "\t" => '<text:tab/>'],
            default => [],
        };

        foreach ($values as $placeholder => $value) {
            $xml = str_ireplace(
                '{{' . $placeholder . '}}',
                strtr(htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8'), $breaks),
                $xml,
            );
        }

        return $xml;
    }

    /**
     * Repair placeholders an editor split with XML markup, e.g.
     *   <w:t>{{case.</w:t></w:r><w:r><w:t>number}}</w:t>
     *
     * Only markup may sit between the two opening and between the two
     * closing braces, and the text inside must look like a field name
     * (letters, digits, "_", "." and spaces). Lone braces elsewhere in the
     * XML — such as Word's extension URIs uri="{96DAC541-…}" — are never
     * taken for the start of a placeholder.
     *
     * The markup is moved behind the placeholder rather than dropped, so the
     * XML stays balanced when the placeholder was split by an opening tag
     * without its closing tag (e.g. ODT "{{sag<text:span>.titel}}</text:span>").
     * The placeholder keeps the formatting of the run it starts in.
     */
    private function fixSplitRuns(string $xml): string {
        return preg_replace_callback(
            '/\{(?:<[^>]*>)*\{((?:[^{}<]|<[^>]*>)*)\}(?:<[^>]*>)*\}/u',
            static function (array $m): string {
                if (!str_contains($m[0], '<')) {
                    return $m[0];
                }
                if (!preg_match('/^\s*[\p{L}\p{N}_.]+(?:\s+[\p{L}\p{N}_.]+)*\s*$/u', strip_tags($m[1]))) {
                    return $m[0];
                }
                preg_match_all('/<[^>]*>/', $m[0], $tags);
                return strip_tags($m[0]) . implode('', $tags[0]);
            },
            $xml,
        ) ?? $xml;
    }

    // ---------------------------------------------------------------
    // MIME type helpers
    // ---------------------------------------------------------------

    private function isZipFormat(string $mimeType): bool {
        return $this->isDocxMime($mimeType)
            || $this->isXlsxMime($mimeType)
            || $this->isOpenDocumentMime($mimeType);
    }

    private function isDocxMime(string $mimeType): bool {
        return str_contains($mimeType, 'wordprocessingml');
    }

    private function isXlsxMime(string $mimeType): bool {
        return str_contains($mimeType, 'spreadsheetml');
    }

    private function isOpenDocumentMime(string $mimeType): bool {
        return str_contains($mimeType, 'opendocument');
    }
}
