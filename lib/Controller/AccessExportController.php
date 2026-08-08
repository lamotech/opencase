<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\AccessRequestMapper;
use OCA\OpenCase\Service\AccessExportService;
use OCA\OpenCase\Service\AccessRequestService;
use OCA\OpenCase\Service\PermissionService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class AccessExportController extends ApiController {

    use CaseAccessGuard;

    public function __construct(
        string $appName,
        IRequest $request,
        private AccessExportService $exportService,
        private AccessRequestMapper $requestMapper,
        private AccessRequestService $requestService,
        private PermissionService $permissionService,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function export(int $id): DataDownloadResponse|DataResponse {
        if ($denied = $this->denyUnlessRequestCase($id, false)) {
            return $denied;
        }

        try {
            $zipPath = $this->exportService->buildZip($id, $this->userId ?? '');

            $content = file_get_contents($zipPath);
            @unlink($zipPath);

            if ($content === false) {
                return new DataResponse(['message' => 'Export failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
            }

            $req      = $this->requestMapper->findById($id);
            $filename = 'aktindsigt_' . $id . '_' . date('Ymd') . '.zip';

            return new DataDownloadResponse($content, $filename, 'application/zip');
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function markSent(int $id): DataResponse {
        if ($denied = $this->denyUnlessRequestCase($id, true)) {
            return $denied;
        }

        try {
            $req    = $this->requestMapper->findById($id);
            $method = $this->request->getParam('delivery_method', 'manual');

            $req->setStatus('sent');
            $req->setUpdatedAt(new \DateTime());
            $this->requestMapper->update($req);

            return new DataResponse(['access_request' => $this->requestService->serialize($req)]);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Both endpoints are addressed by access-request id, so the case behind the
     * request is what the caller's access has to be checked against.
     */
    private function denyUnlessRequestCase(int $requestId, bool $needsWrite): ?DataResponse {
        try {
            $caseId = $this->requestMapper->findById($requestId)->getCaseId();
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        return $needsWrite
            ? $this->denyUnlessCaseWrite($caseId)
            : $this->denyUnlessCaseRead($caseId);
    }
}
