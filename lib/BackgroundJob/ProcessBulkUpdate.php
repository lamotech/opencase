<?php

declare(strict_types=1);

namespace OCA\OpenCase\BackgroundJob;

use OCA\OpenCase\Db\AccessProfileMapper;
use OCA\OpenCase\Db\BulkUpdate;
use OCA\OpenCase\Db\BulkUpdateItem;
use OCA\OpenCase\Db\BulkUpdateItemMapper;
use OCA\OpenCase\Db\BulkUpdateMapper;
use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\ClassificationSubjectMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Db\SensitivityMapper;
use OCA\OpenCase\Service\CaseService;
use OCA\OpenCase\Service\DocumentService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Processes bulk-update jobs saved by the "Ny Masseredigering" flow.
 *
 * Runs hourly: picks up every opencase_bulkupdate row still at status
 * 'pending' (oldest first), applies its `updates` map to each of its
 * opencase_bulkupdate_items, and records a per-item outcome.
 */
class ProcessBulkUpdate extends TimedJob {

    public function __construct(
        ITimeFactory $time,
        private BulkUpdateMapper $bulkUpdateMapper,
        private BulkUpdateItemMapper $bulkUpdateItemMapper,
        private CaseService $caseService,
        private CaseMapper $caseMapper,
        private DocumentService $documentService,
        private DocumentMapper $documentMapper,
        private AccessProfileMapper $accessProfileMapper,
        private OrganisationMapper $organisationMapper,
        private ClassificationSubjectMapper $classificationSubjectMapper,
        private SensitivityMapper $sensitivityMapper,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(3600); // 1 hour
    }

    protected function run(mixed $argument): void {
        $jobs = $this->bulkUpdateMapper->findByStatus('pending');

        foreach ($jobs as $job) {
            $job->setStatus('processing');
            $this->bulkUpdateMapper->update($job);

            $updates   = json_decode($job->getUpdates(), true) ?? [];
            $items     = $this->bulkUpdateItemMapper->findByBulkUpdateId($job->getId());
            $cancelled = false;

            foreach ($items as $item) {
                // Re-read the job's own row before each item — a user can
                // cancel it (PUT .../cancel) from another request at any
                // moment while this loop is running. Items already applied
                // stay applied; only the ones not yet reached are skipped.
                $current = $this->bulkUpdateMapper->findById($job->getId());
                if ($current === null || $current->getStatus() === 'cancelled') {
                    $cancelled = true;
                    break;
                }

                $this->processItem($job, $item, $updates);
            }

            if (!$cancelled) {
                $job->setStatus('processed');
                $this->bulkUpdateMapper->update($job);
            }
        }
    }

    private function processItem(BulkUpdate $job, BulkUpdateItem $item, array $updates): void {
        $item->setStatus('processing');
        $item->setUpdatedAt(new \DateTime());
        $this->bulkUpdateItemMapper->update($item);

        try {
            if ($job->getEntity() === 'case') {
                $item->setOriginalValues(json_encode(
                    $this->currentCaseValues($item->getEntityId(), array_keys($updates))
                ));
                $this->applyCaseUpdate($item->getEntityId(), $updates, $job->getCreatedBy());
            } elseif ($job->getEntity() === 'document') {
                $item->setOriginalValues(json_encode(
                    $this->currentDocumentValues($item->getEntityId(), array_keys($updates))
                ));
                $this->applyDocumentUpdate($item->getEntityId(), $updates, $job->getCreatedBy());
            } else {
                throw new \RuntimeException("Unknown bulk-update entity type: {$job->getEntity()}");
            }

            $item->setStatus('successed');
            $item->setErrorMsg(null);
        } catch (Throwable $e) {
            $item->setStatus('failed');
            $item->setErrorMsg($e->getMessage());
            $this->logger->warning('OpenCase ProcessBulkUpdate: item failed', [
                'bulkupdate_id' => $job->getId(),
                'entity_id'     => $item->getEntityId(),
                'exception'     => $e,
            ]);
        }

        $item->setUpdatedAt(new \DateTime());
        $this->bulkUpdateItemMapper->update($item);
    }

