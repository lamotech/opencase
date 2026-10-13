<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\AccessMaskSuggestionMapper;
use OCA\OpenCase\Db\AccessRequestItem;
use OCA\OpenCase\Db\AccessRequestItemMapper;
use OCA\OpenCase\Db\AccessRequestMapper;
use OCA\OpenCase\Db\FileMapper;
use OCA\OpenCase\Service\AccessMaskingService;
use OCA\OpenCase\Service\PermissionService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Automatic masking of access-request files through the redaction service.
 * See AccessMaskingService for the flow.
 */
class AccessMaskingController extends ApiController {

    use CaseAccessGuard;

    public function __construct(
        string $appName,
        IRequest $request,
        private AccessMaskingService $maskingService,
        private AccessMaskSuggestionMapper $suggestionMapper,
        private AccessRequestItemMapper $itemMapper,
        private AccessRequestMapper $requestMapper,
        private FileMapper $fileMapper,
        private PermissionService $permissionService,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function suggestions(int $itemId, int $fileId): DataResponse {
        [$item, $caseId, $error] = $this->resolve($itemId, $fileId, false);
        if ($error) {
            return $error;
        }
        return new DataResponse(['suggestions' => $this->maskingService->listSuggestions($item->getId(), $fileId)]);
    }

    #[NoAdminRequired]
    public function analyze(int $itemId, int $fileId): DataResponse {
        [$item, $caseId, $error] = $this->resolve($itemId, $fileId, true);
        if ($error) {
            return $error;
        }
        try {
            return new DataResponse($this->maskingService->analyze($item, $fileId, $this->userId));
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
        }
    }

    #[NoAdminRequired]
    public function addTerm(int $itemId, int $fileId): DataResponse {
        [$item, $caseId, $error] = $this->resolve($itemId, $fileId, true);
        if ($error) {
            return $error;
        }
        try {
            $pages = $this->maskingService->addTerm($item, $fileId, (string) $this->request->getParam('term', ''), $this->userId);
            return new DataResponse([
                'found_on_pages' => $pages,
                'suggestions'    => $this->maskingService->listSuggestions($item->getId(), $fileId),
            ]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
        }
    }

    #[NoAdminRequired]
    public function updateSuggestion(int $suggestionId): DataResponse {
        try {
            $s = $this->suggestionMapper->findById($suggestionId);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
        [, , $error] = $this->resolve($s->getRequestItemId(), $s->getFileId(), true);
        if ($error) {
            return $error;
        }
        try {
            $s = $this->maskingService->setStatus($s, (string) $this->request->getParam('status', ''));
            return new DataResponse(['suggestion' => $this->maskingService->serialize($s)]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    public function apply(int $itemId, int $fileId): DataResponse {
        [$item, $caseId, $error] = $this->resolve($itemId, $fileId, true);
        if ($error) {
            return $error;
        }
        try {
            $areas = $this->maskingService->apply($item, $fileId, $caseId, $this->userId);
            return new DataResponse(['has_masked' => true, 'area_count' => $areas]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
        }
    }

    /**
     * Load the item, check the caller's access to its case, and check that the
     * file belongs to the item's document.
     *
     * @return array{0: ?AccessRequestItem, 1: ?int, 2: ?DataResponse}
     */
    private function resolve(int $itemId, int $fileId, bool $needsWrite): array {
        try {
            $item   = $this->itemMapper->findById($itemId);
            $caseId = $this->requestMapper->findById($item->getRequestId())->getCaseId();
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return [null, null, new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND)];
        }

        $denied = $needsWrite ? $this->denyUnlessCaseWrite($caseId) : $this->denyUnlessCaseRead($caseId);
        if ($denied) {
            return [null, null, $denied];
        }

        $file = $this->fileMapper->findById($fileId);
        if ($file === null || $item->getSourceType() !== 'document' || $file->getDocumentId() !== $item->getSourceId()) {
            return [null, null, new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND)];
        }

        return [$item, $caseId, null];
    }
}
