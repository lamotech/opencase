<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\AccessProfileMapper;
use OCA\OpenCase\Db\CaseEntity;
use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\CaseUserEntity;
use OCA\OpenCase\Db\ClassificationSubjectMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\FileMapper;
use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Db\SensitivityMapper;
use Psr\Log\LoggerInterface;
use OCA\OpenCase\Service\AuditService;
use OCA\OpenCase\Service\CaseNumberService;
use OCA\OpenCase\Service\FileService;
use OCA\OpenCase\Service\PrivilegeService;

/**
 * Business logic for case management.
 *
 * Sits between the controller and the DB layer, handling:
 *   - Case creation with access profile resolution
 *   - Status transitions
 *   - Cascade operations (e.g. closing a case)
 *   - Validation
 */
class CaseService {

    public function __construct(
        private CaseMapper $caseMapper,
        private DocumentMapper $documentMapper,
        private FileMapper $fileMapper,
        private AccessProfileMapper $accessProfileMapper,
        private OrganisationMapper $organisationMapper,
        private ClassificationSubjectMapper $classificationSubjectMapper,
        private SensitivityMapper $sensitivityMapper,
        private PermissionService $permissionService,
        private CaseNumberService $caseNumberService,
        private FileService $fileService,
        private AuditService $auditService,
        private LoggerInterface $logger,
        private PrivilegeService $privilegeService,
    ) {
    }

    /**
     * Create a new case.
     *
     * The case number is generated automatically from the administrator-defined
     * mask stored in opencase_config. Resolves (or creates) the access profile
     * for the given organisation/classification/sensitivity tuple.
     *
     * @throws \InvalidArgumentException on validation failure
     * @throws NotFoundException if user lacks permission
     */
    public function create(
        string $title,
        string $organisation,
        string $classificationCode,
        string $sensitivity,
        string $userId,
        ?string $responsibleUserId = null,
        ?int $insightLevelId = null,
        ?string $classificationFacetUuid = null,
        ?int $parentCaseId = null,
        ?int $casetypeId = null,
        ?string $summary = null,
    ): CaseEntity {
        // Validate
        if (trim($title) === '') {
            throw new \InvalidArgumentException('Case title is required');
        }

        // Resolve (or create) the organisation, classification subject, and sensitivity, then the access profile
        $org          = $this->organisationMapper->findOrCreateByName($organisation);
        $classSubject = $this->classificationSubjectMapper->findOrCreateByCode($classificationCode);
        $sens         = $this->sensitivityMapper->findOrCreateByKey($sensitivity);
        $profile      = $this->accessProfileMapper->findOrCreate(
            $org->getOrgUuid(),
            $classSubject->getUuid(),
            $sens->getUuid()
        );

        // Grant access to all users whose SAML privilege constraints cover this profile.
        // This is idempotent — if the profile already existed the inserts are silently skipped.
        $this->privilegeService->grantPrivilegedUsersToProfile(
            (int)$profile->getId(),
            $org->getOrgUuid(),
            $classSubject->getUuid(),
            $sens->getUuid()
        );

        // Verify user has write access to this profile. The responsible user
        // for the new case always has write access regardless of profile
        // grants — same rule as PermissionService::userHasWriteAccessToCase,
        // applied prospectively here since the case doesn't exist yet.
        $effectiveResponsibleUserId = $responsibleUserId ?? $userId;
        if (
            $effectiveResponsibleUserId !== $userId
            && $this->permissionService->getUserAccessLevel($userId, $profile->getId()) !== 'write'
        ) {
            throw new NotFoundException('User does not have write access to this access profile');
        }

        // Generate a unique, gap-free case number (atomic DB sequence)
        $caseNumber = $this->caseNumberService->generateCaseNumber();

        // Derive year from the generated case number or current date
        $year = $this->extractYear($caseNumber);

        $entity = new CaseEntity();
        $entity->setCaseNumber($caseNumber);
        $entity->setTitle($title);
        $entity->setAccessProfileId($profile->getId());
        $entity->setOrgUuid($org->getOrgUuid());
        $entity->setYear($year);
        $entity->setStatusId(1); // 1 = Open
        $entity->setCreatedAt(new \DateTime());
        $entity->setUpdatedAt(new \DateTime());
        $entity->setCreatedBy($userId);
        $entity->setResponsibleUserId($responsibleUserId ?? $userId);
        $entity->setInsightLevelId($insightLevelId);
        $entity->setClassificationFacetUuid($classificationFacetUuid);
        $entity->setUuid(self::generateUuid());
        $entity->setParentCaseId($parentCaseId);
        $entity->setSummary($summary);
        if ($casetypeId !== null) {
            $entity->setCasetypeId($casetypeId);
        }

        $entity = $this->caseMapper->insert($entity);

        // Register the case directory in the virtual filesystem immediately so
        // it is visible in the file browser before any files are uploaded.
        $this->fileService->ensureCaseFilecacheEntry($entity);

        $this->auditService->logCaseCreated($entity->getId(), $userId);

        return $entity;
    }

