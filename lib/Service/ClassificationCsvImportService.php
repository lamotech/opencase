<?php
declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\ClassificationFacetRepository;
use OCA\OpenCase\Db\ClassificationPlanRepository;
use OCA\OpenCase\Db\ClassificationSubjectRepository;

/**
 * Manual CSV import for KLE classifications (Emneplan) and handling facets
 * (Handlingsfacetter) — the enterprise_version=0 fallback for
 * ClassificationSyncService's Datafordeler-sourced sync (which is an
 * Enterprise-only component). Upserts rows and deactivates anything not
 * present in the file, the same way ClassificationSyncService::syncItems()
 * does for the Datafordeler-sourced sync.
 *
 * Expected CSV format (';'-delimited, header row required):
 *   uuid;code;title;description;active
 *
 * The customer's own Emneplan (opencase_class_plan) uses the same format,
 * but uuid may be left empty there: the row is then matched on code, and a
 * new item gets a generated UUID. code may be left empty too, for plans that
 * identify a topic by its name — the title is then used as the code.
 * description and active may be omitted (active defaults to 1). An Emneplan
 * file may also have a 6th column, deletion_deadline: the item's slettefrist
 * by code (e.g. P1825D), name (e.g. "5 år") or id. Empty clears it; without
 * the column, items keep their slettefrist.
 */
class ClassificationCsvImportService {

    public function __construct(
        private ClassificationSubjectRepository $subjectRepo,
        private ClassificationFacetRepository $facetRepo,
        private ClassificationPlanRepository $planRepo,
        private ClassificationPlanService $planService,
    ) {}

    /**
     * @return array{fetched: int, created: int, updated: int, deactivated: int}
     */
    public function importSubjects(string $csvContent): array {
        return $this->syncRows($this->parseCsv($csvContent), $this->subjectRepo);
    }

    /**
     * @return array{fetched: int, created: int, updated: int, deactivated: int}
     */
    public function importFacets(string $csvContent): array {
        return $this->syncRows($this->parseCsv($csvContent), $this->facetRepo);
    }

    /**
     * Rows are all resolved before anything is written, so an unknown
     * slettefrist rejects the whole file.
     *
     * @return array{fetched: int, created: int, updated: int, deactivated: int}
     * @throws \InvalidArgumentException on an unknown slettefrist, or when the file has no items
     */
    public function importPlan(string $csvContent): array {
        $rows = [];
        $seenCodes = [];
        foreach ($this->parseCsv($csvContent, true) as $row) {
            if ($row['code'] === '') {
                $row['code'] = $row['title'];
            }
            if ($row['code'] === '' || isset($seenCodes[$row['code']])) {
                continue;
            }
            $seenCodes[$row['code']] = true;
            if ($row['uuid'] === '') {
                $row['uuid'] = $this->planRepo->findByCode($row['code'])['uuid'] ?? bin2hex(random_bytes(16));
            }
            if ($row['title'] === '') {
                $row['title'] = $row['code'];
            }
            if ($row['deletion_deadline'] !== null) {
                try {
                    $row['deletion_deadline_id'] = $this->planService->resolveDeletionDeadline($row['deletion_deadline']);
                } catch (\InvalidArgumentException $e) {
                    throw new \InvalidArgumentException("{$row['title']}: {$e->getMessage()}", 0, $e);
                }
            }
            $rows[] = $row;
        }
        // Items missing from the file are deactivated, so a file without a
        // single usable row (wrong file, wrong delimiter, header only) would
        // deactivate the whole plan.
        if ($rows === []) {
            throw new \InvalidArgumentException('The file contains no Emneplan items');
        }
        return $this->syncRows($rows, $this->planRepo);
    }

