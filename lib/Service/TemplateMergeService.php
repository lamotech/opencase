<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\AccessProfileMapper;
use OCA\OpenCase\Db\CaseEntity;
use OCA\OpenCase\Db\CaseStatusMapper;
use OCA\OpenCase\Db\ClassificationSubjectMapper;
use OCA\OpenCase\Db\Document;
use OCA\OpenCase\Db\DocumentStatusMapper;
use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Db\SensitivityMapper;
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
 *   {{sag.kle}}          / {{case.kle}}          — KLE classification code
 *   {{sag.følsomhed}}    / {{case.sensitivity}}  — Sensitivity title
 *   {{sag.status}}       / {{case.status}}       — Case status name
 *   {{sag.oprettet}}     / {{case.created}}      — Case creation date (dd.mm.yyyy)
 *   {{dokument.nummer}}  / {{document.number}}   — Document number
 *   {{dokument.type}}    / {{document.type}}     — Document type
 *   {{dokument.titel}}   / {{document.title}}    — Document title
 *   {{dokument.oprettet}}/ {{document.created}}  — Document creation date (dd.mm.yyyy)
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
        // Resolve org name
        $orgName = $this->organisationMapper
            ->findByUuid($case->getOrgUuid())
            ?->getOrgName() ?? '';

        // Resolve responsible user display name
        $responsibleUid  = $case->getResponsibleUserId() ?? '';
        $responsibleName = $responsibleUid !== ''
            ? ($this->userManager->get($responsibleUid)?->getDisplayName() ?? $responsibleUid)
            : '';

        // Resolve KLE code and sensitivity title via access profile
        $kleCode          = '';
        $sensitivityTitle = '';
        $profile = $this->accessProfileMapper->findById($case->getAccessProfileId());
        if ($profile !== null) {
            $kleCode = $this->classificationSubjectMapper
                ->findByUuid($profile->getClassSubjectUuid())
                ?->getCode() ?? '';

            $sensitivityTitle = $this->sensitivityMapper
                ->findByUuid($profile->getSensitivityUuid())
                ?->getTitle() ?? '';
        }

        // Resolve case status name (Danish)
        $caseStatusNames = $this->caseStatusMapper->getNamesByIds([$case->getStatusId()], 'da');
        $caseStatusName  = $caseStatusNames[$case->getStatusId()]['name'] ?? '';

        // Resolve document status name (Danish)
        $docStatusMap   = $this->documentStatusMapper->getNameMap('da');
        $docStatusName  = $docStatusMap[$document->getStatus()] ?? '';

        $caseCreated = $case->getCreatedAt()?->format('d.m.Y') ?? '';
        $docCreated  = $document->getCreatedAt()?->format('d.m.Y') ?? '';

        return [
            // Danish forms
            'sag.nummer'         => $case->getCaseNumber(),
            'sag.titel'          => $case->getTitle(),
            'sag.organisation'   => $orgName,
            'sag.ansvarlig'      => $responsibleName,
            'sag.kle'            => $kleCode,
            'sag.følsomhed'      => $sensitivityTitle,
            'sag.status'         => $caseStatusName,
            'sag.oprettet'       => $caseCreated,
            'dokument.nummer'    => $document->getDocumentNumber() ?? '',
            'dokument.type'      => $document->getDocumentType() ?? '',
            'dokument.titel'     => $document->getTitle(),
            'dokument.oprettet'  => $docCreated,
            'dokument.status'    => $docStatusName,

            // English forms (same values)
            'case.number'        => $case->getCaseNumber(),
            'case.title'         => $case->getTitle(),
            'case.organisation'  => $orgName,
            'case.responsible'   => $responsibleName,
            'case.kle'           => $kleCode,
            'case.sensitivity'   => $sensitivityTitle,
            'case.status'        => $caseStatusName,
            'case.created'       => $caseCreated,
            'document.number'    => $document->getDocumentNumber() ?? '',
            'document.type'      => $document->getDocumentType() ?? '',
            'document.title'     => $document->getTitle(),
            'document.created'   => $docCreated,
            'document.status'    => $docStatusName,
        ];
    }

    /**
     * Copy the template to a temp file, replace all placeholders, and return
     * the path to the resulting file.
     *
     * The caller is responsible for deleting the temp file after use.
     *
     * @param array<string, string> $values  From buildValues()
     */
    public function mergeToTempFile(string $sourcePath, string $mimeType, array $values): string {
        $ext      = pathinfo($sourcePath, PATHINFO_EXTENSION);
        $tempPath = sys_get_temp_dir()
            . '/opencase_tpl_' . bin2hex(random_bytes(8))
            . ($ext !== '' ? '.' . $ext : '');

        if (!copy($sourcePath, $tempPath)) {
            throw new \RuntimeException("Failed to copy template to temp file: {$sourcePath}");
        }

        if ($this->isZipFormat($mimeType)) {
            $this->mergeZip($tempPath, $mimeType, $values);
        }
        // Non-ZIP formats (PDF, plain text, etc.) are returned as-is.

        return $tempPath;
    }

    // ---------------------------------------------------------------
    // ZIP-based merging
    // ---------------------------------------------------------------

    private function mergeZip(string $path, string $mimeType, array $values): void {
        $zip = new \ZipArchive();
        $result = $zip->open($path);
        if ($result !== true) {
            throw new \RuntimeException("Failed to open template as ZIP: {$path} (error {$result})");
        }

        foreach ($this->getXmlEntries($mimeType, $zip) as $entry) {
            $xml = $zip->getFromName($entry);
            if ($xml === false) {
                continue;
            }
            $zip->addFromString($entry, $this->applyValues($xml, $values));
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
    private function applyValues(string $xml, array $values): string {
        $xml = $this->fixSplitRuns($xml);

        foreach ($values as $placeholder => $value) {
            $xml = str_ireplace(
                '{{' . $placeholder . '}}',
                htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
                $xml,
            );
        }

        return $xml;
    }

    /**
     * Strip XML markup that was injected inside a {{...}} placeholder by the editor.
     *
     * Regex explanation:
     *   \{          — opening first brace
     *   [^{]*       — any chars except { (catches XML tags between the two opening braces)
     *   \{          — opening second brace
     *   [^}]*       — placeholder name, possibly interspersed with XML tags
     *   \}          — first closing brace
     *   [^}]*       — any chars except } (catches XML tags between the two closing braces)
     *   \}          — second closing brace
     *
     * The /U (ungreedy) flag keeps each match as short as possible so consecutive
     * placeholders on the same line don't merge into one match.
     *
     * strip_tags() then removes the XML markup, leaving {{placeholder}}.
     */
    private function fixSplitRuns(string $xml): string {
        return preg_replace_callback(
            '/\{[^{]*\{[^}]*\}[^}]*\}/U',
            static fn(array $m): string => strip_tags($m[0]),
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