    /**
     * Update a case's metadata.
     *
     * @throws NotFoundException
     */
    public function update(
        int $caseId,
        ?string $title,
        ?string $organisation,
        ?string $classificationCode,
        ?string $sensitivity,
        string $userId,
        ?string $responsibleUserId = null,
        ?int $insightLevelId = null,
        bool $insightLevelProvided = false,
        ?string $classificationFacetUuid = null,
        bool $facetUuidProvided = false,
        ?string $summary = null,
        bool $summaryProvided = false,
    ): CaseEntity {
        $entity = $this->caseMapper->findById($caseId);
        if ($entity === null) {
            throw new NotFoundException("Case {$caseId} not found");
        }

        // Verify write access
        if (!$this->permissionService->userHasWriteAccessToCase($userId, $caseId)) {
            throw new NotFoundException('Access denied');
        }

        $changes = [];

        if ($title !== null) {
            if ($title !== $entity->getTitle()) {
                $changes['title'] = ['from' => $entity->getTitle(), 'to' => $title];
            }
            $entity->setTitle($title);
        }

        // If any part of the access profile tuple changed, resolve new profile
        if ($organisation !== null || $classificationCode !== null || $sensitivity !== null) {
            $currentProfile = $this->accessProfileMapper->findById($entity->getAccessProfileId());

            // Resolve current org name from the UUID stored on the profile
            $currentOrgUuid = $currentProfile->getOrgUuid();
            $currentOrg     = $this->organisationMapper->findByUuid($currentOrgUuid);
            $currentOrgName = $currentOrg?->getOrgName() ?? '';

            $newOrgName = $organisation ?? $currentOrgName;
            if ($organisation !== null && $organisation !== $currentOrgName) {
                $changes['organisation'] = ['from' => $currentOrgName, 'to' => $organisation];
            }

            // Resolve classification: if a new code is provided, look it up; otherwise keep current UUID
            if ($classificationCode !== null) {
                $currentClassSubject = $this->classificationSubjectMapper->findByUuid($currentProfile->getClassSubjectUuid());
                $oldCode             = $currentClassSubject?->getCode() ?? '';
                if ($classificationCode !== $oldCode) {
                    $changes['classification_code'] = ['from' => $oldCode, 'to' => $classificationCode];
                }
                $newClassSubject = $this->classificationSubjectMapper->findOrCreateByCode($classificationCode);
                $newClassUuid    = $newClassSubject->getUuid();
            } else {
                $newClassUuid = $currentProfile->getClassSubjectUuid();
            }

            // Resolve sensitivity: if a new key is provided, look it up; otherwise keep current UUID
            if ($sensitivity !== null) {
                $currentSens = $this->sensitivityMapper->findByUuid($currentProfile->getSensitivityUuid());
                $oldKey      = $currentSens?->getKey() ?? '';
                if ($sensitivity !== $oldKey) {
                    $changes['sensitivity'] = ['from' => $oldKey, 'to' => $sensitivity];
                }
                $newSens     = $this->sensitivityMapper->findOrCreateByKey($sensitivity);
                $newSensUuid = $newSens->getUuid();
            } else {
                $newSensUuid = $currentProfile->getSensitivityUuid();
            }

            $newOrg     = $this->organisationMapper->findOrCreateByName($newOrgName);
            $newProfile = $this->accessProfileMapper->findOrCreate(
                $newOrg->getOrgUuid(),
                $newClassUuid,
                $newSensUuid
            );
            $this->privilegeService->grantPrivilegedUsersToProfile(
                (int)$newProfile->getId(),
                $newOrg->getOrgUuid(),
                $newClassUuid,
                $newSensUuid
            );
            $entity->setAccessProfileId($newProfile->getId());
            $entity->setOrgUuid($newOrg->getOrgUuid());
        }

        if ($insightLevelProvided && $insightLevelId !== $entity->getInsightLevelId()) {
            $changes['insight_level_id'] = [
                'from' => $entity->getInsightLevelId(),
                'to'   => $insightLevelId,
            ];
            $entity->setInsightLevelId($insightLevelId);
        }

        if ($facetUuidProvided && $classificationFacetUuid !== $entity->getClassificationFacetUuid()) {
            $changes['classification_facet_uuid'] = [
                'from' => $entity->getClassificationFacetUuid(),
                'to'   => $classificationFacetUuid,
            ];
            $entity->setClassificationFacetUuid($classificationFacetUuid);
        }

        if ($summaryProvided && $summary !== $entity->getSummary()) {
            // The summary is rich-text HTML and can run long — record only that
            // it changed rather than two full copies in the audit log details.
            $changes['summary'] = ['changed' => true];
            $entity->setSummary($summary);
        }

        if ($responsibleUserId !== null && $responsibleUserId !== $entity->getResponsibleUserId()) {
            $changes['responsible_user_id'] = [
                'from' => $entity->getResponsibleUserId(),
                'to'   => $responsibleUserId,
            ];
            $entity->setResponsibleUserId($responsibleUserId);
        }

        $entity->setUpdatedAt(new \DateTime());

        $result = $this->caseMapper->update($entity);
        $this->auditService->logCaseMetadataChanged($caseId, $userId, $changes);

        return $result;
    }

