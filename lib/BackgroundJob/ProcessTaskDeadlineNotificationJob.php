<?php

declare(strict_types=1);

namespace OCA\OpenCase\BackgroundJob;

use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\ProcessTaskMapper;
use OCA\OpenCase\Service\Configuration;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Daily job (≈05:00): notifies about open process tasks whose deadline is
 * approaching or past — the assignee, or for a task nobody has taken yet,
 * the case's responsible caseworker.
 *
 * yellow: deadline today or within 2 days
 * red:    deadline has passed
 */
class ProcessTaskDeadlineNotificationJob extends MorningNotificationJob {

    public function __construct(
        ITimeFactory $time,
        Configuration $configuration,
        IConfig $config,
        LoggerInterface $logger,
        private ProcessTaskMapper $taskMapper,
        private CaseMapper $caseMapper,
        private INotificationManager $notificationManager,
        private IUserManager $userManager,
    ) {
        parent::__construct($time, $configuration, $config, $logger);
    }

    protected function lastRunKey(): string {
        return 'process_task_deadline_notification_last_run';
    }

    protected function sendNotifications(\DateTimeZone $tz): void {
        if ($this->configuration->getConfigValue('feature_process-engine', '0') !== '1') {
            return;
        }
        // DB datetimes are UTC; bind the local-midnight cutoff in UTC.
        $cutoff = $this->time->getDateTime('today', $tz)->modify('+3 days')->setTimezone(new \DateTimeZone('UTC'));

        foreach ($this->taskMapper->findOpenDueBefore($cutoff) as $task) {
            $deadline = $task->getDueDate();
            $colour = $this->deadlineColour($deadline, $tz);
            if (!in_array($colour, ['yellow', 'red'], true)) {
                continue;
            }

            $case = $this->caseMapper->findById($task->getCaseId());
            $userId = $task->getAssignee() ?? $case?->getResponsibleUserId();
            if ($userId === null || $userId === '' || $this->userManager->get($userId) === null) {
                continue;
            }

            $notification = $this->notificationManager->createNotification();
            $notification
                ->setApp('opencase')
                ->setUser($userId)
                ->setObject('process_task', (string)$task->getId())
                ->setSubject('process_task_deadline_' . $colour, [
                    'task_name'   => (string)$task->getName(),
                    'case_id'     => $task->getCaseId(),
                    'case_number' => $case?->getCaseNumber() ?? '',
                    'case_title'  => $case?->getTitle() ?? '',
                    'unassigned'  => $task->getAssignee() === null,
                    'deadline'    => (clone $deadline)->setTimezone($tz)->format('d-m-Y'),
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
