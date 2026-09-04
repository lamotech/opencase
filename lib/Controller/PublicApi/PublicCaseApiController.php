<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller\PublicApi;

use OCA\OpenCase\Db\CaseEntity;
use OCA\OpenCase\Db\CaseParticipant;
use OCA\OpenCase\Db\CaseParticipantMapper;
use OCA\OpenCase\Db\CaseWorker;
use OCA\OpenCase\Db\CaseWorkerMapper;
use OCA\OpenCase\Db\ClassificationSubjectMapper;
use OCA\OpenCase\Db\ContactTypeMapper;
use OCA\OpenCase\Db\EstateRoleMapper;
use OCA\OpenCase\Db\JournalNote;
use OCA\OpenCase\Db\JournalNoteMapper;
use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Db\ParticipantRoleMapper;
use OCA\OpenCase\Db\SensitivityMapper;
use OCA\OpenCase\Service\AuditService;
use OCA\OpenCase\Service\CaseExportService;
use OCA\OpenCase\Service\CaseService;
use OCA\OpenCase\Service\CprCvrLookupService;
use OCA\OpenCase\Service\DocumentService;
use OCA\OpenCase\Service\EstateLinkService;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Public data REST API for cases.
 *
 * GET  /index.php/apps/opencase/public/v1/api/cases/search?...
 *   — search cases by metadata (see search() for filters): CaseSearchResult
 *     { Total, Limit, Offset, Cases: [CaseType, ...] }
 * GET  /index.php/apps/opencase/public/v1/api/cases?uuid=...|case_number=...
 *   — the case itself: CaseType minus Documents and JournalNotes
 * GET  /index.php/apps/opencase/public/v1/api/cases/documents?uuid=...|case_number=...
 *   — the case's documents: DocumentsType
 * GET  /index.php/apps/opencase/public/v1/api/cases/journal-notes?uuid=...|case_number=...
 *   — the case's journal notes: JournalNotesType
 * POST /index.php/apps/opencase/public/v1/api/cases
 *   — create a new case. Body (JSON or XML — same field names either way,
 *     XML as a flat <Case> element matching the GET response shape):
 *       Title                     (required)
 *       OrgUuid                   (required) — see GET .../organisations
 *       ClassificationCodeUuid    (required) — see GET .../kle-numbers
 *       SensitivityUuid           (required) — see GET .../sensitivities
 *       ClassificationFacetUuid   (required) — see GET .../classification-facets
 *       ResponsibleUserId, InsightLevelId, CasetypeId, ParentCaseId (optional)
 *       Summary                   (optional) — case summary as rich-text HTML
 *     The lowercase/snake_case equivalents (organisation, classification_code,
 *     sensitivity, classification_facet_uuid, responsible_user_id, ...) are
 *     also accepted, where organisation/classification_code/sensitivity are
 *     matched by name/code/key instead of uuid.
 * PUT  /index.php/apps/opencase/public/v1/api/cases
 *   — update an existing case's metadata. Body: Uuid or CaseNumber
 *     (required, identifies the case) plus any subset of the Create fields
 *     above — omitted fields are left unchanged; ClassificationFacetUuid,
 *     InsightLevelId and Summary can be explicitly cleared by sending them
 *     empty.
 * POST /index.php/apps/opencase/public/v1/api/cases/participants
 *   — add one or more participants to a case. Body:
 *       Uuid or CaseNumber (required, one of)
 *       Participants: list of {ParticipantRoleId, CprCvr}
 *     CprCvr is looked up via Datafordeler — 10 digits resolves a citizen
 *     (CPR), 8 digits resolves a company (CVR); name/address/etc. come back
 *     from that lookup, not from the request. Each participant is resolved
 *     independently — one bad entry doesn't fail the others; the response
 *     lists a result (or Error) per submitted entry.
 * POST /index.php/apps/opencase/public/v1/api/cases/caseworkers
 *   — add one or more additional caseworkers to a case. Body:
 *       Uuid or CaseNumber (required, one of)
 *       Caseworkers: list of {UserId}
 *     Each caseworker is resolved independently — one bad entry (unknown
 *     user, already a caseworker) doesn't fail the others.
 * POST /index.php/apps/opencase/public/v1/api/cases/estates
 *   — link one or more estates to a case. Body:
 *       Uuid or CaseNumber (required, one of)
 *       Estates: list of {Bfenummer, RoleId?}
 *     Bfenummer is looked up via a live Datafordeler call, same as the
 *     internal estate search — the estate (and its aggregated
 *     estate/apartment/building data) is created locally on first use, or
 *     reused if a matching BFE-nummer + type already exists. RoleId
 *     defaults to 1 (Primær ejendom, opencase_estateroles) when omitted.
 *     Each estate is resolved independently — one bad entry doesn't fail
 *     the others.
 * POST /index.php/apps/opencase/public/v1/api/cases/documents
 *   — create a new document on a case. Body:
 *       Uuid or CaseNumber (required, one of)
 *       Title                 (required)
 *       DocumentCategoryId    (required) — see GET .../documentcategory
 *       DocumentType, InsightLevelId, DocumentDate, ReceivedDate, RegisteredDate (optional)
 *     Returns the created document as DocumentType (same shape as
 *     GET .../documents), with empty Files/Contacts/Notes/WorkflowHistory —
 *     this only creates the metadata record, not an attached file.
 *
 * See AbstractPublicDataApiController for auth and response-format handling.
 */