    /**
     * Change case status.
     *
     * Valid status IDs: 1=Open, 2=Closed, 3=Archived.
     * Allowed transitions: 1→2, 2→1, 2→3, 3→2.
     *
     * @throws \InvalidArgumentException on invalid ID or disallowed transition
     * @throws NotFoundException if case not found or access denied
     */
    public function changeStatus(int $caseId, int $newStatusId, string $userId): CaseEntity {
        $validIds = [1, 2, 3];
        if (!in_array($newStatusId, $validIds)) {
            throw new \InvalidArgumentException("Invalid status ID: {$newStatusId}");
        }

        $entity = $this->caseMapper->findById($caseId);
        if ($entity === null) {
            throw new NotFoundException("Case {$caseId} not found");
        }

        if (!$this->permissionService->userHasWriteAccessToCase($userId, $caseId)) {
            throw new NotFoundException('Access denied');
        }

        // Validate status transition
        $allowed = match ($entity->getStatusId()) {
            1 => [2],       // Open → Closed
            2 => [1, 3],    // Closed → Open or Archived
            3 => [2],       // Archived → Closed
            default => [],
        };

        if (!in_array($newStatusId, $allowed)) {
            throw new \InvalidArgumentException(
                "Cannot transition from status {$entity->getStatusId()} to {$newStatusId}"
            );
        }

        $oldStatusId = $entity->getStatusId();
        $entity->setStatusId($newStatusId);
        $entity->setUpdatedAt(new \DateTime());

        $result = $this->caseMapper->update($entity);
        $this->auditService->logCaseStatusChanged($caseId, $userId, $oldStatusId, $newStatusId);

        return $result;
    }

    /**
     * Get a single case with permission check.
     *
     * @param bool $audit Whether to record a case_viewed audit/history entry.
     *                    Set to false when the case is fetched incidentally
     *                    (e.g. parent-case context on a document page) rather
     *                    than actually being viewed by the user.
     */
    public function get(int $caseId, string $userId, bool $audit = true): CaseEntity {
        $entity = $this->caseMapper->findById($caseId);
        if ($entity === null) {
            throw new NotFoundException("Case {$caseId} not found");
        }

        return $this->checkReadAccessAndAudit($entity, $userId, $audit);
    }

    /**
     * Get a single case by UUID with permission check.
     */
    public function getByUuid(string $uuid, string $userId): CaseEntity {
        $entity = $this->caseMapper->findByUuid($uuid);
        if ($entity === null) {
            throw new NotFoundException("Case with uuid {$uuid} not found");
        }

        return $this->checkReadAccessAndAudit($entity, $userId);
    }

    /**
     * Get a single case by case number with permission check.
     */
    public function getByCaseNumber(string $caseNumber, string $userId): CaseEntity {
        $entity = $this->caseMapper->findByCaseNumber($caseNumber);
        if ($entity === null) {
            throw new NotFoundException("Case with case number {$caseNumber} not found");
        }

        return $this->checkReadAccessAndAudit($entity, $userId);
    }

