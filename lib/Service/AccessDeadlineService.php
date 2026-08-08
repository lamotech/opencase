<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

/**
 * Computes legal deadlines for access requests, counting only working days
 * and skipping Danish public holidays.
 *
 * public_access / party_access → 7 working days (Offentlighedsloven §36)
 * gdpr_access                  → 30 calendar days (GDPR art. 12)
 */
class AccessDeadlineService {

    /** @var string[] YYYY-MM-DD format */
    private array $holidayCache = [];
    private int $holidayCacheYear = 0;

    public function computeDeadline(string $type, \DateTime $receivedAt): \DateTime {
        if ($type === 'gdpr_access') {
            $deadline = clone $receivedAt;
            $deadline->modify('+30 days');
            return $deadline;
        }

        return $this->addWorkingDays(clone $receivedAt, 7);
    }

    public function addWorkingDays(\DateTime $from, int $days): \DateTime {
        $date = clone $from;
        $added = 0;
        while ($added < $days) {
            $date->modify('+1 day');
            if ($this->isWorkingDay($date)) {
                $added++;
            }
        }
        return $date;
    }

    public function isWorkingDay(\DateTime $date): bool {
        $dow = (int)$date->format('N'); // 1=Mon … 7=Sun
        if ($dow >= 6) {
            return false;
        }
        return !$this->isDanishHoliday($date);
    }

    /**
     * Return traffic-light colour based on working days remaining.
     * green ≥ 3 | yellow 1–2 | red 0 or overdue
     */
    public function deadlineColour(\DateTime $effectiveDeadline): string {
        $now  = new \DateTime();
        $diff = (int)$now->diff($effectiveDeadline)->days;
        if ($effectiveDeadline < $now) {
            return 'red';
        }
        if ($diff <= 2) {
            return 'yellow';
        }
        return 'green';
    }

    public function effectiveDeadline(?\DateTime $extended, \DateTime $deadline): \DateTime {
        return $extended ?? $deadline;
    }

    // ---------------------------------------------------------------
    // Danish public holiday calculation
    // ---------------------------------------------------------------

    private function isDanishHoliday(\DateTime $date): bool {
        $year = (int)$date->format('Y');
        if ($this->holidayCacheYear !== $year) {
            $this->holidayCache     = $this->buildHolidayList($year);
            $this->holidayCacheYear = $year;
        }
        return in_array($date->format('Y-m-d'), $this->holidayCache, true);
    }

    /** @return string[] */
    private function buildHolidayList(int $year): array {
        $easter = $this->easterDate($year);

        $days = [
            "{$year}-01-01", // Nytårsdag
            $this->addDays($easter, -3),  // Skærtorsdag
            $this->addDays($easter, -2),  // Langfredag
            $this->addDays($easter, 1),   // 2. Påskedag
            $this->addDays($easter, 39),  // Kristi Himmelfartsdag
            $this->addDays($easter, 50),  // 2. Pinsedag
            "{$year}-06-05", // Grundlovsdag
            "{$year}-12-24", // Juleaftensdag
            "{$year}-12-25", // 1. Juledag
            "{$year}-12-26", // 2. Juledag
        ];

        return $days;
    }

    private function easterDate(int $year): \DateTime {
        // Anonymous Gregorian algorithm
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day   = (($h + $l - 7 * $m + 114) % 31) + 1;
        return new \DateTime(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    private function addDays(\DateTime $date, int $days): string {
        $d = clone $date;
        $d->modify("{$days} days");
        return $d->format('Y-m-d');
    }
}
