<?php

declare(strict_types=1);

namespace OCA\OpenCase\BackgroundJob;

use OCA\OpenCase\Db\DocWorkflowStepMapper;
use OCA\OpenCase\Service\Configuration;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Daily job (≈05:00): notifies the user whose turn it is in an active document
 * workflow when their step is approaching or past its deadline.
 *
 * yellow: step deadline today or within 2 days
 * red:    step deadline has passed
 */
class WorkflowDeadlineNotificationJob extends MorningNotificationJob {

    public function __construct(
        ITimeFactory $time,
        Configuration $configuration,
        IConfig $config,
        LoggerInterface $logger,
        private DocWorkflowStepMapper $stepMapper,
        private INotificationManager $notificationManager,
        private IUserManager $userManager,
    ) {
        parent::__construct($time, $configuration, $config, $logger);
    }

    protected function lastRunKey(): string {
        return 'workflow_deadline_notification_last_run';
    }

    protected function sendNotifications(\DateTimeZone $tz): void {
        // DB datetimes are UTC; bind the local-midnight cutoff in UTC.
        $cutoff = $this->time->getDateTime('today', $tz)->modify('+3 days')->setTimezone(new \DateTimeZone('UTC'));

        foreach ($this->stepMapper->findPendingDueBefore($cutoff) as $row) {
            // Only the current step of the workflow is the user's turn to act.
            $currentStep = $this->stepMapper->findCurrentPendingStep((int)$row['workflow_id']);
            if ($currentStep === null || $currentStep->getId() !== (int)$row['step_id']) {
                continue;
            }

            $userId = $currentStep->getUserId();
            if ($this->userManager->get($userId) === null) {
                continue;
            }

            $deadline = $currentStep->getDeadline();
            $colour = $this->deadlineColour($deadline, $tz);
            if (!in_array($colour, ['yellow', 'red'], true)) {
                continue;
            }

            $notification = $this->notificationManager->createNotification();
            $notification
                ->setApp('opencase')
                ->setUser($userId)
                ->setObject('workflow', (string)$row['workflow_id'])
                ->setSubject('workflow_deadline_' . $colour, [
                    'document_id'    => (int)$row['document_id'],
                    'document_title' => $row['document_title'],
                    'workflow_type'  => $row['workflow_type'],
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
