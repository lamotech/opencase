<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\AccessDecision;
use OCA\OpenCase\Db\AccessDecisionMapper;
use OCA\OpenCase\Db\AccessRequestMapper;

class AccessDecisionService {

    private const VALID_DECISIONS = ['full_access', 'partial_access', 'rejected', 'withdrawn'];

    private const COMPLAINT_GUIDANCE_DEFAULT = 'Hvis du er uenig i denne afgørelse, kan du klage til Ankestyrelsen inden 4 uger fra modtagelsen af dette brev.';

    public function __construct(
        private AccessDecisionMapper $decisionMapper,
        private AccessRequestMapper $requestMapper,
        private AuditService $auditService,
    ) {
    }

    public function save(int $requestId, string $userId, array $data): AccessDecision {
        $decision = $data['decision'] ?? '';
        if (!in_array($decision, self::VALID_DECISIONS, true)) {
            throw new \InvalidArgumentException("Invalid decision '{$decision}'");
        }

        $req = $this->requestMapper->findById($requestId);
        $now = new \DateTime();

        $existing = $this->decisionMapper->findByRequest($requestId);
        if ($existing !== null) {
            $existing->setDecision($decision);
            $existing->setDecisionText($data['decision_text'] ?? '');
            $existing->setComplaintGuidance($data['complaint_guidance'] ?? self::COMPLAINT_GUIDANCE_DEFAULT);
            $existing->setUpdatedAt($now);
            $saved = $this->decisionMapper->update($existing);
        } else {
            $d = new AccessDecision();
            $d->setRequestId($requestId);
            $d->setDecision($decision);
            $d->setDecisionText($data['decision_text'] ?? '');
            $d->setComplaintGuidance($data['complaint_guidance'] ?? self::COMPLAINT_GUIDANCE_DEFAULT);
            $d->setCreatedBy($userId);
            $d->setCreatedAt($now);
            $d->setUpdatedAt($now);
            $saved = $this->decisionMapper->insert($d);
        }

        $this->auditService->logAccessRequestDecision($req->getCaseId(), $userId, $requestId, $decision);
        return $saved;
    }

    public function approve(int $requestId, string $userId): AccessDecision {
        $dec = $this->decisionMapper->findByRequest($requestId);
        if ($dec === null) {
            throw new \RuntimeException('No decision draft exists for this request');
        }

        $dec->setApprovedBy($userId);
        $dec->setApprovedAt(new \DateTime());
        $dec->setUpdatedAt(new \DateTime());
        $saved = $this->decisionMapper->update($dec);

        $req = $this->requestMapper->findById($requestId);
        $req->setStatus('approval');
        $req->setUpdatedAt(new \DateTime());
        $this->requestMapper->update($req);

        $this->auditService->logAccessRequestStatusChanged($req->getCaseId(), $userId, $requestId, 'redacting', 'approval');
        return $saved;
    }

    public function serializeDecision(AccessDecision $d): array {
        return [
            'id'                => $d->getId(),
            'request_id'        => $d->getRequestId(),
            'decision'          => $d->getDecision(),
            'decision_text'     => $d->getDecisionText(),
            'complaint_guidance' => $d->getComplaintGuidance(),
            'approved_by'       => $d->getApprovedBy(),
            'approved_at'       => $d->getApprovedAt()?->format('c'),
            'sent_by'           => $d->getSentBy(),
            'sent_at'           => $d->getSentAt()?->format('c'),
            'delivery_method'   => $d->getDeliveryMethod(),
            'created_by'        => $d->getCreatedBy(),
            'created_at'        => $d->getCreatedAt()?->format('c'),
        ];
    }

    /** Standard decision text templates. */
    public function getTemplates(): array {
        return [
            [
                'key'      => 'full_access',
                'label'    => 'Fuld aktindsigt',
                'decision' => 'full_access',
                'text'     => "Du har anmodet om aktindsigt i sagen. Vi imødekommer din anmodning og sender dig hermed det ønskede materiale.",
            ],
            [
                'key'      => 'partial_access',
                'label'    => 'Delvis aktindsigt',
                'decision' => 'partial_access',
                'text'     => "Du har anmodet om aktindsigt i sagen. Vi imødekommer delvist din anmodning. En del af materialet er undtaget fra aktindsigt, jf. nedenstående begrundelse.",
            ],
            [
                'key'      => 'rejected_23',
                'label'    => 'Afslag §23 (interne arbejdsdokumenter)',
                'decision' => 'rejected',
                'text'     => "Din anmodning om aktindsigt afslås. Det materiale, du ønsker aktindsigt i, er undtaget fra aktindsigt i medfør af offentlighedslovens §23 om interne arbejdsdokumenter.",
            ],
            [
                'key'      => 'rejected_personal_data',
                'label'    => 'Afslag - personfølsomme oplysninger',
                'decision' => 'rejected',
                'text'     => "Din anmodning om aktindsigt afslås. Det materiale, du ønsker aktindsigt i, indeholder personfølsomme oplysninger, som er undtaget fra aktindsigt, jf. offentlighedslovens §30.",
            ],
        ];
    }
}
