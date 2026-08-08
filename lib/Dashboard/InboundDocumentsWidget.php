<?php

declare(strict_types=1);

namespace OCA\OpenCase\Dashboard;

use OCA\OpenCase\Service\DocumentService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IURLGenerator;

class InboundDocumentsWidget implements IWidget, IIconWidget, IAPIWidgetV2, IButtonWidget {

    public function __construct(
        private IURLGenerator $urlGenerator,
        private DocumentService $documentService,
    ) {}

    public function getId(): string {
        return 'opencase_inbound_documents';
    }

    public function getTitle(): string {
        return 'Indkommende dokumenter';
    }

    public function getOrder(): int {
        return 11;
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
                $this->urlGenerator->linkToRouteAbsolute('opencase.page.catch_all', ['path' => 'inbound']),
                'Vis alle',
            ),
        ];
    }

    public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
        $result    = $this->documentService->listInboxDocuments($userId, $limit);
        $documents = $result['documents'];

        $items = array_map(function ($row) {
            $dateStr = '';
            $rawDate = $row['received_date'] ?? $row['created_at'] ?? null;
            if ($rawDate) {
                try {
                    $dt      = new \DateTime($rawDate);
                    $dateStr = $dt->format('d.m.Y');
                } catch (\Exception) {
                }
            }

            $subtitle = trim(($row['document_type'] ?? '') . ($dateStr ? '  ·  ' . $dateStr : ''));

            return new WidgetItem(
                $row['title'] ?? '',
                $subtitle,
                $this->urlGenerator->linkToRouteAbsolute('opencase.page.catch_all', ['path' => 'document/' . $row['id']]),
                $this->urlGenerator->getAbsoluteURL(
                    $this->urlGenerator->imagePath('core', 'filetypes/x-office-document.svg')
                ),
            );
        }, $documents);

        return new WidgetItems(
            $items,
            'Ingen indkommende dokumenter',
        );
    }
}
