<?php

declare(strict_types=1);

namespace OCA\OpenCase\Dashboard;

use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\HistoryMapper;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IURLGenerator;

class RecentWidget implements IWidget, IIconWidget, IAPIWidgetV2, IButtonWidget {

    public function __construct(
        private IURLGenerator $urlGenerator,
        private HistoryMapper $historyMapper,
        private CaseMapper $caseMapper,
        private DocumentMapper $documentMapper,
    ) {}

    public function getId(): string {
        return 'opencase_recent';
    }

    public function getTitle(): string {
        return 'Seneste';
    }

    public function getOrder(): int {
        return 13;
    }

    public function getIconClass(): string {
        return '';
    }

    public function getIconUrl(): string {
        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->imagePath('opencase', 'app-dark.svg')
        );
    }

    public function getUrl(): ?string {
        return $this->urlGenerator->linkToRouteAbsolute('opencase.page.index');
    }

    public function load(): void {
    }

    public function getWidgetButtons(string $userId): array {
        return [
            new WidgetButton(
                WidgetButton::TYPE_MORE,
                $this->urlGenerator->linkToRouteAbsolute('opencase.page.catch_all', ['path' => 'recent']),
                'Vis alle',
            ),
        ];
    }

    public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
        $history = $this->historyMapper->findByUser($userId);

        $items = [];
        foreach ($history as $entry) {
            if (count($items) >= $limit) {
                break;
            }

            if ($entry->getEntity() === 'case') {
                $case = $this->caseMapper->findById($entry->getEntityKey());
                if ($case === null) {
                    continue;
                }
                $items[] = new WidgetItem(
                    $case->getTitle() ?? '',
                    $case->getCaseNumber() ?? '',
                    $this->urlGenerator->linkToRouteAbsolute('opencase.page.catch_all', ['path' => 'case/' . $case->getId()]),
                    $this->urlGenerator->getAbsoluteURL(
                        $this->urlGenerator->imagePath('core', 'filetypes/folder.svg')
                    ),
                );
            } elseif ($entry->getEntity() === 'document') {
                $doc = $this->documentMapper->findById($entry->getEntityKey());
                if ($doc === null) {
                    continue;
                }
                $items[] = new WidgetItem(
                    $doc->getTitle() ?? '',
                    $doc->getDocumentNumber() ?? '',
                    $this->urlGenerator->linkToRouteAbsolute('opencase.page.catch_all', ['path' => 'document/' . $doc->getId()]),
                    $this->urlGenerator->getAbsoluteURL(
                        $this->urlGenerator->imagePath('core', 'filetypes/x-office-document.svg')
                    ),
                );
            }
        }

        return new WidgetItems(
            $items,
            'Ingen seneste aktiviteter endnu',
        );
    }
}
