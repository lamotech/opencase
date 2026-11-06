<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\OrganisationRepository;
use OCA\OpenCase\Db\OrgSyncLogRepository;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Imports the organisation structure from a CSV file — for customers without
 * the Serviceplatformen Organisation integration (e.g. Microsoft Entra ID
 * with groups and Emneplan). The result is the same as a Serviceplatformen
 * sync (OrganisationSyncService::sync()): organisations are created or
 * updated (with their org_<uuid> group and team folder when team folders are
 * enabled), organisations missing from the file are deactivated, and the run
 * is logged in opencase_org_log.
 *
 * CSV: ';'-separated, UTF-8 or Windows-1252, with a header row. Columns are
 * matched by header name, in any order:
 *   uuid     — optional. Empty: an existing organisation with the same name
 *              is updated, else a new uuid is generated. Rows sharing a name
 *              need a uuid.
 *   name     — required.
 *   parent   — optional. The parent's uuid, or its name when that is unique
 *              in the file. The parent must be in the file (organisations
 *              missing from it are deactivated). Empty: a top-level
 *              organisation.
 *   group    — optional. The Nextcloud group (e.g. from Microsoft Entra ID)
 *              whose members belong to the organisation; it need not exist
 *              yet. See OrganisationMembershipSyncService. Empty: no members
 *              from a group.
 *   email, phone, location — optional contact details.
 *
 * The whole file is checked before anything is written, so an error (missing
 * name, unknown parent, cycle, duplicate) rejects it without changes.
 *
 * @psalm-type OrgRow = array{line: int, uuid: string, name: string, parent: string, parent_uuid: string, group: ?string, email: ?string, phone: ?string, location: ?string}
 */
class OrganisationCsvImportService {

    private const COLUMNS = ['uuid', 'name', 'parent', 'group', 'email', 'phone', 'location'];

    /** Nextcloud's limit on group ids (oc_groups.gid). */
    private const MAX_GROUP_ID_LENGTH = 64;

    public function __construct(
        private OrganisationRepository $repo,
        private OrganisationSyncService $syncService,
        private OrgSyncLogRepository $syncLogRepository,
        private ITimeFactory $time,
        private IDBConnection $db,
        private OrganisationMembershipSyncService $membershipSyncService,
    ) {}

