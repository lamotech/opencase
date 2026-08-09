<?php

declare(strict_types=1);

namespace OCA\OpenCase\BackgroundJob;

use OCA\OpenCase\Db\AccessRequestMapper;
use OCA\OpenCase\Service\AccessDeadlineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Daily job: sends Nextcloud notifications for access requests approaching
 * or past their effective deadline.
 *
 * yellow: effective deadline within 2 days
 * red:    effective deadline is today or past
 */
class AccessDeadlineNotificationJob extends TimedJob {

    public function __construct(
        ITimeFactory $time,
        private AccessRequestMapper $requestMapper,
        private AccessDeadlineService $deadlineService,
        private INotificationManager $notificationManager,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(24 * 3600); // once per day
    }

    protected function run($argument): void {
        try {
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

                $this->notify($req->getAssignedUser(), $req->getId(), $colour, $effective);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[AccessDeadlineNotificationJob] ' . $e->getMessage());
        }
    }

    private function notify(string $userId, int $requestId, string $colour, \DateTime $deadline): void {
        if ($this->userManager->get($userId) === null) {
            return;
        }

        $notification = $this->notificationManager->createNotification();
        $notification
            ->setApp('opencase')
            ->setUser($userId)
            ->setObject('access_request', (string)$requestId)
            ->setSubject('access_deadline_' . $colour, [
                'deadline' => $deadline->format('d-m-Y'),
            ])
            ->setDateTime(new \DateTime());

        try {
            $this->notificationManager->notify($notification);
        } catch (\Throwable) {
            // Notification may already exist
        }
    }
}
