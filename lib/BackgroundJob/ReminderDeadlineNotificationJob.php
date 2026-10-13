<?php

declare(strict_types=1);

namespace OCA\OpenCase\BackgroundJob;

use OCA\OpenCase\Db\ReminderMapper;
use OCA\OpenCase\Service\Configuration;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Daily job (≈05:00): notifies the responsible user of active reminders that
 * are approaching or past their deadline.
 *
 * yellow: deadline today or within 2 days
 * red:    deadline has passed
 */
class ReminderDeadlineNotificationJob extends MorningNotificationJob {

    public function __construct(
        ITimeFactory $time,
        Configuration $configuration,
        IConfig $config,
        LoggerInterface $logger,
        private ReminderMapper $reminderMapper,
        private INotificationManager $notificationManager,
        private IUserManager $userManager,
    ) {
        parent::__construct($time, $configuration, $config, $logger);
    }

    protected function lastRunKey(): string {
        return 'reminder_deadline_notification_last_run';
    }

    protected function sendNotifications(\DateTimeZone $tz): void {
        // DB datetimes are UTC; bind the local-midnight cutoff in UTC.
        $cutoff = $this->time->getDateTime('today', $tz)->modify('+3 days')->setTimezone(new \DateTimeZone('UTC'));

        foreach ($this->reminderMapper->findActiveDueBefore($cutoff) as $reminder) {
            $userId = $reminder->getResponsibleUserId();
            if ($userId === null || $userId === '' || $this->userManager->get($userId) === null) {
                continue;
            }

            $deadline = $reminder->getDeadline();
            $colour = $this->deadlineColour($deadline, $tz);
            if (!in_array($colour, ['yellow', 'red'], true)) {
                continue;
            }

            $notification = $this->notificationManager->createNotification();
            $notification
                ->setApp('opencase')
                ->setUser($userId)
                ->setObject('reminder', (string)$reminder->getId())
                ->setSubject('reminder_deadline_' . $colour, [
                    'reminder_id'    => $reminder->getId(),
                    'reminder_title' => $reminder->getTitle(),
                    'entity'         => $reminder->getEntity(),
                    'entity_key'     => $reminder->getEntityKey(),
                    'deadline'       => (clone $deadline)->setTimezone($tz)->format('d-m-Y'),
                ])
                ->setDateTime(new \DateTime());

            try {
                $this->notificationManager->notify($notification);
            } catch (\Throwable) {
                // best-effort
            }
        }
    }
}