    /**
     * @return array{fetched: int, created: int, updated: int, deactivated: int}
     * @throws \InvalidArgumentException when the file is invalid
     */
    public function import(string $csvContent): array {
        $rows = $this->resolve($this->parse($csvContent));

        $created = 0;
        $updated = 0;
        $deactivated = 0;

        $this->db->beginTransaction();
        try {
            // Parents before children, so a new organisation's parent exists
            foreach ($this->parentsFirst($rows) as $row) {
                $existing = $this->repo->find($row['uuid']);
                if ($existing === null) {
                    $this->syncService->createOrganisation($row['uuid'], $row['name'], $row['parent_uuid']);
                    $this->repo->updateContactDetails($row['uuid'], $row['email'], $row['phone'], $row['location']);
                    $this->repo->setMemberGroupId($row['uuid'], $row['group']);
                    $created++;
                    continue;
                }

                $changes = $this->changes($existing, $row);
                if (in_array('structure', $changes, true)) {
                    $this->syncService->updateOrganisation($row['uuid'], $row['name'], (int)$existing['groupfolder_id'], $row['parent_uuid']);
                }
                if (in_array('contact', $changes, true)) {
                    $this->repo->updateContactDetails($row['uuid'], $row['email'], $row['phone'], $row['location']);
                }
                if (in_array('group', $changes, true)) {
                    $this->repo->setMemberGroupId($row['uuid'], $row['group']);
                }
                if (in_array('active', $changes, true)) {
                    $this->repo->setActive($row['uuid'], true);
                }
                if ($changes !== []) {
                    $updated++;
                }
            }

            foreach ($this->repo->findActiveNotIn(array_column($rows, 'uuid')) as $missing) {
                $this->repo->setActive($missing['org_uuid'], false);
                $deactivated++;
            }

            $this->syncLogRepository->insert($this->time->getDateTime(), count($rows), $created, $updated, $deactivated);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // New or changed member groups, and deactivated organisations
        $this->membershipSyncService->syncAll();

        return [
            'fetched' => count($rows),
            'created' => $created,
            'updated' => $updated,
            'deactivated' => $deactivated,
        ];
    }

    /**
     * Check the file and work out what import() would do, without changing
     * anything.
     *
     * @return array{fetched: int, created: list<string>, updated: list<string>, deactivated: list<string>}
     *               organisation names per action
     * @throws \InvalidArgumentException when the file is invalid
     */
    public function preview(string $csvContent): array {
        $rows = $this->resolve($this->parse($csvContent));

        $created = [];
        $updated = [];
        foreach ($rows as $row) {
            $existing = $this->repo->find($row['uuid']);
            if ($existing === null) {
                $created[] = $row['name'];
            } elseif ($this->changes($existing, $row) !== []) {
                $updated[] = $row['name'];
            }
        }
        $deactivated = array_map(
            fn(array $org) => (string)$org['org_name'],
            $this->repo->findActiveNotIn(array_column($rows, 'uuid')),
        );

        return ['fetched' => count($rows), 'created' => $created, 'updated' => $updated, 'deactivated' => array_values($deactivated)];
    }

    /**
     * What differs between an existing organisation and its row in the file.
     *
     * @param array<string, mixed> $existing opencase_org row
     * @param OrgRow $row
     * @return list<'structure'|'contact'|'group'|'active'>
     */
    private function changes(array $existing, array $row): array {
        $changes = [];
        if ($existing['org_name'] !== $row['name'] || (string)$existing['org_parent_uuid'] !== $row['parent_uuid']) {
            $changes[] = 'structure';
        }
        if (($existing['email'] ?? null) !== $row['email']
            || ($existing['phone'] ?? null) !== $row['phone']
            || ($existing['location'] ?? null) !== $row['location']) {
            $changes[] = 'contact';
        }
        if (($existing['member_group_id'] ?? null) !== $row['group']) {
            $changes[] = 'group';
        }
        if (!(bool)$existing['active']) {
            $changes[] = 'active';
        }
        return $changes;
    }

    /**
     * @return list<array{line: int, uuid: string, name: string, parent: string, group: ?string, email: ?string, phone: ?string, location: ?string}>
     */
    private function parse(string $csvContent): array {
        $csvContent = preg_replace('/^\xEF\xBB\xBF/', '', $csvContent) ?? $csvContent;
        // Excel's default "CSV" export is Windows-1252, not UTF-8
        if (!mb_check_encoding($csvContent, 'UTF-8')) {
            $csvContent = mb_convert_encoding($csvContent, 'UTF-8', 'Windows-1252');
        }

        $lines = preg_split('/\r\n|\r|\n/', $csvContent) ?: [];
        $header = array_map(fn($h) => mb_strtolower(trim((string)$h)), str_getcsv((string)array_shift($lines), ';', '"', ''));
        if (!in_array('name', $header, true)) {
            throw new \InvalidArgumentException('The file has no "name" column (header row: uuid;name;parent;group;email;phone;location)');
        }

        $rows = [];
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line, ';', '"', '');
            $row = ['line' => $index + 2];
            foreach (self::COLUMNS as $column) {
                $position = array_search($column, $header, true);
                $row[$column] = $position === false ? '' : trim((string)($values[$position] ?? ''));
            }
            if ($row['uuid'] === '' && $row['name'] === '') {
                continue;
            }
            if (mb_strlen($row['group']) > self::MAX_GROUP_ID_LENGTH) {
                throw new \InvalidArgumentException("Line {$row['line']}: group is longer than " . self::MAX_GROUP_ID_LENGTH . ' characters');
            }
            foreach (['group', 'email', 'phone', 'location'] as $column) {
                $row[$column] = $row[$column] === '' ? null : $row[$column];
            }
            $rows[] = $row;
        }

