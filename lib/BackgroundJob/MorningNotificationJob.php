<?php

declare(strict_types=1);

namespace OCA\OpenCase\BackgroundJob;

use OCA\OpenCase\Service\Configuration;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Base class for the daily deadline notification jobs.
 *
 * The job is checked every 15 minutes, but only sends notifications once per
 * day, at the first check after TARGET_HOUR local time — so users have the
 * notifications (and the email digest) when they start their working day.
 *
 * Local time is the instance's 'default_timezone' system setting, falling back
 * to Europe/Copenhagen (PHP itself always runs in UTC inside Nextcloud).
 */
abstract class MorningNotificationJob extends TimedJob {
    private const TARGET_HOUR = 5; // 05:00 local time

    public function __construct(
        ITimeFactory $time,
        protected Configuration $configuration,
        protected IConfig $config,
        protected LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(15 * 60);
    }

    /** opencase_config key holding the date (Y-m-d) of the last run. */
    abstract protected function lastRunKey(): string;

    /** Send today's notifications. */
    abstract protected function sendNotifications(\DateTimeZone $tz): void;

    protected function run($argument): void {
        $tz = $this->localTimeZone();
        $now = $this->time->getDateTime('now', $tz);
        $today = $now->format('Y-m-d');

        if ((int)$now->format('G') < self::TARGET_HOUR) {
            return;
        }
        if ($this->configuration->getConfigValue($this->lastRunKey(), '') === $today) {
            return;
        }

        // Mark as done before sending, so a failure halfway never causes the
        // users who already got today's notifications to get them twice.
        $this->configuration->setConfigValue($this->lastRunKey(), $today);

        try {
            $this->sendNotifications($tz);
        } catch (\Throwable $e) {
            $this->logger->error('[' . static::class . '] ' . $e->getMessage(), [
                'app' => 'opencase',
                'exception' => $e,
            ]);
        }
    }

    /**
     * Traffic-light colour for a deadline, by calendar date in local time:
     * red = overdue, yellow = due today or within 2 days, green = later.
     */
    protected function deadlineColour(\DateTimeInterface $deadline, \DateTimeZone $tz): string {
        $today = $this->time->getDateTime('today', $tz);
        $due = (new \DateTime('@' . $deadline->getTimestamp()))->setTimezone($tz)->setTime(0, 0);
        $days = (int)$today->diff($due)->format('%r%a');

        if ($days < 0) {
            return 'red';
        }
        if ($days <= 2) {
            return 'yellow';
        }
        return 'green';
    }

    private function localTimeZone(): \DateTimeZone {
        $name = $this->config->getSystemValueString('default_timezone', 'Europe/Copenhagen');
        try {
            return new \DateTimeZone($name);
        } catch (\Exception) {
            return new \DateTimeZone('Europe/Copenhagen');
        }
    }
}