    /**
     * Snapshot of a case's current value for each field about to be
     * overwritten. organisation/classification_code/sensitivity live on the
     * case's access profile, not the case row itself, so they need an extra
     * lookup; the rest read straight off the entity.
     */
    private function currentCaseValues(int $caseId, array $fields): array {
        $case = $this->caseMapper->findById($caseId);
        if ($case === null) {
            throw new \RuntimeException("Case {$caseId} not found");
        }

        $needsProfile = array_intersect($fields, ['organisation', 'classification_code', 'sensitivity']);
        $profile      = $needsProfile ? $this->accessProfileMapper->findById($case->getAccessProfileId()) : null;

        $values = [];
        foreach ($fields as $field) {
            $values[$field] = match ($field) {
                'title' => $case->getTitle(),
                'organisation' => $profile
                    ? $this->organisationMapper->findByUuid($profile->getOrgUuid())?->getOrgName()
                    : null,
                'classification_code' => $profile
                    ? $this->classificationSubjectMapper->findByUuid($profile->getClassSubjectUuid())?->getCode()
                    : null,
                'sensitivity' => $profile
                    ? $this->sensitivityMapper->findByUuid($profile->getSensitivityUuid())?->getKey()
                    : null,
                'classification_facet_uuid' => $case->getClassificationFacetUuid(),
                'insight_level_id' => $case->getInsightLevelId(),
                'responsible_user_id' => $case->getResponsibleUserId(),
                'summary' => $case->getSummary(),
                default => null,
            };
        }

        return $values;
    }

    private function applyCaseUpdate(int $caseId, array $updates, string $userId): void {
        $this->caseService->update(
            $caseId,
            $updates['title'] ?? null,
            $updates['organisation'] ?? null,
            $updates['classification_code'] ?? null,
            $updates['sensitivity'] ?? null,
            $userId,
            $updates['responsible_user_id'] ?? null,
            isset($updates['insight_level_id']) ? (int)$updates['insight_level_id'] : null,
            array_key_exists('insight_level_id', $updates),
            $updates['classification_facet_uuid'] ?? null,
            array_key_exists('classification_facet_uuid', $updates),
            $updates['summary'] ?? null,
            array_key_exists('summary', $updates),
        );
    }

    /**
     * Snapshot of a document's current value for each field about to be
     * overwritten — all of them read straight off the entity.
     */
    private function currentDocumentValues(int $documentId, array $fields): array {
        $document = $this->documentMapper->findById($documentId);
        if ($document === null) {
            throw new \RuntimeException("Document {$documentId} not found");
        }

        $values = [];
        foreach ($fields as $field) {
            $values[$field] = match ($field) {
                'title' => $document->getTitle(),
                'document_type' => $document->getDocumentType(),
                'status' => $document->getStatus(),
                'insight_level_id' => $document->getInsightLevelId(),
                'document_date' => $document->getDocumentDate(),
                'received_date' => $document->getReceivedDate(),
                'registered_date' => $document->getRegisteredDate(),
                default => null,
            };
        }

        return $values;
    }

    private function applyDocumentUpdate(int $documentId, array $updates, string $userId): void {
        $this->documentService->update(
            $documentId,
            $updates['title'] ?? null,
            $updates['document_type'] ?? null,
            isset($updates['status']) ? (int)$updates['status'] : null,
            $userId,
            isset($updates['insight_level_id']) ? (int)$updates['insight_level_id'] : null,
            array_key_exists('insight_level_id', $updates),
            $updates['document_date'] ?? null,
            array_key_exists('document_date', $updates),
            $updates['received_date'] ?? null,
            array_key_exists('received_date', $updates),
            $updates['registered_date'] ?? null,
            array_key_exists('registered_date', $updates),
        );
    }
}
