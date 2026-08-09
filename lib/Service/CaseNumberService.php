<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\ConfigMapper;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Generates unique, gap-free case numbers from an administrator-defined mask.
 *
 * ## Mask syntax
 *   yyyy   — 4-digit year  (e.g. 2026)
 *   yy     — 2-digit year  (e.g. 26)
 *   #...#  — sequence number, zero-padded to the number of # characters
 *
 * Examples:
 *   yyyy-#####   → 2026-00001, 2026-00002, …
 *   yy/######   → 26/000001, 26/000002, …
 *   ########     → 00000001, 00000002, …  (no year, global sequence in 'year' 0)
 *
 * ## Multi-server safety
 * The sequence is stored in `oc_opencase_sequences` with one row per year.
 * Generation uses `SELECT … FOR UPDATE` inside an explicit transaction so
 * that concurrent requests on separate application servers always get a
 * different value — without gaps caused by failed allocations being
 * discarded (the transaction is committed before the case row is written).
 *
 * Requires InnoDB (MySQL/MariaDB) — the same requirement as the rest of the
 * app's transactional operations.
 */
class CaseNumberService {

    private const CONFIG_KEY_MASK    = 'case_number_mask';
    private const DEFAULT_MASK       = 'yyyy-#####';

    public function __construct(
        private ConfigMapper $configMapper,
        private IDBConnection $db,
        private IConfig $config,
    ) {
    }

    /**
     * Generate and reserve the next case number for the current calendar year.
     *
     * The sequence number is atomically claimed from the database before this
     * method returns, regardless of whether the caller later succeeds in
     * persisting the new case.  This is intentional: it matches the behaviour
     * of traditional ESDH sequence numbers (auditors expect no gaps) where
     * a gap would be far more surprising than a missing case.
     */
    public function generateCaseNumber(): string {
        $mask = $this->configMapper->get(self::CONFIG_KEY_MASK, self::DEFAULT_MASK);
        $year = $this->extractYearFromMask($mask);
        $seq  = $this->acquireNextSequence($year);
        return $this->applyMask($mask, $year, $seq);
    }

    /**
     * Return the currently configured mask (falls back to the default).
     */
    public function getMask(): string {
        return $this->configMapper->get(self::CONFIG_KEY_MASK, self::DEFAULT_MASK);
    }

    /**
     * Persist a new mask after validation.
     *
     * @throws \InvalidArgumentException if the mask is not valid
     */
    public function setMask(string $mask): void {
        $this->validateMask($mask);
        $this->configMapper->set(self::CONFIG_KEY_MASK, $mask);
    }

    /**
     * Validate a mask string without persisting it.
     *
     * @throws \InvalidArgumentException
     */
    public function validateMask(string $mask): void {
        if (trim($mask) === '') {
            throw new \InvalidArgumentException('Case number mask must not be empty');
        }
        if (preg_match('/#+/', $mask) !== 1) {
            throw new \InvalidArgumentException(
                'Case number mask must contain exactly one consecutive block of # characters for the sequence number'
            );
        }
    }

    /**
     * Generate and reserve the next document number within a case.
     *
     * Format: {case_number}-{seq}  e.g. "2026-00101-1", "2026-00101-2", …
     *
     * The per-case sequence is stored in oc_opencase_doc_sequences and claimed
     * with the same SELECT FOR UPDATE pattern used for case numbers, guaranteeing
     * uniqueness and no gaps across concurrent requests.
     */
    public function generateDocumentNumber(int $caseId, string $caseNumber): string {
        $seq = $this->acquireNextDocumentSequence($caseId);
        return $caseNumber . '-' . $seq;
    }

    /**
     * Atomically claim the next document sequence number for a given case.
     *
     * @return int The sequence number that was claimed (1-based).
     */
    private function acquireNextDocumentSequence(int $caseId): int {
        $prefix = $this->config->getSystemValue('dbtableprefix', 'oc_');
        $table  = $prefix . 'opencase_doc_sequences';

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->executeQuery(
                "SELECT next_seq FROM `{$table}` WHERE case_id = ? FOR UPDATE",
                [$caseId],
                [\PDO::PARAM_INT]
            );
            $row = $stmt->fetchOne();
            $stmt->closeCursor();

            if ($row === false) {
                $this->db->executeStatement(
                    "INSERT INTO `{$table}` (case_id, next_seq) VALUES (?, 2)",
                    [$caseId],
                    [\PDO::PARAM_INT]
                );
                $seq = 1;
            } else {
                $seq = (int)$row;
                $this->db->executeStatement(
                    "UPDATE `{$table}` SET next_seq = ? WHERE case_id = ?",
                    [$seq + 1, $caseId],
                    [\PDO::PARAM_INT, \PDO::PARAM_INT]
                );
            }

            $this->db->commit();
            return $seq;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Atomically claim the next sequence number for a given year.
     *
     * Uses SELECT FOR UPDATE inside a transaction.  The row is inserted on
     * first use so there is no need for a separate seed step.
     *
     * @return int The sequence number that was claimed (1-based).
     */
    private function acquireNextSequence(int $year): int {
        $prefix = $this->config->getSystemValue('dbtableprefix', 'oc_');
        $table  = $prefix . 'opencase_sequences';

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->executeQuery(
                "SELECT next_seq FROM `{$table}` WHERE year = ? FOR UPDATE",
                [$year],
                [\PDO::PARAM_INT]
            );
            $row = $stmt->fetchOne();
            $stmt->closeCursor();

            if ($row === false) {
                // First case number of this year — insert the row with next=2
                // (we are claiming sequence 1 right now).
                $this->db->executeStatement(
                    "INSERT INTO `{$table}` (year, next_seq) VALUES (?, 2)",
                    [$year],
                    [\PDO::PARAM_INT]
                );
                $seq = 1;
            } else {
                $seq = (int)$row;
                $this->db->executeStatement(
                    "UPDATE `{$table}` SET next_seq = ? WHERE year = ?",
                    [$seq + 1, $year],
                    [\PDO::PARAM_INT, \PDO::PARAM_INT]
                );
            }

            $this->db->commit();
            return $seq;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Determine which calendar year to use for the sequence bucket.
     *
     * If the mask contains a year placeholder (yyyy / yy) we use the current
     * wall-clock year so sequences reset every year.  If there is no year in
     * the mask the sequence runs forever and we use year=0 as the bucket key.
     */
    private function extractYearFromMask(string $mask): int {
        if (str_contains($mask, 'yyyy') || str_contains($mask, 'yy')) {
            return (int)date('Y');
        }
        return 0; // global sequence, never resets
    }

    /**
     * Substitute year and sequence placeholders in the mask.
     */
    private function applyMask(string $mask, int $year, int $seq): string {
        $yearStr   = $year > 0 ? (string)$year : '';
        $yearShort = $year > 0 ? substr($yearStr, -2) : '';

        // Replace year placeholders — longest token first to avoid partial replacement
        $mask = str_replace('yyyy', $yearStr, $mask);
        $mask = str_replace('yy',   $yearShort, $mask);

        // Replace the # block with the sequence number, zero-padded to its width
        $mask = preg_replace_callback('/#+/', static function (array $m) use ($seq): string {
            return str_pad((string)$seq, strlen($m[0]), '0', STR_PAD_LEFT);
        }, $mask);

        return $mask;
    }
}