class PublicCaseApiController extends AbstractPublicDataApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private CaseService $caseService,
        private CaseExportService $caseExportService,
        private DocumentService $documentService,
        private OrganisationMapper $organisationMapper,
        private ClassificationSubjectMapper $classificationSubjectMapper,
        private SensitivityMapper $sensitivityMapper,
        private CaseParticipantMapper $caseParticipantMapper,
        private ParticipantRoleMapper $participantRoleMapper,
        private CaseWorkerMapper $caseWorkerMapper,
        private JournalNoteMapper $journalNoteMapper,
        private PermissionService $permissionService,
        private IUserManager $userManager,
        private AuditService $auditService,
        private CprCvrLookupService $cprCvrLookupService,
        private EstateLinkService $estateLinkService,
        private EstateRoleMapper $estateRoleMapper,
        IUserSession $userSession,
    ) {
        parent::__construct($appName, $request, $userSession);
    }

    /**
     * Create a new case.
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function create(): Response {
        $params = $this->requestBodyParams();

        $title                   = $this->bodyValue($params, 'Title', 'title');
        $summary                 = $this->bodyValue($params, 'Summary', 'summary');
        $orgUuid                 = $this->bodyValue($params, 'OrgUuid', 'org_uuid');
        $organisationName        = $this->bodyValue($params, 'organisation');
        $classificationCodeUuid  = $this->bodyValue($params, 'ClassificationCodeUuid', 'classification_code_uuid');
        $classificationCode      = $this->bodyValue($params, 'classification_code');
        $sensitivityUuid         = $this->bodyValue($params, 'SensitivityUuid', 'sensitivity_uuid');
        $sensitivityKey          = $this->bodyValue($params, 'sensitivity');
        $classificationFacetUuid = $this->bodyValue($params, 'ClassificationFacetUuid', 'classification_facet_uuid');
        $responsibleUserId       = $this->bodyValue($params, 'ResponsibleUserId', 'responsible_user_id');
        $insightLevelIdRaw       = $this->bodyValue($params, 'InsightLevelId', 'insight_level_id');
        $casetypeIdRaw           = $this->bodyValue($params, 'CasetypeId', 'casetype_id');
        $parentCaseIdRaw         = $this->bodyValue($params, 'ParentCaseId', 'parent_case_id');

        if (empty($title)) {
            return $this->errorResponse("Field 'Title' is required", Http::STATUS_BAD_REQUEST);
        }
        if (empty($classificationFacetUuid)) {
            return $this->errorResponse("Field 'ClassificationFacetUuid' is required", Http::STATUS_BAD_REQUEST);
        }

        // Organisation: resolve OrgUuid to its name, or take the name directly.
        if (!empty($orgUuid)) {
            $org = $this->organisationMapper->findByUuid($orgUuid);
            if ($org === null) {
                return $this->errorResponse("No organisation found for OrgUuid '{$orgUuid}'", Http::STATUS_BAD_REQUEST);
            }
            $organisationName = $org->getOrgName();
        } elseif (empty($organisationName)) {
            return $this->errorResponse("Field 'OrgUuid' (or 'organisation') is required", Http::STATUS_BAD_REQUEST);
        }

        // Classification: resolve ClassificationCodeUuid to its code, or take the code directly.
        if (!empty($classificationCodeUuid)) {
            $classSubject = $this->classificationSubjectMapper->findByUuid($classificationCodeUuid);
            if ($classSubject === null) {
                return $this->errorResponse("No KLE number found for ClassificationCodeUuid '{$classificationCodeUuid}'", Http::STATUS_BAD_REQUEST);
            }
            $classificationCode = $classSubject->getCode();
        } elseif (empty($classificationCode)) {
            return $this->errorResponse("Field 'ClassificationCodeUuid' (or 'classification_code') is required", Http::STATUS_BAD_REQUEST);
        }

        // Sensitivity: resolve SensitivityUuid to its key, or take the key directly.
        if (!empty($sensitivityUuid)) {
            $sensitivity = $this->sensitivityMapper->findByUuid($sensitivityUuid);
            if ($sensitivity === null) {
                return $this->errorResponse("No sensitivity found for SensitivityUuid '{$sensitivityUuid}'", Http::STATUS_BAD_REQUEST);
            }
            $sensitivityKey = $sensitivity->getKey();
        } elseif (empty($sensitivityKey)) {
            return $this->errorResponse("Field 'SensitivityUuid' (or 'sensitivity') is required", Http::STATUS_BAD_REQUEST);
        }

        $userId = $this->actingUserId();
        if ($userId === null) {
            return $this->errorResponse('No acting user resolved', Http::STATUS_FORBIDDEN);
        }

        try {
            $entity = $this->caseService->create(
                $title,
                $organisationName,
                $classificationCode,
                $sensitivityKey,
                $userId,
                !empty($responsibleUserId) ? $responsibleUserId : null,
                $insightLevelIdRaw !== null ? (int)$insightLevelIdRaw : null,
                $classificationFacetUuid,
                $parentCaseIdRaw !== null ? (int)$parentCaseIdRaw : null,
                !empty($casetypeIdRaw) ? (int)$casetypeIdRaw : null,
                !empty($summary) ? $summary : null,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), Http::STATUS_BAD_REQUEST);
        } catch (NotFoundException $e) {
            return $this->errorResponse($e->getMessage(), Http::STATUS_FORBIDDEN);
        }

        $fields       = $this->caseExportService->caseFields($entity, $entity->getExportedAt());
        $participants = $this->caseExportService->participantFields($entity->getId());
        $caseworkers  = $this->caseExportService->caseworkerFields($entity->getId());
        $estates      = $this->caseExportService->estateFields($entity->getId());

        $payload = $fields + [
            'Participants' => $participants,
            'Caseworkers'  => $caseworkers,
            'Estates'      => $estates,
        ];

        return $this->respond('Case', $payload, Http::STATUS_CREATED);
    }

    /**
     * Update an existing case's metadata. Only fields present in the body
     * are changed.
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function update(): Response {
        $params = $this->requestBodyParams();

        $uuid       = $this->bodyValue($params, 'Uuid', 'uuid');
        $caseNumber = $this->bodyValue($params, 'CaseNumber', 'case_number');

        try {
            $caseEntity = $this->resolveCase($uuid, $caseNumber);
        } catch (PublicCaseApiError $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatusCode());
        }

        $title = $this->bodyValue($params, 'Title', 'title');

        $orgUuid          = $this->bodyValue($params, 'OrgUuid', 'org_uuid');
        $organisationName = $this->bodyValue($params, 'organisation');
        if (!empty($orgUuid)) {
            $org = $this->organisationMapper->findByUuid($orgUuid);
            if ($org === null) {
                return $this->errorResponse("No organisation found for OrgUuid '{$orgUuid}'", Http::STATUS_BAD_REQUEST);
            }
            $organisationName = $org->getOrgName();
        }

        $classificationCodeUuid = $this->bodyValue($params, 'ClassificationCodeUuid', 'classification_code_uuid');
        $classificationCode     = $this->bodyValue($params, 'classification_code');
        if (!empty($classificationCodeUuid)) {
            $classSubject = $this->classificationSubjectMapper->findByUuid($classificationCodeUuid);
            if ($classSubject === null) {
                return $this->errorResponse("No KLE number found for ClassificationCodeUuid '{$classificationCodeUuid}'", Http::STATUS_BAD_REQUEST);
            }
            $classificationCode = $classSubject->getCode();
        }

        $sensitivityUuid = $this->bodyValue($params, 'SensitivityUuid', 'sensitivity_uuid');
        $sensitivityKey  = $this->bodyValue($params, 'sensitivity');
        if (!empty($sensitivityUuid)) {
            $sensitivity = $this->sensitivityMapper->findByUuid($sensitivityUuid);
            if ($sensitivity === null) {
                return $this->errorResponse("No sensitivity found for SensitivityUuid '{$sensitivityUuid}'", Http::STATUS_BAD_REQUEST);
            }
            $sensitivityKey = $sensitivity->getKey();
        }

        $responsibleUserId = $this->bodyValue($params, 'ResponsibleUserId', 'responsible_user_id');

        $insightLevelProvided = $this->bodyKeyProvided($params, 'InsightLevelId', 'insight_level_id');
        $insightLevelRaw      = $this->bodyValue($params, 'InsightLevelId', 'insight_level_id');
        $insightLevelId       = $insightLevelRaw !== null ? (int)$insightLevelRaw : null;

        $facetUuidProvided = $this->bodyKeyProvided($params, 'ClassificationFacetUuid', 'classification_facet_uuid');
        $facetUuid         = $this->bodyValue($params, 'ClassificationFacetUuid', 'classification_facet_uuid');

        $summaryProvided = $this->bodyKeyProvided($params, 'Summary', 'summary');
        $summaryRaw      = $this->bodyValue($params, 'Summary', 'summary');
        $summary         = ($summaryRaw !== null && $summaryRaw !== '') ? $summaryRaw : null;

        $userId = $this->actingUserId();
        if ($userId === null) {
            return $this->errorResponse('No acting user resolved', Http::STATUS_FORBIDDEN);
        }

        try {
            $entity = $this->caseService->update(
                $caseEntity->getId(),
                $title,
                $organisationName,
                $classificationCode,
                $sensitivityKey,
                $userId,
                $responsibleUserId,
                $insightLevelId,
                $insightLevelProvided,
                $facetUuid,
                $facetUuidProvided,
                $summary,
                $summaryProvided,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), Http::STATUS_BAD_REQUEST);
        } catch (NotFoundException $e) {
            return $this->errorResponse($e->getMessage(), Http::STATUS_FORBIDDEN);
        }

        $fields       = $this->caseExportService->caseFields($entity, $entity->getExportedAt());
        $participants = $this->caseExportService->participantFields($entity->getId());
        $caseworkers  = $this->caseExportService->caseworkerFields($entity->getId());
        $estates      = $this->caseExportService->estateFields($entity->getId());

        $payload = $fields + [
            'Participants' => $participants,
            'Caseworkers'  => $caseworkers,
            'Estates'      => $estates,
        ];

        return $this->respond('Case', $payload);
    }

    /**
     * Add one or more participants to a case, each resolved independently
     * via Datafordeler (CPR → citizen, CVR → company).
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function addParticipants(): Response {
        ['fields' => $fields, 'items' => $participantsInput] = $this->requestBodyWithList('Participants', 'Participant');

        $uuid       = $this->bodyValue($fields, 'Uuid', 'uuid');
        $caseNumber = $this->bodyValue($fields, 'CaseNumber', 'case_number');

        try {
            $caseEntity = $this->resolveCase($uuid, $caseNumber);
        } catch (PublicCaseApiError $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatusCode());
        }

        $userId = $this->actingUserId();
        if ($userId === null) {
            return $this->errorResponse('No acting user resolved', Http::STATUS_FORBIDDEN);
        }

        if (!$this->caseService->canWriteCase($caseEntity->getId(), $userId)) {
            return $this->errorResponse('User does not have write access to this case', Http::STATUS_FORBIDDEN);
        }

        if (empty($participantsInput)) {
            return $this->errorResponse('At least one participant is required', Http::STATUS_BAD_REQUEST);
        }

        $roleNames = $this->participantRoleMapper->getNameMap('da');
        if (empty($roleNames)) {
            $roleNames = $this->participantRoleMapper->getNameMap('en');
        }

        $results      = [];
        $anySucceeded = false;

        foreach ($participantsInput as $item) {
            $result = $this->addOneParticipant($caseEntity->getId(), $userId, $roleNames, $item);
            if (!isset($result['Error'])) {
                $anySucceeded = true;
            }
            $results[] = $result;
        }

        $status = $anySucceeded ? Http::STATUS_CREATED : Http::STATUS_BAD_REQUEST;

        return $this->respondList('Participants', 'Participant', $results, $status);
    }

    /** @param array<string, string> $roleNames */
    private function addOneParticipant(int $caseId, string $userId, array $roleNames, array $item): array {
        $roleIdRaw = $this->bodyValue($item, 'ParticipantRoleId', 'participant_role_id');
        $cprCvrRaw = $this->bodyValue($item, 'CprCvr', 'cpr_cvr');

        if ($roleIdRaw === null || $cprCvrRaw === null) {
            return ['CprCvr' => $cprCvrRaw, 'Error' => "'participant_role_id' and 'cpr_cvr' are both required"];
        }

        $participantRoleId = (int)$roleIdRaw;
        $digits             = preg_replace('/\D/', '', $cprCvrRaw) ?? '';

        try {
            if (strlen($digits) === 10) {
                $lookup      = $this->cprCvrLookupService->lookupCitizen($digits);
                $type        = 'citizen';
                $contactType = ContactTypeMapper::CITIZEN;
            } elseif (strlen($digits) === 8) {
                $lookup      = $this->cprCvrLookupService->lookupCompany($digits);
                $type        = 'company';
                $contactType = ContactTypeMapper::COMPANY;
            } else {
                return ['CprCvr' => $cprCvrRaw, 'Error' => "'cpr_cvr' must be 10 digits (CPR) or 8 digits (CVR)"];
            }
        } catch (\Throwable $e) {
            return ['CprCvr' => $cprCvrRaw, 'Error' => $e->getMessage()];
        }

        if ($lookup === null) {
            return ['CprCvr' => $cprCvrRaw, 'Error' => "No {$type} found for {$digits}"];
        }

        if ($this->caseParticipantMapper->existsByCaseRoleAndCvr($caseId, $participantRoleId, $digits, $lookup['pnumber'])) {
            return ['CprCvr' => $cprCvrRaw, 'Error' => 'A participant with this role and CPR/CVR already exists on this case'];
        }

        $entity = new CaseParticipant();
        $entity->setCaseId($caseId);
        $entity->setParticipantroleId($participantRoleId);
        $entity->setContactType($contactType);
        $entity->setCprCvr($digits);
        $entity->setName($lookup['name']);
        $entity->setStreetname($lookup['streetname']);
        $entity->setHousenumber($lookup['housenumber']);
        $entity->setFloor($lookup['floor']);
        $entity->setDoor($lookup['door']);
        $entity->setZipcode($lookup['zipcode']);
        $entity->setZipdistrict($lookup['zipdistrict']);
        $entity->setPhone($lookup['phone']);
        $entity->setEmail($lookup['email']);
        $entity->setHasAddressProtection($lookup['has_address_protection']);
        $entity->setPnumber($lookup['pnumber']);

        $saved = $this->caseParticipantMapper->insert($entity);

        $this->auditService->logParticipantAdded(
            $caseId,
            $userId,
            $roleNames[$participantRoleId] ?? '',
            $saved->getName() ?? '',
            $saved->getCprCvr() ?? '',
        );

        return [
            'Id'                   => $saved->getId(),
            'RoleId'               => $saved->getParticipantroleId(),
            'RoleName'             => $roleNames[$participantRoleId] ?? '',
            'ContactType'          => $saved->getContactType(),
            'CprCvr'               => $saved->getCprCvr(),
            'Name'                 => $saved->getName(),
            'Streetname'           => $saved->getStreetname(),
            'Housenumber'          => $saved->getHousenumber(),
            'Floor'                => $saved->getFloor(),
            'Door'                 => $saved->getDoor(),
            'Zipcode'              => $saved->getZipcode(),
            'Zipdistrict'          => $saved->getZipdistrict(),
            'Phone'                => $saved->getPhone(),
            'Email'                => $saved->getEmail(),
            'HasAddressProtection' => $saved->getHasAddressProtection() ? 'true' : 'false',
            'Pnumber'              => $saved->getPnumber(),
        ];
    }

    /**
     * Add one or more additional caseworkers to a case.
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function addCaseworkers(): Response {
        ['fields' => $fields, 'items' => $caseworkersInput] = $this->requestBodyWithList('Caseworkers', 'Caseworker');

        $uuid       = $this->bodyValue($fields, 'Uuid', 'uuid');
        $caseNumber = $this->bodyValue($fields, 'CaseNumber', 'case_number');

        try {
            $caseEntity = $this->resolveCase($uuid, $caseNumber);
        } catch (PublicCaseApiError $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatusCode());
        }

        $userId = $this->actingUserId();
        if ($userId === null) {
            return $this->errorResponse('No acting user resolved', Http::STATUS_FORBIDDEN);
        }

        if (!$this->caseService->canWriteCase($caseEntity->getId(), $userId)) {
            return $this->errorResponse('User does not have write access to this case', Http::STATUS_FORBIDDEN);
        }

        if (empty($caseworkersInput)) {
            return $this->errorResponse('At least one caseworker is required', Http::STATUS_BAD_REQUEST);
        }

        $results      = [];
        $anySucceeded = false;

        foreach ($caseworkersInput as $item) {
            $result = $this->addOneCaseworker($caseEntity, $userId, $item);
            if (!isset($result['Error'])) {
                $anySucceeded = true;
            }
            $results[] = $result;
        }

        $status = $anySucceeded ? Http::STATUS_CREATED : Http::STATUS_BAD_REQUEST;

        return $this->respondList('Caseworkers', 'Caseworker', $results, $status);
    }

    private function addOneCaseworker(CaseEntity $caseEntity, string $grantedBy, array $item): array {
        $targetUserId = $this->bodyValue($item, 'UserId', 'user_id');
        if (empty($targetUserId)) {
            return ['Error' => "'user_id' is required"];
        }

        $user = $this->userManager->get($targetUserId);
        if ($user === null) {
            return ['UserId' => $targetUserId, 'Error' => 'User not found'];
        }

        $caseId = $caseEntity->getId();

        if ($caseEntity->getResponsibleUserId() === $targetUserId) {
            return ['UserId' => $targetUserId, 'Error' => 'User is already the responsible caseworker'];
        }

        if ($this->caseWorkerMapper->existsByCaseAndUser($caseId, $targetUserId)) {
            return ['UserId' => $targetUserId, 'Error' => 'User is already a caseworker on this case'];
        }

        // Snapshot existing access so it can be restored if the caseworker is later removed
        $existingGrant = $this->permissionService->findGrant($caseId, $targetUserId);
        $priorCanWrite = $existingGrant !== null ? ($existingGrant->getCanWrite() ? 1 : 0) : null;

        $entity = new CaseWorker();
        $entity->setCaseId($caseId);
        $entity->setUserId($targetUserId);
        $entity->setPriorCanWrite($priorCanWrite);
        $this->caseWorkerMapper->insert($entity);

        // Grant write access (no-op if they already have write)
        if ($existingGrant === null || !$existingGrant->getCanWrite()) {
            $this->permissionService->grantUserAccess($caseId, $targetUserId, true, $grantedBy);
        }

        $this->auditService->logCaseworkerAdded($caseId, $grantedBy, $targetUserId, $user->getDisplayName());

        return [
            'UserId'        => $targetUserId,
            'DisplayName'   => $user->getDisplayName(),
            'PriorCanWrite' => $priorCanWrite,
        ];
    }

    /**
     * Link one or more estates to a case, each resolved independently by
     * BFE-nummer via a live Datafordeler lookup (find-or-create locally).
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function addEstates(): Response {
        ['fields' => $fields, 'items' => $estatesInput] = $this->requestBodyWithList('Estates', 'Estate');

        $uuid       = $this->bodyValue($fields, 'Uuid', 'uuid');
        $caseNumber = $this->bodyValue($fields, 'CaseNumber', 'case_number');

        try {
            $caseEntity = $this->resolveCase($uuid, $caseNumber);
        } catch (PublicCaseApiError $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatusCode());
        }

        $userId = $this->actingUserId();
        if ($userId === null) {
            return $this->errorResponse('No acting user resolved', Http::STATUS_FORBIDDEN);
        }

        if (!$this->caseService->canWriteCase($caseEntity->getId(), $userId)) {
            return $this->errorResponse('User does not have write access to this case', Http::STATUS_FORBIDDEN);
        }

        if (empty($estatesInput)) {
            return $this->errorResponse('At least one estate is required', Http::STATUS_BAD_REQUEST);
        }

        $roleNames = $this->estateRoleMapper->getNameMap('da');
        if (empty($roleNames)) {
            $roleNames = $this->estateRoleMapper->getNameMap('en');
        }

        $results      = [];
        $anySucceeded = false;

        foreach ($estatesInput as $item) {
            $result = $this->addOneEstate($caseEntity->getId(), $userId, $roleNames, $item);
            if (!isset($result['Error'])) {
                $anySucceeded = true;
            }
            $results[] = $result;
        }

        $status = $anySucceeded ? Http::STATUS_CREATED : Http::STATUS_BAD_REQUEST;

        return $this->respondList('Estates', 'Estate', $results, $status);
    }

    /** @param array<int, string> $roleNames */
    private function addOneEstate(int $caseId, string $userId, array $roleNames, array $item): array {
        $bfenummerRaw = $this->bodyValue($item, 'Bfenummer', 'bfenummer');
        if (empty($bfenummerRaw)) {
            return ['Error' => "'bfenummer' is required"];
        }

        $bfenummer = preg_replace('/\D/', '', (string)$bfenummerRaw) ?? '';
        if ($bfenummer === '') {
            return ['Bfenummer' => $bfenummerRaw, 'Error' => "'bfenummer' must be numeric"];
        }

        $roleIdRaw = $this->bodyValue($item, 'RoleId', 'role_id');
        $roleId    = $roleIdRaw !== null && $roleIdRaw !== '' ? (int)$roleIdRaw : 1; // default: Primær ejendom

        try {
            $result = $this->estateLinkService->linkByBfenummer($caseId, $bfenummer, $roleId);
        } catch (\Throwable $e) {
            return ['Bfenummer' => $bfenummer, 'Error' => $e->getMessage()];
        }

        if ($result === null) {
            return ['Bfenummer' => $bfenummer, 'Error' => "No estate found for BFE {$bfenummer}"];
        }

        $this->auditService->logEstateAdded($caseId, $userId, $roleNames[$roleId] ?? '', $result['type'], $result['bfenummer']);

        return [
            'EstateId'        => (string)$result['estate_id'],
            'RoleId'          => (string)$roleId,
            'RoleName'        => $roleNames[$roleId] ?? '',
            'Type'            => $result['type'],
            'Bfenummer'       => $result['bfenummer'] !== null ? (string)$result['bfenummer'] : null,
            'LocationAddress' => $result['location_address'],
        ];
    }

    /**
     * Search cases by metadata (query parameters, all optional except at
     * least one filter is recommended): search, organisation, year,
     * status_id, classification_code, sensitivity_key,
     * classification_facet_uuid, insight_level_id, responsible_user_id,
     * casetype_id, limit (default 50, max 1000), offset.
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function search(): Response {
        $userId = $this->actingUserId();
        if ($userId === null) {
            return $this->errorResponse('No acting user resolved', Http::STATUS_FORBIDDEN);
        }

        $search                  = $this->request->getParam('search') ?: null;
        $organisation            = $this->request->getParam('organisation') ?: null;
        $year                    = $this->request->getParam('year') ?: null;
        $statusIdRaw             = $this->request->getParam('status_id');
        $statusId                = ($statusIdRaw !== null && $statusIdRaw !== '') ? (int)$statusIdRaw : null;
        $classificationCode      = $this->request->getParam('classification_code') ?: null;
        $sensitivityKey          = $this->request->getParam('sensitivity_key') ?: null;
        $classificationFacetUuid = $this->request->getParam('classification_facet_uuid') ?: null;
        $insightLevelIdRaw       = $this->request->getParam('insight_level_id');
        $insightLevelId          = ($insightLevelIdRaw !== null && $insightLevelIdRaw !== '') ? (int)$insightLevelIdRaw : null;
        $responsibleUserId       = $this->request->getParam('responsible_user_id') ?: null;
        $casetypeIdRaw           = $this->request->getParam('casetype_id');
        $casetypeId              = ($casetypeIdRaw !== null && $casetypeIdRaw !== '') ? (int)$casetypeIdRaw : null;
        $limit                   = max(1, min((int)$this->request->getParam('limit', '50'), 1000));
        $offset                  = max(0, (int)$this->request->getParam('offset', '0'));

        $result = $this->caseService->list(
            $userId,
            $organisation,
            $year,
            $statusId,
            $search,
            $limit,
            $offset,
            $classificationCode,
            $sensitivityKey,
            $classificationFacetUuid,
            $insightLevelId,
            $responsibleUserId,
            $casetypeId,
        );

        $cases = array_map(
            fn ($entity) => $this->caseExportService->caseFields($entity, $entity->getExportedAt()),
            $result['cases'],
        );

        $payload = [
            'Total'  => (string)$result['total'],
            'Limit'  => (string)$limit,
            'Offset' => (string)$offset,
            'Cases'  => $cases,
        ];

        return $this->respond('CaseSearchResult', $payload);
    }

    /**
     * Retrieve a single case by uuid or case_number (exactly one required).
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(?string $uuid = null, ?string $case_number = null): Response {
        try {
            $entity = $this->resolveCase($uuid, $case_number);
        } catch (PublicCaseApiError $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatusCode());
        }

        $fields       = $this->caseExportService->caseFields($entity, $entity->getExportedAt());
        $participants = $this->caseExportService->participantFields($entity->getId());
        $caseworkers  = $this->caseExportService->caseworkerFields($entity->getId());
        $estates      = $this->caseExportService->estateFields($entity->getId());

        $payload = $fields + [
            'Participants' => $participants,
            'Caseworkers'  => $caseworkers,
            'Estates'      => $estates,
        ];

        return $this->respond('Case', $payload);
    }

    /**
     * Retrieve a case's documents (DocumentsType) by uuid or case_number.
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function documents(?string $uuid = null, ?string $case_number = null): Response {
        try {
            $entity = $this->resolveCase($uuid, $case_number);
        } catch (PublicCaseApiError $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatusCode());
        }

        $documents = $this->caseExportService->documentFields($entity->getId());

        return $this->respondList('Documents', 'Document', $documents);
    }

    /**
     * Create a new document on a case.
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function createDocument(): Response {
        $params = $this->requestBodyParams();

        $uuid       = $this->bodyValue($params, 'Uuid', 'uuid');
        $caseNumber = $this->bodyValue($params, 'CaseNumber', 'case_number');

        try {
            $caseEntity = $this->resolveCase($uuid, $caseNumber);
        } catch (PublicCaseApiError $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatusCode());
        }

        $title                  = $this->bodyValue($params, 'Title', 'title');
        $documentType           = $this->bodyValue($params, 'DocumentType', 'document_type');
        $documentCategoryIdRaw  = $this->bodyValue($params, 'DocumentCategoryId', 'document_category_id');
        $insightLevelIdRaw      = $this->bodyValue($params, 'InsightLevelId', 'insight_level_id');
        $documentDate           = $this->bodyValue($params, 'DocumentDate', 'document_date');
        $receivedDate           = $this->bodyValue($params, 'ReceivedDate', 'received_date');
        $registeredDate         = $this->bodyValue($params, 'RegisteredDate', 'registered_date');

        if (empty($title)) {
            return $this->errorResponse("Field 'Title' is required", Http::STATUS_BAD_REQUEST);
        }
        if ($documentCategoryIdRaw === null) {
            return $this->errorResponse("Field 'DocumentCategoryId' is required", Http::STATUS_BAD_REQUEST);
        }

        $userId = $this->actingUserId();
        if ($userId === null) {
            return $this->errorResponse('No acting user resolved', Http::STATUS_FORBIDDEN);
        }

        try {
            $entity = $this->documentService->create(
                $caseEntity->getId(),
                $title,
                $documentType,
                $userId,
                $insightLevelIdRaw !== null ? (int)$insightLevelIdRaw : null,
                $documentDate,
                $receivedDate,
                $registeredDate,
                (int)$documentCategoryIdRaw,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), Http::STATUS_BAD_REQUEST);
        } catch (NotFoundException $e) {
            return $this->errorResponse($e->getMessage(), Http::STATUS_FORBIDDEN);
        }

        $fields = $this->caseExportService->singleDocumentFields($entity);

        return $this->respond('Document', $fields, Http::STATUS_CREATED);
    }

    /**
     * Retrieve a case's journal notes (JournalNotesType) by uuid or case_number.
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function journalNotes(?string $uuid = null, ?string $case_number = null): Response {
        try {
            $entity = $this->resolveCase($uuid, $case_number);
        } catch (PublicCaseApiError $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatusCode());
        }

        $journalNotes = $this->caseExportService->journalNoteFields($entity->getId());

        return $this->respondList('JournalNotes', 'JournalNote', $journalNotes);
    }

    /**
     * Add a journal note to a case.
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function addJournalNote(): Response {
        $params = $this->requestBodyParams();

        $uuid       = $this->bodyValue($params, 'Uuid', 'uuid');
        $caseNumber = $this->bodyValue($params, 'CaseNumber', 'case_number');

        try {
            $caseEntity = $this->resolveCase($uuid, $caseNumber);
        } catch (PublicCaseApiError $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatusCode());
        }

        $title = $this->bodyValue($params, 'Title', 'title');
        $text  = $this->bodyValue($params, 'Text', 'text');

        if (empty($title)) {
            return $this->errorResponse("Field 'Title' is required", Http::STATUS_BAD_REQUEST);
        }

        $userId = $this->actingUserId();
        if ($userId === null) {
            return $this->errorResponse('No acting user resolved', Http::STATUS_FORBIDDEN);
        }

        if (!$this->caseService->canWriteCase($caseEntity->getId(), $userId)) {
            return $this->errorResponse('User does not have write access to this case', Http::STATUS_FORBIDDEN);
        }

        $note = new JournalNote();
        $note->setCaseId($caseEntity->getId());
        $note->setTitle($title);
        $note->setText($text);
        $note->setIsLocked(0);
        $note->setCreatedBy($userId);
        $now = new \DateTime();
        $note->setCreatedAt($now);
        $note->setUpdatedAt($now);

        $note = $this->journalNoteMapper->insert($note);

        $this->auditService->logJournalNoteCreated($caseEntity->getId(), $userId, $note->getId(), $title);

        $fields = $this->caseExportService->journalNoteFieldsFromEntity($note);

        return $this->respond('JournalNote', $fields, Http::STATUS_CREATED);
    }

    /**
     * @throws PublicCaseApiError
     */
    private function resolveCase(?string $uuid, ?string $caseNumber): CaseEntity {
        if (empty($uuid) && empty($caseNumber)) {
            throw new PublicCaseApiError('uuid or case_number is required', Http::STATUS_BAD_REQUEST);
        }

        $userId = $this->actingUserId();
        if ($userId === null) {
            throw new PublicCaseApiError('No acting user resolved', Http::STATUS_FORBIDDEN);
        }

        try {
            return !empty($uuid)
                ? $this->caseService->getByUuid($uuid, $userId)
                : $this->caseService->getByCaseNumber($caseNumber, $userId);
        } catch (NotFoundException $e) {
            throw new PublicCaseApiError('Case not found', Http::STATUS_NOT_FOUND);
        }
    }
}