    private function checkReadAccessAndAudit(CaseEntity $entity, string $userId, bool $audit = true): CaseEntity {
        $caseId = $entity->getId();

        if (!$this->permissionService->userHasReadAccessToCase($userId, $caseId)) {
            throw new NotFoundException('Access denied');
        }

        if ($audit) {
            $this->auditService->logCaseViewed($caseId, $userId);
        }

        return $entity;
    }

    /**
     * List cases with access control, pagination and optional filters.
     *
     * @return array{cases: CaseEntity[], total: int}
     */
    public function list(
        string $userId,
        ?string $organisation = null,
        ?string $year = null,
        ?int $statusId = null,
        ?string $search = null,
        int $limit = 50,
        int $offset = 0,
        ?string $classificationCode = null,
        ?string $sensitivityKey = null,
        ?string $classificationFacetUuid = null,
        ?int $insightLevelId = null,
        ?string $responsibleUserId = null,
        ?int $casetypeId = null,
    ): array {
        // The mapper resolves every access path (responsible user, direct grant,
        // access profile) in SQL — no pre-resolved profile ID list needed.
        $hasAdvancedFilters = $classificationCode !== null || $sensitivityKey !== null
            || $classificationFacetUuid !== null || $insightLevelId !== null
            || $responsibleUserId !== null || $casetypeId !== null;

        // Fast text-only path — only when no other filters are active
        if ($search !== null && trim($search) !== ''
            && !$hasAdvancedFilters && $organisation === null && $year === null && $statusId === null
        ) {
            $cases = $this->caseMapper->searchByTitleOrNumber($search, $userId, $limit);
            return ['cases' => $cases, 'total' => count($cases)];
        }

        // Resolve org name → UUID for the filter (null means no filter)
        $orgUuid = null;
        if ($organisation !== null) {
            $orgEntity = $this->organisationMapper->findByName($organisation);
            if ($orgEntity === null) {
                return ['cases' => [], 'total' => 0];
            }
            $orgUuid = $orgEntity->getOrgUuid();
        }

        $total = $this->caseMapper->countWithFilters(
            $userId, $orgUuid, $year, $statusId,
            $search, $classificationFacetUuid, $insightLevelId, $responsibleUserId,
            $classificationCode, $sensitivityKey, $casetypeId,
        );
        $cases = $this->caseMapper->findWithFilters(
            $userId, $orgUuid, $year, $statusId, $limit, $offset,
            $search, $classificationFacetUuid, $insightLevelId, $responsibleUserId,
            $classificationCode, $sensitivityKey, $casetypeId,
        );
        return ['cases' => $cases, 'total' => $total];
    }

    /**
     * Get case statistics for the current user.
     *
     * @return array<string, array<string, int>>
     */
    public function getStats(string $userId): array {
        return $this->caseMapper->countByOrgAndYear($userId);
    }

    /**
     * Get distinct organisation names accessible to the current user,
     * derived from their access profiles (not from existing cases).
     *
     * @return string[]
     */
    public function getAccessibleOrganisations(string $userId): array {
        $profileIds = $this->permissionService->getAccessProfileIdsForUser($userId);
        return $this->permissionService->getAccessibleOrganisations($profileIds);
    }

    /**
     * @return list<array{org_uuid: string, org_name: string}>
     */
    public function getAccessibleOrganisationsWithUuid(string $userId): array {
        $profileIds = $this->permissionService->getAccessProfileIdsForUser($userId);
        return $this->permissionService->getAccessibleOrganisationsWithUuid($profileIds);
    }

    // ---------------------------------------------------------------
    // Direct access grant management
    // ---------------------------------------------------------------

    /**
     * List all grants for a case (including expired ones, for audit view).
     *
     * @return CaseUserEntity[]
     * @throws NotFoundException if case not found or access denied
     */
    public function listGrants(int $caseId, string $userId): array {
        if (!$this->permissionService->userHasReadAccessToCase($userId, $caseId)) {
            throw new NotFoundException('Access denied');
        }
        return $this->permissionService->getAllCaseGrants($caseId);
    }

    /**
     * Grant (or update) a user's direct access to a case.
     *
     * @throws NotFoundException if access denied
     */
    public function grantAccess(
        int $caseId,
        string $grantUserId,
        bool $canWrite,
        ?\DateTime $expiresAt,
        string $userId,
    ): CaseUserEntity {
        if (!$this->permissionService->userHasWriteAccessToCase($userId, $caseId)) {
            throw new NotFoundException('Access denied');
        }

        $grant = $this->permissionService->grantUserAccess($caseId, $grantUserId, $canWrite, $userId, $expiresAt);

        $this->auditService->logAccessGranted($caseId, $userId, $grantUserId, $canWrite, $expiresAt);

        return $grant;
    }

