<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\HistoryEntity;
use OCA\OpenCase\Db\HistoryMapper;

/**
 * Tracks the most recently accessed cases and documents per user in
 * opencase_history, capped at the configured history_count entries per
 * user/entity.
 *
 * Recording is fire-and-forget: errors are silently swallowed so that a
 * history-tracking failure never aborts a business operation.
 */
class HistoryService {

    public function __construct(
        private HistoryMapper $mapper,
        private Configuration $configuration,
    ) {
    }

    public function recordCaseAccess(int $caseId, string $userId): void {
        $this->recordAccess($userId, 'case', $caseId);
    }

    public function recordDocumentAccess(int $documentId, string $userId): void {
        $this->recordAccess($userId, 'document', $documentId);
    }

    /**
     * @return HistoryEntity[]
     */
    public function findByUser(string $userId): array {
        return $this->mapper->findByUser($userId);
    }

    private function recordAccess(string $userId, string $entity, int $key): void {
        try {
            $existing = $this->mapper->findByUserAndEntity($userId, $entity, $key);
            $now = new \DateTime();

            if ($existing !== null) {
                $existing->setAccessedAt($now);
                $this->mapper->update($existing);
            } else {
                $entry = new HistoryEntity();
                $entry->setUserId($userId);
                $entry->setEntity($entity);
                $entry->setEntityKey($key);
                $entry->setAccessedAt($now);
                $this->mapper->insert($entry);
            }

            $historyCount = (int)$this->configuration->getConfigValue('history_count', '20');
            if ($historyCount > 0) {
                $this->mapper->trimOldest($userId, $entity, $historyCount);
            }
        } catch (\Throwable) {
            // Never let history tracking fail a business operation
        }
    }
}