        // Organisations missing from the file are deactivated, so a file
        // without a single usable row would deactivate everything.
        if ($rows === []) {
            throw new \InvalidArgumentException('The file contains no organisations');
        }
        return $rows;
    }

    /**
     * Give every row its uuid and its parent's uuid, and check the structure.
     *
     * @param list<array{line: int, uuid: string, name: string, parent: string, group: ?string, email: ?string, phone: ?string, location: ?string}> $rows
     * @return list<OrgRow>
     */
    private function resolve(array $rows): array {
        $existingByName = [];
        foreach ($this->allOrganisations() as $org) {
            $existingByName[mb_strtolower($org['org_name'])][] = $org['org_uuid'];
        }

        $nameCount = array_count_values(array_map(fn(array $r) => mb_strtolower($r['name']), $rows));
        $uuidsByName = [];
        $seenUuids = [];
        foreach ($rows as $i => $row) {
            if ($row['name'] === '') {
                throw new \InvalidArgumentException("Line {$row['line']}: name is required");
            }
            if (mb_strlen($row['name']) > 255) {
                throw new \InvalidArgumentException("Line {$row['line']}: name is longer than 255 characters");
            }
            $key = mb_strtolower($row['name']);
            if ($row['uuid'] === '' && $nameCount[$key] > 1) {
                throw new \InvalidArgumentException("Line {$row['line']}: \"{$row['name']}\" appears more than once; give these rows a uuid");
            }
            if ($row['uuid'] === '') {
                // Re-importing a file without uuids must update, not duplicate
                $matches = $existingByName[$key] ?? [];
                $rows[$i]['uuid'] = count($matches) === 1 ? $matches[0] : CaseService::generateUuid();
            } elseif (mb_strlen($row['uuid']) > 64) {
                throw new \InvalidArgumentException("Line {$row['line']}: uuid is longer than 64 characters");
            }
            if (isset($seenUuids[$rows[$i]['uuid']])) {
                throw new \InvalidArgumentException("Line {$row['line']}: uuid {$rows[$i]['uuid']} appears more than once");
            }
            $seenUuids[$rows[$i]['uuid']] = true;
            $uuidsByName[$key][] = $rows[$i]['uuid'];
        }

        foreach ($rows as $i => $row) {
            $parent = $row['parent'];
            if ($parent === '') {
                $rows[$i]['parent_uuid'] = '';
            } elseif (isset($seenUuids[$parent])) {
                $rows[$i]['parent_uuid'] = $parent;
            } elseif (count($uuidsByName[mb_strtolower($parent)] ?? []) === 1) {
                $rows[$i]['parent_uuid'] = $uuidsByName[mb_strtolower($parent)][0];
            } elseif (count($uuidsByName[mb_strtolower($parent)] ?? []) > 1) {
                throw new \InvalidArgumentException("Line {$row['line']}: parent \"{$parent}\" is the name of several organisations; use its uuid");
            } else {
                throw new \InvalidArgumentException("Line {$row['line']}: parent \"{$parent}\" is not in the file");
            }
            if ($rows[$i]['parent_uuid'] === $rows[$i]['uuid']) {
                throw new \InvalidArgumentException("Line {$row['line']}: \"{$row['name']}\" is its own parent");
            }
        }

        $this->checkNoCycles($rows);
        return $rows;
    }

    /**
     * @param list<array{line: int, uuid: string, name: string, parent_uuid: string}> $rows
     */
    private function checkNoCycles(array $rows): void {
        $parentOf = array_column($rows, 'parent_uuid', 'uuid');
        $nameOf = array_column($rows, 'name', 'uuid');
        foreach (array_keys($parentOf) as $uuid) {
            $visited = [];
            $current = (string)$uuid;
            while ($current !== '' && isset($parentOf[$current])) {
                if (isset($visited[$current])) {
                    throw new \InvalidArgumentException("The parent relations of \"{$nameOf[$uuid]}\" form a loop");
                }
                $visited[$current] = true;
                $current = $parentOf[$current];
            }
        }
    }

    /**
     * @param list<OrgRow> $rows
     * @return list<OrgRow>
     */
    private function parentsFirst(array $rows): array {
        $byUuid = array_column($rows, null, 'uuid');
        $ordered = [];
        $done = [];
        $visit = function (string $uuid) use (&$visit, &$ordered, &$done, $byUuid): void {
            if (isset($done[$uuid]) || !isset($byUuid[$uuid])) {
                return;
            }
            $done[$uuid] = true;
            $visit($byUuid[$uuid]['parent_uuid']);
            $ordered[] = $byUuid[$uuid];
        };
        foreach (array_keys($byUuid) as $uuid) {
            $visit((string)$uuid);
        }
        return $ordered;
    }

    /**
     * @return list<array{org_uuid: string, org_name: string}>
     */
    private function allOrganisations(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('org_uuid', 'org_name')->from('opencase_org');
        return $qb->executeQuery()->fetchAll();
    }
}
