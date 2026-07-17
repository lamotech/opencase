<?php
declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\ClassificationFacetRepository;
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
 */
class ClassificationCsvImportService {

    public function __construct(
        private ClassificationSubjectRepository $subjectRepo,
        private ClassificationFacetRepository $facetRepo,
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
     * @return array<int, array{uuid: string, code: string, title: string, description: string, active: bool}>
     */
    private function parseCsv(string $csvContent): array {
        // Strip a UTF-8 BOM if present (common with Excel-exported CSVs)
        $csvContent = preg_replace('/^\xEF\xBB\xBF/', '', $csvContent) ?? $csvContent;
        $lines = preg_split('/\r\n|\r|\n/', trim($csvContent)) ?: [];

        $rows = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $fields = str_getcsv($line, ';');
            if ($i === 0 && strtolower(trim((string)($fields[0] ?? ''))) === 'uuid') {
                continue;
            }
            if (count($fields) < 5) {
                continue;
            }
            $uuid = trim((string)$fields[0]);
            if ($uuid === '') {
                continue;
            }
            $rows[] = [
                'uuid'        => $uuid,
                'code'        => trim((string)$fields[1]),
                'title'       => trim((string)$fields[2]),
                'description' => trim((string)$fields[3]),
                'active'      => trim((string)$fields[4]) === '1',
            ];
        }
        return $rows;
    }

    /**
     * @param array<int, array{uuid: string, code: string, title: string, description: string, active: bool}> $rows
     * @return array{fetched: int, created: int, updated: int, deactivated: int}
     */
    private function syncRows(array $rows, ClassificationSubjectRepository|ClassificationFacetRepository $repo): array {
        $seen = [];
        $created = 0;
        $updated = 0;
        $deactivated = 0;

        foreach ($rows as $row) {
            $seen[$row['uuid']] = true;

            $existing = $repo->find($row['uuid']);
            if ($existing === null) {
                $repo->insert($row['uuid'], $row['code'], $row['title'], $row['description']);
                $created++;
                if (!$row['active']) {
                    $repo->setActive($row['uuid'], false);
                }
            } else {
                if ($existing['code'] !== $row['code'] || $existing['title'] !== $row['title'] || $existing['description'] !== $row['description']) {
                    $repo->update($row['uuid'], $row['code'], $row['title'], $row['description']);
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
