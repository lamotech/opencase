<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Generic CRUD for the multilingual (id, language) code-list lookup tables
 * managed from the Configuration > Code lists UI.
 *
 * Each table has a composite primary key (id, language) plus `name` and
 * `expired` columns. Other columns specific to individual tables
 * (is_closed, is_final, description, …) are left untouched — this service
 * only manages the Danish/English display names and the expired flag.
 */
class CodeListService {

    private const TABLES = [
        'casestatus'        => 'opencase_casestatus',
        'contactroles'      => 'opencase_contactroles',
        'documentcategory'  => 'opencase_documentcategory',
        'documentstatus'    => 'opencase_documentstatus',
        'insightlevel'      => 'opencase_insightlevel',
        'participantroles'  => 'opencase_participantroles',
        'casetype'          => 'opencase_casetype',
    ];

    /** Lists whose table also carries a (non-per-language) `primary_participant` column. */
    private const PRIMARY_PARTICIPANT_LISTS = ['casetype'];

    /** Allowed values for the `primary_participant` column. */
    public const PRIMARY_PARTICIPANT_VALUES = ['None', 'Citizen', 'Company', 'Employee', 'Estate'];

    public function __construct(
        private IDBConnection $db,
    ) {}

    public function isValidList(string $list): bool {
        return isset(self::TABLES[$list]);
    }

    public function hasPrimaryParticipant(string $list): bool {
        return in_array($list, self::PRIMARY_PARTICIPANT_LISTS, true);
    }

    /**
     * Return all entries for a code list, merging the 'da' and 'en' rows
     * for each id into a single record.
     *
     * @return list<array{id: int, da: string, en: string, expired: bool, updated_at: ?string, updated_by: ?string}>
     */
    public function getList(string $list): array {
        $table = $this->tableName($list);
        $hasPP = $this->hasPrimaryParticipant($list);

        $columns = ['id', 'language', 'name', 'expired', 'updated_at', 'updated_by'];
        if ($hasPP) {
            $columns[] = 'primary_participant';
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select(...$columns)
            ->from($table)
            ->orderBy('id', 'ASC');

        $result = $qb->executeQuery();
        $byId = [];
        while ($row = $result->fetch()) {
            $id = (int)$row['id'];
            if (!isset($byId[$id])) {
                $byId[$id] = ['id' => $id, 'da' => '', 'en' => '', 'expired' => false, 'updated_at' => null, 'updated_by' => null];
                if ($hasPP) {
                    $byId[$id]['primary_participant'] = 'None';
                }
            }
            $byId[$id][$row['language']] = (string)$row['name'];
            $byId[$id]['expired'] = $byId[$id]['expired'] || (bool)$row['expired'];
            if ($hasPP) {
                $byId[$id]['primary_participant'] = (string)$row['primary_participant'];
            }
            if ($row['updated_at'] !== null) {
                $byId[$id]['updated_at'] = $row['updated_at'];
            }
            if ($row['updated_by'] !== null) {
                $byId[$id]['updated_by'] = $row['updated_by'];
            }
        }
        $result->closeCursor();

        return array_values($byId);
    }

    /**
     * Create a new code-list entry. If only one of $da / $en is given, the
     * value is copied to the other language.
     */
    public function create(string $list, string $da, string $en, bool $expired, string $userId, ?string $primaryParticipant = null): array {
        [$da, $en] = $this->fillMissing($da, $en);

        $table = $this->tableName($list);
        $hasPP = $this->hasPrimaryParticipant($list);
        $id = $this->nextId($table);
        $now = new \DateTime();

        if ($hasPP) {
            $primaryParticipant = $this->normalizePrimaryParticipant($primaryParticipant);
        }

        foreach (['da' => $da, 'en' => $en] as $language => $name) {
            $qb = $this->db->getQueryBuilder();
            $values = [
                'id'         => $qb->createNamedParameter($id, \PDO::PARAM_INT),
                'language'   => $qb->createNamedParameter($language),
                'name'       => $qb->createNamedParameter($name),
                'expired'    => $qb->createNamedParameter($expired, IQueryBuilder::PARAM_BOOL),
                'updated_at' => $qb->createNamedParameter($now, 'datetime'),
                'updated_by' => $qb->createNamedParameter($userId),
            ];
            if ($hasPP) {
                $values['primary_participant'] = $qb->createNamedParameter($primaryParticipant);
            }
            $qb->insert($table)->values($values)->executeStatement();
        }

        $entry = ['id' => $id, 'da' => $da, 'en' => $en, 'expired' => $expired, 'updated_at' => $now->format('c'), 'updated_by' => $userId];
        if ($hasPP) {
            $entry['primary_participant'] = $primaryParticipant;
        }
        return $entry;
    }

    /**
     * Update an existing code-list entry. If only one of $da / $en is given,
     * the value is copied to the other language.
     */
    public function update(string $list, int $id, string $da, string $en, bool $expired, string $userId, ?string $primaryParticipant = null): array {
        [$da, $en] = $this->fillMissing($da, $en);

        $table = $this->tableName($list);
        $hasPP = $this->hasPrimaryParticipant($list);
        $now = new \DateTime();

        if ($hasPP) {
            $primaryParticipant = $this->normalizePrimaryParticipant($primaryParticipant);
        }

        foreach (['da' => $da, 'en' => $en] as $language => $name) {
            $qb = $this->db->getQueryBuilder();
            $qb->update($table)
                ->set('name', $qb->createNamedParameter($name))
                ->set('expired', $qb->createNamedParameter($expired, IQueryBuilder::PARAM_BOOL))
                ->set('updated_at', $qb->createNamedParameter($now, 'datetime'))
                ->set('updated_by', $qb->createNamedParameter($userId));
            if ($hasPP) {
                $qb->set('primary_participant', $qb->createNamedParameter($primaryParticipant));
            }
            $updated = $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)))
                ->andWhere($qb->expr()->eq('language', $qb->createNamedParameter($language)))
                ->executeStatement();

