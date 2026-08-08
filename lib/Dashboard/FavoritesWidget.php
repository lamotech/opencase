<?php

declare(strict_types=1);

namespace OCA\OpenCase\Dashboard;

use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\FavoriteMapper;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IURLGenerator;

class FavoritesWidget implements IWidget, IIconWidget, IAPIWidgetV2, IButtonWidget {

    public function __construct(
        private IURLGenerator $urlGenerator,
        private FavoriteMapper $favoriteMapper,
        private CaseMapper $caseMapper,
        private DocumentMapper $documentMapper,
    ) {}

    public function getId(): string {
        return 'opencase_favorites';
    }

    public function getTitle(): string {
        return 'Favoritter';
    }

    public function getOrder(): int {
        return 12;
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
                $this->urlGenerator->linkToRouteAbsolute('opencase.page.catch_all', ['path' => 'favorites']),
                'Vis alle',
            ),
        ];
    }

    public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
        $favorites = $this->favoriteMapper->findByUser($userId);

        $items = [];
        foreach ($favorites as $fav) {
            if (count($items) >= $limit) {
                break;
            }

            if ($fav->getEntity() === 'case') {
                $case = $this->caseMapper->findById((int)$fav->getEntityKey());
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
            } elseif ($fav->getEntity() === 'document') {
                $doc = $this->documentMapper->findById((int)$fav->getEntityKey());
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
            'Ingen favoritter endnu',
        );
    }
}
