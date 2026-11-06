<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\PhraseCategory;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\PhraseCategoryService;
use OCA\OpenCase\Service\RoleService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * REST API for the Brevdesigner phrase categories.
 *
 * Access control (like TemplateController):
 *   - GET    /api/v1/phrase-categories       — any OpenCase user
 *   - POST   /api/v1/phrase-categories       — Administrator or Template Designer
 *   - PUT    /api/v1/phrase-categories/{id}  — Administrator or Template Designer
 *   - DELETE /api/v1/phrase-categories/{id}  — Administrator or Template Designer
 */
class PhraseCategoryController extends OCSController {

    public function __construct(
        string $appName,
        IRequest $request,
        private PhraseCategoryService $categoryService,
        private RoleService $roleService,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/phrase-categories')]
    public function index(): DataResponse {
        $counts = $this->categoryService->phraseCounts();
        return new DataResponse([
            'categories' => array_map(
                fn(PhraseCategory $c) => $this->serialize($c, $counts[$c->getId()] ?? 0),
                $this->categoryService->list(),
            ),
        ]);
    }

    /**
     * Request body (JSON): name (required), description, parent_id, sort_order.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/phrase-categories')]
    public function create(): DataResponse {
        if ($denied = $this->checkCanManage()) {
            return $denied;
        }
        $params = $this->request->getParams();
        $parentId = $params['parent_id'] ?? null;

        try {
            $category = $this->categoryService->create(
                (string)($params['name'] ?? ''),
                isset($params['description']) ? (string)$params['description'] : null,
                $parentId === null || $parentId === '' ? null : (int)$parentId,
                (int)($params['sort_order'] ?? 0),
                $this->userId,
            );
            return new DataResponse(['category' => $this->serialize($category, 0)], Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Request body (JSON): any of name, description, parent_id, sort_order.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/phrase-categories/{id}')]
    public function update(int $id): DataResponse {
        if ($denied = $this->checkCanManage()) {
            return $denied;
        }
        $params = $this->request->getParams();
        $changes = array_intersect_key($params, array_flip(['name', 'description', 'parent_id', 'sort_order']));

        try {
            $category = $this->categoryService->update($id, $changes, $this->userId);
            $counts = $this->categoryService->phraseCounts();
            return new DataResponse(['category' => $this->serialize($category, $counts[$id] ?? 0)]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/phrase-categories/{id}')]
    public function destroy(int $id): DataResponse {
        if ($denied = $this->checkCanManage()) {
            return $denied;
        }
        try {
            $this->categoryService->delete($id);
            return new DataResponse(['deleted' => true]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    private function checkCanManage(): ?DataResponse {
        if ($this->userId === null
            || !($this->roleService->userHasRole($this->userId, 'Administrator')
                || $this->roleService->userHasRole($this->userId, 'Template Designer'))) {
            return new DataResponse(['error' => 'Not permitted'], Http::STATUS_FORBIDDEN);
        }
        return null;
    }

    private function serialize(PhraseCategory $category, int $phraseCount): array {
        return [
            'id'           => $category->getId(),
            'name'         => $category->getName(),
            'description'  => $category->getDescription(),
            'parent_id'    => $category->getParentId(),
            'sort_order'   => $category->getSortOrder(),
            'phrase_count' => $phraseCount,
            'created_at'   => $category->getCreatedAt()?->format('c'),
            'created_by'   => $category->getCreatedBy(),
            'updated_at'   => $category->getUpdatedAt()?->format('c'),
            'updated_by'   => $category->getUpdatedBy(),
        ];
    }
}