            if ($updated === 0) {
                $qb2 = $this->db->getQueryBuilder();
                $values = [
                    'id'         => $qb2->createNamedParameter($id, \PDO::PARAM_INT),
                    'language'   => $qb2->createNamedParameter($language),
                    'name'       => $qb2->createNamedParameter($name),
                    'expired'    => $qb2->createNamedParameter($expired, IQueryBuilder::PARAM_BOOL),
                    'updated_at' => $qb2->createNamedParameter($now, 'datetime'),
                    'updated_by' => $qb2->createNamedParameter($userId),
                ];
                if ($hasPP) {
                    $values['primary_participant'] = $qb2->createNamedParameter($primaryParticipant);
                }
                $qb2->insert($table)->values($values)->executeStatement();
            }
        }

        $entry = ['id' => $id, 'da' => $da, 'en' => $en, 'expired' => $expired, 'updated_at' => $now->format('c'), 'updated_by' => $userId];
        if ($hasPP) {
            $entry['primary_participant'] = $primaryParticipant;
        }
        return $entry;
    }

    /** Falls back to 'None' for missing/unrecognised values. */
    private function normalizePrimaryParticipant(?string $value): string {
        return in_array($value, self::PRIMARY_PARTICIPANT_VALUES, true) ? $value : 'None';
    }

    private function fillMissing(string $da, string $en): array {
        $da = trim($da);
        $en = trim($en);
        if ($da === '' && $en !== '') {
            $da = $en;
        } elseif ($en === '' && $da !== '') {
            $en = $da;
        }
        return [$da, $en];
    }

    private function nextId(string $table): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->max('id', 'max_id'))->from($table);
        $result = $qb->executeQuery();
        $maxId = (int)($result->fetchOne() ?: 0);
        $result->closeCursor();
        return $maxId + 1;
    }

    private function tableName(string $list): string {
        if (!$this->isValidList($list)) {
            throw new DoesNotExistException("Unknown code list: $list");
        }
        return self::TABLES[$list];
    }
}
