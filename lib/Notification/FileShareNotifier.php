<?php

declare(strict_types=1);

namespace OCA\OpenCase\Notification;

use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory as IL10NFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

/**
 * Formats OpenCase file-share notifications for display in the NC notification
 * bell and email digests.
 *
 * Handled subjects:
 *   'file_shared'     — a file was shared with the recipient
 *       Parameters: ['filename' => string, 'sharer' => string (uid)]
 *   'document_shared' — a whole document was shared with the recipient
 *       Parameters: ['document_title' => string, 'document_id' => int|string, 'sharer' => string (uid)]
 */
class FileShareNotifier implements INotifier {

    public function __construct(
        private IURLGenerator $urlGenerator,
        private IUserManager $userManager,
        private IL10NFactory $l10nFactory,
    ) {
    }

    public function getID(): string {
        return 'opencase';
    }

    public function getName(): string {
        return 'OpenCase';
    }

    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== 'opencase') {
            throw new \InvalidArgumentException('Notification does not belong to OpenCase');
        }

        $l = $this->l10nFactory->get('opencase', $languageCode);

        if ($notification->getSubject() === 'shipment_complete') {
            $params  = $notification->getSubjectParameters();
            $title   = $params['document_title'] ?? '';
            $status  = $params['status'] ?? '';
            $failed  = (int)($params['failures'] ?? 0);

            if ($failed === 0) {
                $notification->setParsedSubject($l->t('Digital post afsendt'));
                $notification->setParsedMessage($l->t('Forsendelse til "%1$s" er fuldført', [$title]));
            } else {
                $notification->setParsedSubject($l->t('Digital post – afsendelse fejlede'));
                $notification->setParsedMessage($l->t('Forsendelse til "%1$s" mislykkedes (%2$s fejl)', [$title, (string)$failed]));
            }

            $link = $this->urlGenerator->linkToRoute('opencase.page.index');
            $notification->setLink($link);

            return $notification;
        }

        if ($notification->getSubject() === 'file_shared') {
            $params     = $notification->getSubjectParameters();
            $filename   = $params['filename'] ?? '';
            $sharerUid  = $params['sharer'] ?? '';
            $sharer     = $this->userManager->get($sharerUid);
            $sharerName = $sharer?->getDisplayName() ?? $sharerUid;

            $notification->setParsedSubject(
                $l->t('"%1$s" har delt en fil med dig', [$sharerName])
            );
            $notification->setParsedMessage(
                $l->t('Filen "%1$s" er delt med dig', [$filename])
            );

            $notification->setLink($this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->linkToRoute('opencase.page.index') . 'shared'
            ));

            return $notification;
        }

        if ($notification->getSubject() === 'document_shared') {
            $params     = $notification->getSubjectParameters();
            $docTitle   = $params['document_title'] ?? '';
            $docId      = $params['document_id'] ?? '';
            $sharerUid  = $params['sharer'] ?? '';
            $sharer     = $this->userManager->get($sharerUid);
            $sharerName = $sharer?->getDisplayName() ?? $sharerUid;

            $notification->setParsedSubject(
                $l->t('"%1$s" har delt et dokument med dig', [$sharerName])
            );
            $notification->setParsedMessage(
                $l->t('Dokumentet "%1$s" er delt med dig', [$docTitle])
            );

            $notification->setLink($this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->linkToRoute(
                    'opencase.page.catch_all',
                    ['path' => 'document/' . $docId]
                )
            ));

            return $notification;
        }

        if ($notification->getSubject() === 'workflow_action_required') {
            $params   = $notification->getSubjectParameters();
            $docTitle = $params['document_title'] ?? '';
            $docId    = $params['document_id'] ?? '';
            $wfType   = $params['workflow_type'] ?? '';

            $typeLabel = $wfType === 'review'
                ? $l->t('gennemse')
                : $l->t('godkende/afvise');

            $notification->setParsedSubject($l->t('Handling påkrævet'));
            $notification->setParsedMessage(
                $l->t('Du skal %1$s dokumentet "%2$s"', [$typeLabel, $docTitle])
            );
            $notification->setLink($this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->linkToRoute(
                    'opencase.page.catch_all',
                    ['path' => 'document/' . $docId]
                )
            ) . '#tab-workflow');
            return $notification;
        }

        if ($notification->getSubject() === 'workflow_completed') {
            $params   = $notification->getSubjectParameters();
            $docTitle = $params['document_title'] ?? '';
            $docId    = $params['document_id'] ?? '';

            $notification->setParsedSubject($l->t('Workflow fuldført'));
            $notification->setParsedMessage(
                $l->t('Alle trin er gennemført for dokumentet "%1$s"', [$docTitle])
            );
            $notification->setLink($this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->linkToRoute(
                    'opencase.page.catch_all',
                    ['path' => 'document/' . $docId]
                )
            ) . '#tab-workflow');
            return $notification;
        }

        if ($notification->getSubject() === 'workflow_rejected') {
            $params   = $notification->getSubjectParameters();
            $docTitle = $params['document_title'] ?? '';
            $docId    = $params['document_id'] ?? '';

            $notification->setParsedSubject($l->t('Workflow afvist'));
            $notification->setParsedMessage(
                $l->t('Dokumentet "%1$s" er afvist', [$docTitle])
            );
            $notification->setLink($this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->linkToRoute(
                    'opencase.page.catch_all',
                    ['path' => 'document/' . $docId]
                )
            ) . '#tab-workflow');
            return $notification;
        }

        if ($notification->getSubject() === 'reminder_assigned') {
            $params      = $notification->getSubjectParameters();
            $title       = $params['reminder_title'] ?? '';
            $entity      = $params['entity'] ?? 'case';
            $entityKey   = $params['entity_key'] ?? '';
            $assignerUid = $params['assigner'] ?? '';
            $deadline    = $params['deadline'] ?? null;
            $assigner    = $this->userManager->get($assignerUid);
            $assignerName = $assigner?->getDisplayName() ?? $assignerUid;

            $notification->setParsedSubject($l->t('Ny påmindelse tildelt'));

            $msg = $l->t('"%1$s" har tildelt dig påmindelsen "%2$s"', [$assignerName, $title]);
            if ($deadline !== null && $deadline !== '') {
                $dt = new \DateTime($deadline);
                $msg .= ' (' . $l->t('deadline') . ': ' . $dt->format('d.m.Y') . ')';
            }
            $notification->setParsedMessage($msg);

            $routeParams = $entity === 'case'
                ? ['path' => 'case/' . $entityKey]
                : ['path' => 'document/' . $entityKey];

            $notification->setLink($this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->linkToRoute('opencase.page.catch_all', $routeParams)
            ) . '#tab-info');

            return $notification;
        }

        if ($notification->getSubject() === 'cpr_event_received') {
            $params      = $notification->getSubjectParameters();
            $description = $params['description'] ?? '';
            $caseCount   = (int)($params['case_count'] ?? 0);
            $documentCount = (int)($params['document_count'] ?? 0);

            $notification->setParsedSubject(
                $l->t('CPR-hændelse modtaget: %1$s', [$description])
            );

            $parts = [];
            if ($caseCount > 0) {
                $parts[] = $l->n('%n sag', '%n sager', $caseCount);
            }
            if ($documentCount > 0) {
                $parts[] = $l->n('%n dokument', '%n dokumenter', $documentCount);
            }
            $notification->setParsedMessage(
                $l->t('Opdateret på %1$s', [implode(' ' . $l->t('og') . ' ', $parts)])
            );

            $notification->setLink($this->urlGenerator->linkToRoute('opencase.page.index'));

            return $notification;
        }

        throw new \OCP\Notification\UnknownNotificationException('Unknown OpenCase notification subject: ' . $notification->getSubject());
    }
}
