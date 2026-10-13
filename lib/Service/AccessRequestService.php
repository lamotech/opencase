<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\AccessDecisionMapper;
use OCA\OpenCase\Db\AccessRequest;
use OCA\OpenCase\Db\AccessRequestItemMapper;
use OCA\OpenCase\Db\AccessRequestMapper;
use OCA\OpenCase\Db\CaseMapper;
use OCP\IUserManager;

class AccessRequestService {

    private const VALID_TYPES    = ['public_access', 'party_access', 'gdpr_access'];
    private const VALID_STATUSES = [
        'received', 'collecting', 'reviewing', 'redacting',
        'approval', 'approved', 'sent', 'rejected', 'partly_rejected', 'closed',
    ];

    public function __construct(
        private AccessRequestMapper $requestMapper,
        private AccessRequestItemMapper $itemMapper,
        private AccessDecisionMapper $decisionMapper,
        private CaseMapper $caseMapper,
        private AccessDeadlineService $deadlineService,
        private AuditService $auditService,
        private IUserManager $userManager,
    ) {
    }

    public function create(
        int $caseId,
        string $type,
        string $subject,
        string $createdBy,
        array $data = [],
    ): AccessRequest {
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid type '{$type}'");
        }

        if ($this->caseMapper->findById($caseId) === null) {
            throw new \OCP\AppFramework\Db\DoesNotExistException('Case not found');
        }

        $now      = new \DateTime();
        $deadline = $this->deadlineService->computeDeadline($type, $now);

        $req = new AccessRequest();
        $req->setCaseId($caseId);
        $req->setUuid($this->generateUuid());
        $req->setType($type);
        $req->setStatus('received');
        $req->setSubject($subject);
        $req->setDescription($data['description'] ?? null);
        $req->setRequesterName($data['requester_name'] ?? null);
        $req->setRequesterEmail($data['requester_email'] ?? null);
        $req->setRequesterPhone($data['requester_phone'] ?? null);
        $req->setRequesterIdentifier($data['requester_identifier'] ?? null);
        $req->setAssignedUser(($data['assigned_user'] ?? '') !== '' ? $data['assigned_user'] : $createdBy);
        $req->setLegalBasis($data['legal_basis'] ?? null);
        $req->setReceivedAt($now);
        $req->setDeadlineAt($deadline);
        $req->setCreatedBy($createdBy);
        $req->setCreatedAt($now);
        $req->setUpdatedAt($now);

        $saved = $this->requestMapper->insert($req);

        $this->auditService->logAccessRequestCreated($caseId, $createdBy, $saved->getId(), $type);

        return $saved;
    }

    public function update(int $id, string $userId, array $data): AccessRequest {
        $req = $this->requestMapper->findById($id);

        if (isset($data['type']) && $data['type'] !== $req->getType()) {
            if (!in_array($data['type'], self::VALID_TYPES, true)) {
                throw new \InvalidArgumentException("Invalid type '{$data['type']}'");
            }
            $req->setType($data['type']);
            $req->setDeadlineAt($this->deadlineService->computeDeadline($data['type'], $req->getReceivedAt() ?? new \DateTime()));
        }
        if (isset($data['subject']) && trim($data['subject']) !== '') { $req->setSubject($data['subject']); }
        if (isset($data['assigned_user']))      { $req->setAssignedUser($data['assigned_user'] !== '' ? $data['assigned_user'] : null); }
        if (isset($data['requester_name']))      { $req->setRequesterName($data['requester_name']); }
        if (isset($data['requester_email']))     { $req->setRequesterEmail($data['requester_email']); }
        if (isset($data['requester_phone']))     { $req->setRequesterPhone($data['requester_phone']); }
        if (isset($data['requester_identifier'])){ $req->setRequesterIdentifier($data['requester_identifier']); }
        if (isset($data['description']))         { $req->setDescription($data['description']); }
        if (isset($data['legal_basis']))         { $req->setLegalBasis($data['legal_basis']); }

        if (isset($data['extended_deadline_at']) && $data['extended_deadline_at']) {
            $req->setExtendedDeadlineAt(new \DateTime($data['extended_deadline_at']));
            $req->setExtensionReason($data['extension_reason'] ?? null);
        }

        $req->setUpdatedAt(new \DateTime());
        $saved = $this->requestMapper->update($req);

        $this->auditService->logAccessRequestUpdated($req->getCaseId(), $userId, $id);

        return $saved;
    }

    public function changeStatus(int $id, string $status, string $userId): AccessRequest {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid status '{$status}'");
        }

        $req = $this->requestMapper->findById($id);
        $old = $req->getStatus();
        $req->setStatus($status);
        $req->setUpdatedAt(new \DateTime());
        $saved = $this->requestMapper->update($req);

        $this->auditService->logAccessRequestStatusChanged($req->getCaseId(), $userId, $id, $old, $status);

        return $saved;
    }

    private function generateUuid(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Open (not sent/rejected/closed) requests assigned to $userId, serialized
     * with the owning case's number, soonest deadline first.
     */
    public function listOpenAssignedTo(string $userId): array {
        $caseNumbers = [];
        $result = [];
        foreach ($this->requestMapper->findByAssignedUser($userId) as $req) {
            $caseId = $req->getCaseId();
            if (!array_key_exists($caseId, $caseNumbers)) {
                $caseNumbers[$caseId] = $this->caseMapper->findById($caseId)?->getCaseNumber();
            }
            $result[] = $this->serialize($req) + ['case_number' => $caseNumbers[$caseId]];
        }
        usort($result, fn($a, $b) => strcmp($a['effective_deadline_at'], $b['effective_deadline_at']));
        return $result;
    }

    public function serialize(AccessRequest $req): array {
        $effectiveDeadline = $this->deadlineService->effectiveDeadline(
            $req->getExtendedDeadlineAt(),
            $req->getDeadlineAt(),
        );

        return [
            'id'                    => $req->getId(),
            'uuid'                  => $req->getUuid(),
            'case_id'               => $req->getCaseId(),
            'type'                  => $req->getType(),
            'status'                => $req->getStatus(),
            'requester_name'        => $req->getRequesterName(),
            'requester_email'       => $req->getRequesterEmail(),
            'requester_phone'       => $req->getRequesterPhone(),
            'requester_identifier'  => $req->getRequesterIdentifier(),
            'subject'               => $req->getSubject(),
            'description'           => $req->getDescription(),
            'assigned_user'         => $req->getAssignedUser(),
            'assigned_user_display_name' => $req->getAssignedUser() !== null && $req->getAssignedUser() !== ''
                ? ($this->userManager->getDisplayName($req->getAssignedUser()) ?? $req->getAssignedUser())
                : null,
            'received_at'           => $req->getReceivedAt()?->format('c'),
            'deadline_at'           => $req->getDeadlineAt()?->format('c'),
            'extended_deadline_at'  => $req->getExtendedDeadlineAt()?->format('c'),
            'extension_reason'      => $req->getExtensionReason(),
            'effective_deadline_at' => $effectiveDeadline->format('c'),
            'deadline_colour'       => $this->deadlineService->deadlineColour($effectiveDeadline),
            'legal_basis'           => $req->getLegalBasis(),
            'created_by'            => $req->getCreatedBy(),
            'created_at'            => $req->getCreatedAt()?->format('c'),
            'updated_at'            => $req->getUpdatedAt()?->format('c'),
        ];
    }
}
