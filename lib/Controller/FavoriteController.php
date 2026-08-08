<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\FavoriteEntity;
use OCA\OpenCase\Db\FavoriteMapper;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class FavoriteController extends ApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private FavoriteMapper $favoriteMapper,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * List all favorites for the current user.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/favorites')]
    public function index(): DataResponse {
        $favorites = $this->favoriteMapper->findByUser($this->userId);
        return new DataResponse([
            'favorites' => array_map(fn($f) => $this->serialize($f), $favorites),
        ]);
    }

    /**
     * Add a favorite.
     *
     * Request body: { entity: 'case'|'document'|'ai_prompt', key: <id> }
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/favorites')]
    public function create(): DataResponse {
        $params = $this->request->getParams();
        $entity = $params['entity'] ?? '';
        $key    = isset($params['key']) ? (int)$params['key'] : 0;

        if (!in_array($entity, ['case', 'document', 'ai_prompt'], true) || $key <= 0) {
            return new DataResponse(['error' => 'Invalid entity or key'], Http::STATUS_BAD_REQUEST);
        }

        // Idempotent — return existing if already favorited
        $existing = $this->favoriteMapper->findByUserAndEntity($this->userId, $entity, $key);
        if ($existing !== null) {
            return new DataResponse(['favorite' => $this->serialize($existing)], Http::STATUS_OK);
        }

        $fav = new FavoriteEntity();
        $fav->setUserId($this->userId);
        $fav->setEntity($entity);
        $fav->setEntityKey($key);
        $fav->setAddedAt(new \DateTime());

        $fav = $this->favoriteMapper->insert($fav);
        return new DataResponse(['favorite' => $this->serialize($fav)], Http::STATUS_CREATED);
    }

    /**
     * Remove a favorite.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/favorites/{entity}/{key}')]
    public function destroy(string $entity, int $key): DataResponse {
        if (!in_array($entity, ['case', 'document', 'ai_prompt'], true) || $key <= 0) {
            return new DataResponse(['error' => 'Invalid entity or key'], Http::STATUS_BAD_REQUEST);
        }

        $this->favoriteMapper->deleteByUserAndEntity($this->userId, $entity, $key);
        return new DataResponse([]);
    }

    private function serialize(FavoriteEntity $fav): array {
        return [
            'id'        => $fav->getId(),
            'entity'    => $fav->getEntity(),
            'key'       => $fav->getEntityKey(),
            'added_at'  => $fav->getAddedAt()?->format('c'),
        ];
    }
}