    /**
     * @param bool $lenient Emneplan files: uuid may be empty, and the
     *                      description/active columns may be left out.
     * @return array<int, array{uuid: string, code: string, title: string, description: string, active: bool}>
     */
    private function parseCsv(string $csvContent, bool $lenient = false): array {
        // Excel's default "CSV" export is Windows-1252, not UTF-8 — convert
        // it up front so non-ASCII characters (æ, ø, å, ...) don't reach the
        // (UTF-8) database as raw Latin-1 bytes and get rejected.
        if (!mb_check_encoding($csvContent, 'UTF-8')) {
            $csvContent = mb_convert_encoding($csvContent, 'UTF-8', 'Windows-1252');
        }

        // Strip a UTF-8 BOM if present (common with Excel-exported CSVs)
        $csvContent = preg_replace('/^\xEF\xBB\xBF/', '', $csvContent) ?? $csvContent;

        // Parse via a stream rather than pre-splitting on newlines: quoted
        // fields (e.g. multi-paragraph descriptions) legitimately contain
        // embedded newlines, and splitting the raw text first would chop
        // those rows into fragments that fgetcsv/str_getcsv can't reassemble.
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csvContent);
        rewind($stream);

        $rows = [];
        $rowIndex = 0;
        while (($fields = fgetcsv($stream, 0, ';')) !== false) {
            $isBlankLine = count($fields) === 1 && $fields[0] === null;
            $currentIndex = $rowIndex;
            $rowIndex++;
            if ($isBlankLine) {
                continue;
            }
            if ($currentIndex === 0 && strtolower(trim((string)($fields[0] ?? ''))) === 'uuid') {
                continue;
            }
            if (count($fields) < ($lenient ? 3 : 5)) {
                continue;
            }
            $uuid = trim((string)$fields[0]);
            if ($uuid === '' && !$lenient) {
                continue;
            }
            $active = trim((string)($fields[4] ?? ''));
            $rows[] = [
                'uuid'        => $uuid,
                'code'        => trim((string)$fields[1]),
                'title'       => trim((string)$fields[2]),
                'description' => trim((string)($fields[3] ?? '')),
                'active'      => $active === '1' || ($lenient && $active === ''),
                // null = no deletion_deadline column (Emneplan files only)
                'deletion_deadline' => $lenient && count($fields) >= 6 ? trim((string)$fields[5]) : null,
            ];
        }
        fclose($stream);

        return $rows;
    }

    /**
     * @param array<int, array{uuid: string, code: string, title: string, description: string, active: bool}> $rows
     * @return array{fetched: int, created: int, updated: int, deactivated: int}
     */
    private function syncRows(array $rows, ClassificationSubjectRepository|ClassificationFacetRepository|ClassificationPlanRepository $repo): array {
        $seen = [];
        $created = 0;
        $updated = 0;
        $deactivated = 0;

        foreach ($rows as $row) {
            $seen[$row['uuid']] = true;

            // Only Emneplan rows can carry a slettefrist (deletion_deadline_id)
            $hasDeadline = array_key_exists('deletion_deadline_id', $row);

            $existing = $repo->find($row['uuid']);
            if ($existing === null) {
                $repo->insert($row['uuid'], $row['code'], $row['title'], $row['description'], null, $hasDeadline ? $row['deletion_deadline_id'] : null);
                $created++;
                if (!$row['active']) {
                    $repo->setActive($row['uuid'], false);
                }
            } else {
                $existingDeadline = $existing['deletion_deadline_id'] !== null ? (int)$existing['deletion_deadline_id'] : null;
                $deadline         = $hasDeadline ? $row['deletion_deadline_id'] : $existingDeadline;
                if ($existing['code'] !== $row['code'] || $existing['title'] !== $row['title'] || (string)$existing['description'] !== $row['description']
                    || $deadline !== $existingDeadline) {
                    // The KLE CSV carries no Mapninger, so keep the disposal code / deletion deadline from the last KLE sync
                    $repo->update(
                        $row['uuid'], $row['code'], $row['title'], $row['description'],
                        $existing['disposalcode_id'] !== null ? (int)$existing['disposalcode_id'] : null,
                        $deadline,
                    );
                    $updated++;
                }
                if ((bool)$existing['active'] !== $row['active']) {
                    $repo->setActive($row['uuid'], $row['active']);
                }
            }
        }

        $missing = $repo->findActiveNotIn(array_keys($seen));
        foreach ($missing as $missingRow) {
            $repo->setActive($missingRow['uuid'], false);
            $deactivated++;
        }

        return [
            'fetched'     => count($rows),
            'created'     => $created,
            'updated'     => $updated,
            'deactivated' => $deactivated,
        ];
    }
}