    /**
     * Revoke a user's direct access grant from a case.
     *
     * @throws NotFoundException if access denied
     */
    public function revokeAccess(int $caseId, string $grantUserId, string $userId): void {
        if (!$this->permissionService->userHasWriteAccessToCase($userId, $caseId)) {
            throw new NotFoundException('Access denied');
        }

        $this->permissionService->revokeUserAccess($caseId, $grantUserId);

        $this->auditService->logAccessRevoked($caseId, $userId, $grantUserId);
    }

    public function canWriteCase(int $caseId, string $userId): bool {
        return $this->permissionService->userHasWriteAccessToCase($userId, $caseId);
    }

    /**
     * Build a full hierarchy tree for a case.
     *
     * Walks up to the root ancestor, then recursively loads all descendants.
     * Access is checked only on the requested case; hierarchy nodes are
     * included regardless of access so the tree structure is always coherent.
     *
     * Returns null if the case is not part of any hierarchy (no parent, no children).
     *
     * @throws NotFoundException if the case is not found or user lacks access
     */
    public function getHierarchy(int $caseId, string $userId): ?array {
        $entity = $this->get($caseId, $userId);

        // Walk up to the root ancestor
        $root = $this->findHierarchyRoot($entity);

        // Check if there is actually a hierarchy (more than just this single case)
        $children = $this->caseMapper->findByParentId($root->getId());
        if ($root->getId() === $caseId && empty($children)) {
            return null;
        }

        return $this->buildHierarchyTree($root, $caseId);
    }

    private function findHierarchyRoot(CaseEntity $entity): CaseEntity {
        $current  = $entity;
        $maxDepth = 20;
        while ($current->getParentCaseId() !== null && $maxDepth-- > 0) {
            $parent = $this->caseMapper->findById($current->getParentCaseId());
            if ($parent === null) {
                break;
            }
            $current = $parent;
        }
        return $current;
    }

    private function buildHierarchyTree(CaseEntity $node, int $currentCaseId): array {
        $children = $this->caseMapper->findByParentId($node->getId());
        return [
            'id'          => $node->getId(),
            'case_number' => $node->getCaseNumber(),
            'title'       => $node->getTitle(),
            'status_id'   => $node->getStatusId(),
            'is_current'  => $node->getId() === $currentCaseId,
            'children'    => array_map(
                fn($c) => $this->buildHierarchyTree($c, $currentCaseId),
                $children
            ),
        ];
    }

    /**
     * Get or create the inbox case for an organisation.
     *
     * The inbox case is a system-managed case (is_inbox = true) that receives
     * documents not yet assigned to a real case, e.g. incoming Digital Post.
     * It is excluded from all normal case listings and search results.
     *
     * The access profile must already exist. Pass the profile that controls
     * who may process the inbox (typically the org's default Digital Post profile).
     */
    public function getOrCreateInboxCase(string $orgUuid, int $accessProfileId): CaseEntity {
        $existing = $this->caseMapper->findInboxByOrgUuid($orgUuid);
        if ($existing !== null) {
            return $existing;
        }

        $caseNumber = $this->caseNumberService->generateCaseNumber();
        $year       = $this->extractYear($caseNumber);

        $entity = new CaseEntity();
        $entity->setCaseNumber($caseNumber);
        $entity->setTitle('Indbakke');
        $entity->setAccessProfileId($accessProfileId);
        $entity->setOrgUuid($orgUuid);
        $entity->setYear($year);
        $entity->setStatusId(1);
        $entity->setCreatedAt(new \DateTime());
        $entity->setUpdatedAt(new \DateTime());
        $entity->setCreatedBy('system');
        $entity->setIsInbox(true);
        $entity->setUuid(self::generateUuid());

        $entity = $this->caseMapper->insert($entity);

        $this->fileService->ensureCaseFilecacheEntry($entity);

        return $entity;
    }

    private function extractYear(string $caseNumber): string {
        if (preg_match('/^(\d{4})/', $caseNumber, $matches)) {
            return $matches[1];
        }
        return (string)date('Y');
    }

    public static function generateUuid(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
