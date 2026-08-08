<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\CaseParticipant;
use OCA\OpenCase\Db\CaseParticipantMapper;
use OCA\OpenCase\Db\ContactTypeMapper;
use OCA\OpenCase\Db\ParticipantRoleMapper;
use OCA\OpenCase\Service\AuditService;
use OCA\OpenCase\Service\PermissionService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\L10N\IFactory;

/**
 * REST API controller for case participants.
 *
 * Routes:
 *   GET    /api/v1/participant-roles                                 — List participant roles (localized)
 *   GET    /api/v1/cases/{caseId}/participants                       — List participants for a case
 *   POST   /api/v1/cases/{caseId}/participants                       — Add a participant to a case
 *   DELETE /api/v1/cases/{caseId}/participants/{participantId}       — Remove a participant
 */
class CaseParticipantController extends ApiController {

    use CaseAccessGuard;

    public function __construct(
        string $appName,
        IRequest $request,
        private CaseParticipantMapper $participantMapper,
        private ParticipantRoleMapper $roleMapper,
        private AuditService $auditService,
        private IFactory $l10nFactory,
        private IUserManager $userManager,
        private PermissionService $permissionService,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    private function getUserLanguage(): string {
        if ($this->userId === null) {
            return 'en';
        }
        $user = $this->userManager->get($this->userId);
        return $user ? $this->l10nFactory->getUserLanguage($user) : 'en';
    }

    private function resolveRoleNames(): array {
        $lang = $this->getUserLanguage();
        $names = $this->roleMapper->getNameMap($lang);
        return empty($names) ? $this->roleMapper->getNameMap('en') : $names;
    }

    private function serializeParticipant(CaseParticipant $p, array $roleNames): array {
        return [
            'id'                     => $p->getId(),
            'case_id'                => $p->getCaseId(),
            'participantrole_id'     => $p->getParticipantroleId(),
            'role_name'              => $roleNames[$p->getParticipantroleId()] ?? '',
            'contact_type'           => $p->getContactType(),
            'user_id'                => $p->getUserId(),
            'cpr_cvr'                => $p->getCprCvr(),
            'name'                   => $p->getName(),
            'streetname'             => $p->getStreetname(),
            'housenumber'            => $p->getHousenumber(),
            'floor'                  => $p->getFloor(),
            'door'                   => $p->getDoor(),
            'zipcode'                => $p->getZipcode(),
            'zipdistrict'            => $p->getZipdistrict(),
            'phone'                  => $p->getPhone(),
            'email'                  => $p->getEmail(),
            'updated_date'           => $p->getUpdatedDate()?->format('Y-m-d'),
            'has_address_protection' => (bool)$p->getHasAddressProtection(),
            'pnumber'                => $p->getPnumber(),
        ];
    }

    /**
     * List participant roles in the current user's language.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/participant-roles')]
    public function participantRoles(): DataResponse {
        $lang = $this->getUserLanguage();
        $roles = $this->roleMapper->findByLanguage($lang, true);
        if (empty($roles)) {
            $roles = $this->roleMapper->findByLanguage('en', true);
        }
        return new DataResponse(['roles' => $roles]);
    }

    /**
     * List all participants attached to a case.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/cases/{caseId}/participants')]
    public function indexByCase(int $caseId): DataResponse {
        if ($denied = $this->denyUnlessCaseRead($caseId)) {
            return $denied;
        }

        $roleNames    = $this->resolveRoleNames();
        $participants = $this->participantMapper->findByCase($caseId);

        return new DataResponse([
            'participants' => array_map(fn($p) => $this->serializeParticipant($p, $roleNames), $participants),
        ]);
    }

    /**
     * Add a participant to a case.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/cases/{caseId}/participants')]
    public function create(int $caseId): DataResponse {
        if ($denied = $this->denyUnlessCaseWrite($caseId)) {
            return $denied;
        }

        $params = $this->request->getParams();

        if (!isset($params['participantrole_id']) || $params['participantrole_id'] === '') {
            return new DataResponse(
                ['error' => "Field 'participantrole_id' is required"],
                Http::STATUS_BAD_REQUEST
            );
        }

        $participantroleId = (int)$params['participantrole_id'];
        $cprCvr            = $params['cpr_cvr'] ?? null;
        $userId            = isset($params['user_id']) && $params['user_id'] !== '' ? (string)$params['user_id'] : null;
        $pnumber           = isset($params['pnumber']) && $params['pnumber'] !== '' ? $params['pnumber'] : null;

        if ($cprCvr !== null && $cprCvr !== '') {
            if ($this->participantMapper->existsByCaseRoleAndCvr($caseId, $participantroleId, $cprCvr, $pnumber)) {
                return new DataResponse(
                    ['error' => 'A participant with this role and CVR already exists on this case'],
                    Http::STATUS_CONFLICT
                );
            }
        }

        $entity = new CaseParticipant();
        $entity->setCaseId($caseId);
        $entity->setParticipantroleId($participantroleId);
        $entity->setContactType(ContactTypeMapper::deriveFromCprCvr($cprCvr));
        $entity->setUserId($userId);
        $entity->setCprCvr($cprCvr);
        $entity->setName($params['name'] ?? null);
        $entity->setStreetname($params['streetname'] ?? null);
        $entity->setHousenumber($params['housenumber'] ?? null);
        $entity->setFloor($params['floor'] ?? null);
        $entity->setDoor($params['door'] ?? null);
        $entity->setZipcode($params['zipcode'] ?? null);
        $entity->setZipdistrict($params['zipdistrict'] ?? null);
        $entity->setPhone($params['phone'] ?? null);
        $entity->setEmail($params['email'] ?? null);
        $entity->setHasAddressProtection(!empty($params['has_address_protection']));
        $entity->setPnumber($params['pnumber'] ?? null);

        if (!empty($params['updated_date'])) {
            $entity->setUpdatedDate(new \DateTime($params['updated_date']));
        }

        $saved     = $this->participantMapper->insert($entity);
        $roleNames = $this->resolveRoleNames();

        $this->auditService->logParticipantAdded(
            $caseId,
            $this->userId ?? '',
            $roleNames[$participantroleId] ?? '',
            $saved->getName() ?? '',
            $saved->getCprCvr() ?? '',
        );

        return new DataResponse(
            ['participant' => $this->serializeParticipant($saved, $roleNames)],
            Http::STATUS_CREATED
        );
    }

    /**
     * Remove a participant from a case.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/cases/{caseId}/participants/{participantId}')]
    public function destroy(int $caseId, int $participantId): DataResponse {
        if ($denied = $this->denyUnlessCaseWrite($caseId)) {
            return $denied;
        }

        $participant = $this->participantMapper->findById($participantId);

        if ($participant === null || $participant->getCaseId() !== $caseId) {
            return new DataResponse(['error' => 'Participant not found'], Http::STATUS_NOT_FOUND);
        }

        $roleNames = $this->resolveRoleNames();
        $roleName  = $roleNames[$participant->getParticipantroleId()] ?? '';
        $name      = $participant->getName() ?? '';
        $cvr       = $participant->getCprCvr() ?? '';

        $this->participantMapper->delete($participant);

        $this->auditService->logParticipantDeleted(
            $caseId,
            $this->userId ?? '',
            $roleName,
            $name,
            $cvr,
        );

        return new DataResponse([], Http::STATUS_NO_CONTENT);
    }
}
