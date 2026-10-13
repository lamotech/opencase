<?php

declare(strict_types=1);

namespace OCA\OpenCase\BackgroundJob;

use OCA\OpenCase\Db\AccessRequestMapper;
use OCA\OpenCase\Service\AccessDeadlineService;
use OCA\OpenCase\Service\Configuration;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Daily job (≈05:00): sends Nextcloud notifications for access requests approaching
 * or past their effective deadline.
 *
 * yellow: effective deadline within 2 days
 * red:    effective deadline is today or past
 */
class AccessDeadlineNotificationJob extends MorningNotificationJob {

    public function __construct(
        ITimeFactory $time,
        Configuration $configuration,
        IConfig $config,
        LoggerInterface $logger,
        private AccessRequestMapper $requestMapper,
        private AccessDeadlineService $deadlineService,
        private INotificationManager $notificationManager,
        private IUserManager $userManager,
    ) {
        parent::__construct($time, $configuration, $config, $logger);
    }

    protected function lastRunKey(): string {
        return 'access_deadline_notification_last_run';
    }

    protected function sendNotifications(\DateTimeZone $tz): void {
        $requests = $this->requestMapper->findApproachingDeadlines(3);
        foreach ($requests as $req) {
            if (empty($req->getAssignedUser())) {
                continue;
            }

            $effective = $this->deadlineService->effectiveDeadline(
                $req->getExtendedDeadlineAt(),
                $req->getDeadlineAt(),
            );
            $colour = $this->deadlineService->deadlineColour($effective);
            if (!in_array($colour, ['yellow', 'red'], true)) {
                continue;
            }

            $this->notify($req->getAssignedUser(), $req->getId(), $req->getSubject(), $colour, $effective);
        }
    }

    private function notify(string $userId, int $requestId, string $subject, string $colour, \DateTime $deadline): void {
        if ($this->userManager->get($userId) === null) {
            return;
        }

        $notification = $this->notificationManager->createNotification();
        $notification
            ->setApp('opencase')
            ->setUser($userId)
            ->setObject('access_request', (string)$requestId)
            ->setSubject('access_deadline_' . $colour, [
                'request_id' => $requestId,
                'subject'    => $subject,
                'deadline'   => $deadline->format('d-m-Y'),
            ])
            ->setDateTime(new \DateTime());

        try {
            $this->notificationManager->notify($notification);
        } catch (\Throwable) {
            // Notification may already exist
        }
    }
}
