<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\ClassificationPlanMapper;
use OCA\OpenCase\Db\DeletionDeadlineMapper;
use OCA\OpenCase\Service\ClassificationCsvImportService;
use OCA\OpenCase\Service\ClassificationPlanService;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\RoleService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * The customer's own classification plan (Emneplan).
 *
 * Routes:
 *   GET    /api/v1/classification-plan              — active items (case forms)
 *   GET    /api/v1/admin/classification-plan        — all items (Emneplan tab)
 *   POST   /api/v1/admin/classification-plan        — create an item
 *   PUT    /api/v1/admin/classification-plan/{uuid} — update an item
 *   DELETE /api/v1/admin/classification-plan/{uuid} — delete an unused item
 *   POST   /api/v1/admin/classification-plan/import — import a CSV file
 *   GET    /api/v1/admin/deletion-deadlines         — deletion deadlines (slettefrister) to pick from
 *
 * The admin routes require the OpenCase Administrator role.
 */
class ClassificationPlanController extends OCSController {

    public function __construct(
        string $appName,
        IRequest $request,
        private ?string $userId,
        private ClassificationPlanMapper $mapper,
        private DeletionDeadlineMapper $deletionDeadlineMapper,
        private ClassificationPlanService $planService,
        private ClassificationCsvImportService $csvImportService,
        private RoleService $roleService,
    ) {
        parent::__construct($appName, $request);
    }

    private function checkAdministrator(): ?DataResponse {
        if ($this->userId === null || !$this->roleService->userHasRole($this->userId, 'Administrator')) {
            return new DataResponse(
                ['error' => 'OpenCase Administrator role required.'],
                Http::STATUS_FORBIDDEN,
            );
        }
        return null;
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/classification-plan')]
    public function index(): DataResponse {
        return new DataResponse([
            'items' => array_map([ClassificationPlanService::class, 'serialize'], $this->mapper->findAllActive()),
        ]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/admin/classification-plan')]
    public function adminIndex(): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }
        return new DataResponse([
            'items' => array_map([ClassificationPlanService::class, 'serialize'], $this->mapper->findAll()),
        ]);
    }

    /**
     * Request body: { code, title, description?, active?, deletion_deadline_id? }
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/admin/classification-plan')]
    public function create(): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }
        try {
            $item = $this->planService->create(
                (string)$this->request->getParam('code', ''),
                (string)$this->request->getParam('title', ''),
                (string)$this->request->getParam('description', ''),
                filter_var($this->request->getParam('active', true), FILTER_VALIDATE_BOOLEAN),
                $this->deletionDeadlineParam(),
            );
            return new DataResponse(['item' => ClassificationPlanService::serialize($item)], Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Request body: { code, title, description?, active?, deletion_deadline_id? }
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/admin/classification-plan/{uuid}')]
    public function update(string $uuid): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }
        try {
            $item = $this->planService->update(
                $uuid,
                (string)$this->request->getParam('code', ''),
                (string)$this->request->getParam('title', ''),
                (string)$this->request->getParam('description', ''),
                filter_var($this->request->getParam('active', true), FILTER_VALIDATE_BOOLEAN),
                $this->request->getParam('deletion_deadline_id') !== null
                    ? $this->deletionDeadlineParam()
                    // Not given — keep the item's slettefrist
                    : $this->mapper->findByUuid($uuid)?->getDeletionDeadlineId(),
            );
            return new DataResponse(['item' => ClassificationPlanService::serialize($item)]);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * The deletion deadlines (slettefrister) an item can be given.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/admin/deletion-deadlines')]
    public function deletionDeadlines(): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }
        return new DataResponse([
            'deletion_deadlines' => array_map(fn ($d) => [
                'id'            => $d->getId(),
                'code'          => $d->getCode(),
                'name'          => $d->getName(),
                'deletion_days' => $d->getDeletionDays(),
                'expired'       => (bool)$d->getExpired(),
            ], $this->deletionDeadlineMapper->findAll()),
        ]);
    }

    private function deletionDeadlineParam(): ?int {
        $raw = $this->request->getParam('deletion_deadline_id');
        return ($raw === null || $raw === '') ? null : (int)$raw;
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/admin/classification-plan/{uuid}')]
    public function destroy(string $uuid): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }
        try {
            $this->planService->delete($uuid);
            return new DataResponse(['deleted' => true]);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        }
    }

    /**
     * Import an Emneplan CSV file (multipart/form-data, field "file";
     * ';'-delimited, header row "uuid;code;title;description;active", uuid
     * may be empty, and an optional 6th column deletion_deadline — the
     * slettefrist by code, name or id). Items not in the file are deactivated.
     *
     * Response: { fetched, created, updated, deactivated }
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/admin/classification-plan/import')]
    public function import(): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }

        $file = $this->request->getUploadedFile('file');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            $errorMsg = match ($file['error'] ?? UPLOAD_ERR_NO_FILE) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds maximum upload size',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                default => 'File upload failed',
            };
            return new DataResponse(['error' => $errorMsg], Http::STATUS_BAD_REQUEST);
        }

        $content = file_get_contents($file['tmp_name']);
        if ($content === false) {
            return new DataResponse(['error' => 'Failed to read uploaded file'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        try {
            return new DataResponse($this->csvImportService->importPlan($content));
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
