<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\AccessRedaction;
use OCA\OpenCase\Db\AccessRedactionMapper;
use OCA\OpenCase\Db\AccessRequestItemMapper;

class AccessRedactionService {

    private const VALID_TYPES = [
        'manual', 'cpr', 'name', 'address', 'email', 'phone', 'health', 'confidential', 'other',
    ];

    public function __construct(
        private AccessRedactionMapper $redactionMapper,
        private AccessRequestItemMapper $itemMapper,
        private AuditService $auditService,
    ) {
    }

    public function create(int $requestItemId, string $userId, array $data, int $caseId): AccessRedaction {
        $type = $data['redaction_type'] ?? 'manual';
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid redaction type '{$type}'");
        }

        $this->itemMapper->findById($requestItemId); // validates item exists

        $r = new AccessRedaction();
        $r->setRequestItemId($requestItemId);
        $r->setRedactionType($type);
        $r->setOriginalText($data['original_text'] ?? null);
        $r->setReplacementText($data['replacement_text'] ?? '████');
        $r->setReason($data['reason'] ?? null);
        $r->setLegalReference($data['legal_reference'] ?? null);
        $r->setCreatedBy($userId);
        $r->setCreatedAt(new \DateTime());

        $saved = $this->redactionMapper->insert($r);

        $this->auditService->logAccessRequestUpdated($caseId, $userId, 0);

        return $saved;
    }

    public function delete(int $id, int $caseId, string $userId): void {
        $r = $this->redactionMapper->findById($id);
        $this->redactionMapper->delete($r);
        $this->auditService->logAccessRequestUpdated($caseId, $userId, 0);
    }

    public function serializeRedaction(AccessRedaction $r): array {
        return [
            'id'               => $r->getId(),
            'request_item_id'  => $r->getRequestItemId(),
            'redaction_type'   => $r->getRedactionType(),
            'original_text'    => $r->getOriginalText(),
            'replacement_text' => $r->getReplacementText(),
            'reason'           => $r->getReason(),
            'legal_reference'  => $r->getLegalReference(),
            'created_by'       => $r->getCreatedBy(),
            'created_at'       => $r->getCreatedAt()?->format('c'),
        ];
    }
}
