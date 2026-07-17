<?php

declare(strict_types=1);

namespace OCA\OpenCase\Dashboard;

use OCA\OpenCase\Db\CaseMapper;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IURLGenerator;

class MyCasesWidget implements IWidget, IIconWidget, IAPIWidgetV2, IButtonWidget {

    public function __construct(
        private IURLGenerator $urlGenerator,
        private CaseMapper $caseMapper,
    ) {}

    public function getId(): string {
        return 'opencase_my_cases';
    }

    public function getTitle(): string {
        return 'Mine sager';
    }

    public function getOrder(): int {
        return 10;
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
                $this->urlGenerator->linkToRouteAbsolute('opencase.page.catch_all', ['path' => 'my-cases']),
                'Vis alle',
            ),
        ];
    }

    public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
        $cases = $this->caseMapper->findResponsibleNotClosed($userId, $limit);

        $items = array_map(function ($case) {
            $updatedAt = $case->getUpdatedAt();
            $dateStr = $updatedAt instanceof \DateTime ? $updatedAt->format('d.m.Y') : '';

            return new WidgetItem(
                $case->getTitle() ?? '',
                $case->getCaseNumber() . ($dateStr ? '  ·  ' . $dateStr : ''),
                $this->urlGenerator->linkToRouteAbsolute('opencase.page.catch_all', ['path' => 'case/' . $case->getId()]),
                $this->urlGenerator->getAbsoluteURL(
                    $this->urlGenerator->imagePath('core', 'filetypes/folder.svg')
                ),
            );
        }, $cases);

        return new WidgetItems(
            $items,
            'Ingen aktive sager',
        );
    }
}
