<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\BulkUpdate;
use OCA\OpenCase\Db\BulkUpdateItem;
use OCA\OpenCase\Db\BulkUpdateItemMapper;
use OCA\OpenCase\Db\BulkUpdateMapper;
use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Service\CaseService;
use OCA\OpenCase\Service\DocumentService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class BulkUpdateController extends ApiController {

    // Hard cap on how many matching cases/documents a single job will
    // snapshot into opencase_bulkupdate_items. Protects against an
    // unbounded scan/insert for a near-unfiltered search on a large instance.
    private const MAX_ITEMS = 20000;
    private const PAGE_SIZE = 1000;

    public function __construct(
        string $appName,
        IRequest $request,
        private BulkUpdateMapper $bulkUpdateMapper,
        private BulkUpdateItemMapper $bulkUpdateItemMapper,
        private CaseService $caseService,
        private CaseMapper $caseMapper,
        private DocumentService $documentService,
        private DocumentMapper $documentMapper,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * List the current user's saved bulk-update jobs for an entity type,
     * newest first, including per-job success/failure item counts.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/bulkupdates')]
    public function index(string $entity): DataResponse {
        $jobs   = $this->bulkUpdateMapper->findByUserAndEntity($this->userId, $entity);
        $counts = $this->bulkUpdateItemMapper->countsByBulkUpdateIds(array_map(fn($j) => $j->getId(), $jobs));
        return new DataResponse([
            'bulk_updates' => array_map(fn($j) => $this->serialize($j, $counts[$j->getId()] ?? []), $jobs),
        ]);
    }

    /**
     * The failed items of a bulk-update job (case number + error message),
     * for the "why did these fail" dialog.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/bulkupdates/{id}/failed-items')]
    public function failedItems(int $id): DataResponse {
        $job = $this->bulkUpdateMapper->findById($id);
        if ($job === null || $job->getCreatedBy() !== $this->userId) {
            return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        $items = $this->bulkUpdateItemMapper->findByBulkUpdateIdAndStatus($id, 'failed');

        return new DataResponse([
            'items' => array_map(function ($item) use ($job) {
                $number = null;
                if ($job->getEntity() === 'case') {
                    $number = $this->caseMapper->findById($item->getEntityId())?->getCaseNumber();
                } elseif ($job->getEntity() === 'document') {
                    $number = $this->documentMapper->findById($item->getEntityId())?->getDocumentNumber();
                }
                return [
                    'entity_id'   => $item->getEntityId(),
                    'case_number' => $number,
                    'error_msg'   => $item->getErrorMsg(),
                ];
            }, $items),
        ]);
    }

    /**
     * Cancel a bulk-update job that hasn't finished yet. ProcessBulkUpdate
     * checks the job's status before working each item and stops as soon as
     * it sees 'cancelled', so items already applied stay applied — only the
     * ones not yet reached are skipped.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/bulkupdates/{id}/cancel')]
    public function cancel(int $id): DataResponse {
        $job = $this->bulkUpdateMapper->findById($id);
        if ($job === null || $job->getCreatedBy() !== $this->userId) {
            return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
        if (!in_array($job->getStatus(), ['created', 'pending', 'processing'], true)) {
            return new DataResponse(['error' => 'Job can no longer be cancelled'], Http::STATUS_BAD_REQUEST);
        }

        $job->setStatus('cancelled');
        $job = $this->bulkUpdateMapper->update($job);

        return new DataResponse(['bulk_update' => $this->serialize($job)]);
    }

    /**
     * Save a new bulk-update job — nothing is applied yet. `criterias` is the
     * search filter the user built; `updates` is the field=>value map to
     * apply. Every case or document (per `entity`) matching `criterias` right
     * now (re-queried here, not trusted from the client) is snapshotted into
     * opencase_bulkupdate_items with status=pending, and `total_count` is set
     * to how many were actually captured. The job itself starts as 'created'
     * and only flips to 'pending' — ready for ProcessBulkUpdate to pick up —
     * once every item row has been inserted.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/bulkupdates')]
    public function create(): DataResponse {
        $params    = $this->getJsonBody();
        $title     = trim((string)($params['title'] ?? ''));
        $entity    = (string)($params['entity'] ?? '');
        $criterias = is_array($params['criterias'] ?? null) ? $params['criterias'] : [];

        if ($title === '' || !in_array($entity, ['case', 'document'], true)) {
            return new DataResponse(['error' => 'Invalid title or entity'], Http::STATUS_BAD_REQUEST);
        }

        $itemIds = $entity === 'case'
            ? $this->collectMatchingCaseIds($criterias)
            : $this->collectMatchingDocumentIds($criterias);

        $job = new BulkUpdate();
        $job->setTitle($title);
        $job->setEntity($entity);
        $job->setCreatedAt(new \DateTime());
        $job->setCreatedBy($this->userId);
        $job->setTotalCount(count($itemIds));
        $job->setStatus('created');
        $job->setCriterias(json_encode($criterias));
        $job->setUpdates(json_encode($params['updates'] ?? []));

        $job = $this->bulkUpdateMapper->insert($job);

        foreach ($itemIds as $entityId) {
            $item = new BulkUpdateItem();
            $item->setBulkupdateId($job->getId());
            $item->setEntityId($entityId);
            $item->setStatus('pending');
            $item->setUpdatedAt(new \DateTime());
            $this->bulkUpdateItemMapper->insert($item);
        }

        // Only once every item row exists does the job become visible to the
        // (not yet implemented) processing step as ready to work on.
        $job->setStatus('pending');
        $job = $this->bulkUpdateMapper->update($job);

        return new DataResponse(['bulk_update' => $this->serialize($job)], Http::STATUS_CREATED);
    }

    /**
     * Re-runs the case search behind `criterias` (the same filter vocabulary
     * as GET /cases) to collect every matching case id, paging through
     * CaseService::list() up to self::MAX_ITEMS.
     *
     * @return int[]
     */
    private function collectMatchingCaseIds(array $criterias): array {
        $ids    = [];
        $offset = 0;

        do {
            $result = $this->caseService->list(
                $this->userId,
                $criterias['organisation'] ?? null,
                $criterias['year'] ?? null,
                isset($criterias['status_id']) ? (int)$criterias['status_id'] : null,
                $criterias['search'] ?? null,
                self::PAGE_SIZE,
                $offset,
                $criterias['classification_code'] ?? null,
                $criterias['sensitivity_key'] ?? null,
                $criterias['classification_facet_uuid'] ?? null,
                isset($criterias['insight_level_id']) ? (int)$criterias['insight_level_id'] : null,
                $criterias['responsible_user_id'] ?? null,
                isset($criterias['casetype_id']) ? (int)$criterias['casetype_id'] : null,
                [],
            );
            foreach ($result['cases'] as $case) {
                $ids[] = $case->getId();
            }
            $offset += self::PAGE_SIZE;
        } while ($offset < $result['total'] && count($ids) < self::MAX_ITEMS);

        return $ids;
    }

    /**
     * Re-runs the document search behind `criterias` (the same filter
     * vocabulary as GET /documents) to collect every matching document id,
     * paging through DocumentService::searchDocuments() up to self::MAX_ITEMS.
     *
     * @return int[]
     */
    private function collectMatchingDocumentIds(array $criterias): array {
        $ids    = [];
        $offset = 0;

        do {
            $result = $this->documentService->searchDocuments(
                $this->userId,
                $criterias['title'] ?? null,
                $criterias['document_type'] ?? null,
                isset($criterias['status']) ? (int)$criterias['status'] : null,
                $criterias['date_from'] ?? null,
                $criterias['date_to'] ?? null,
                $criterias['organisation'] ?? null,
                self::PAGE_SIZE,
                $offset,
                isset($criterias['document_category_id']) ? (int)$criterias['document_category_id'] : null,
                isset($criterias['insight_level_id']) ? (int)$criterias['insight_level_id'] : null,
                null,
            );
            foreach ($result['documents'] as $row) {
                $ids[] = (int)$row['id'];
            }
            $offset += self::PAGE_SIZE;
        } while ($offset < $result['total'] && count($ids) < self::MAX_ITEMS);

        return $ids;
    }

    private function getJsonBody(): array {
        // Nextcloud parses a JSON request body into params automatically.
        return $this->request->getParams();
    }

    private function serialize(BulkUpdate $job, array $itemCounts = []): array {
        return [
            'id'             => $job->getId(),
            'title'          => $job->getTitle(),
            'entity'         => $job->getEntity(),
            'created_at'     => $job->getCreatedAt()?->format('c'),
            'created_by'     => $job->getCreatedBy(),
            'total_count'    => $job->getTotalCount(),
            'success_count'  => $itemCounts['successed'] ?? 0,
            'failed_count'   => $itemCounts['failed'] ?? 0,
            'status'         => $job->getStatus(),
            'criterias'      => json_decode($job->getCriterias(), true) ?? [],
            'updates'        => json_decode($job->getUpdates(), true) ?? [],
        ];
    }
}
