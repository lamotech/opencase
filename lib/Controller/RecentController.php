<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\HistoryEntity;
use OCA\OpenCase\Service\HistoryService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class RecentController extends ApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private HistoryService $historyService,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * List the current user's recently accessed cases and documents,
     * newest first.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/recent')]
    public function index(): DataResponse {
        $entries = $this->historyService->findByUser($this->userId ?? '');
        return new DataResponse([
            'recent' => array_map(fn($e) => $this->serialize($e), $entries),
        ]);
    }

    private function serialize(HistoryEntity $entry): array {
        return [
            'entity'      => $entry->getEntity(),
            'key'         => $entry->getEntityKey(),
            'accessed_at' => $entry->getAccessedAt()?->format('c'),
        ];
    }
}
