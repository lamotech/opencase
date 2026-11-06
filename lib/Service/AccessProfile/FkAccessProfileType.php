<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\AccessProfile;

use OCA\OpenCase\Db\AccessProfile;
use OCA\OpenCase\Db\AccessProfileMapper;
use OCA\OpenCase\Db\ClassificationSubjectMapper;
use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Db\SensitivityMapper;
use OCA\OpenCase\Service\PrivilegeService;

/**
 * "FK Adgang" access profiles: a unique (organisation, KLE classification
 * subject, sensitivity) tuple. Users are granted profiles from their SAML
 * privilege constraints via PrivilegeService.
 */
class FkAccessProfileType implements AccessProfileType {

    public function __construct(
        private AccessProfileMapper $accessProfileMapper,
        private OrganisationMapper $organisationMapper,
        private ClassificationSubjectMapper $classificationSubjectMapper,
        private SensitivityMapper $sensitivityMapper,
        private PrivilegeService $privilegeService,
    ) {}

    public function key(): string {
        return AccessProfile::TYPE_FK;
    }

    public function requiredInputFields(): array {
        return ['classification_code', 'sensitivity'];
    }

    /**
     * Each part of the tuple is given either as a UUID or as a human-readable
     * value, which is resolved (or created) via its lookup mapper:
     *
     *   org_uuid           | organisation        (org name)
     *   class_subject_uuid | classification_code (KLE code)
     *   sensitivity_uuid   | sensitivity         (sensitivity key)
     *
     * The UUID wins when both are given. UUIDs are not validated here —
     * callers that take UUIDs from config or input check them first.
     */
    public function resolveProfile(array $input): AccessProfile {
        $orgUuid          = $this->resolveOrgUuid($input);
        $classSubjectUuid = $this->resolveClassSubjectUuid($input);
        $sensitivityUuid  = $this->resolveSensitivityUuid($input);

        $profile = $this->accessProfileMapper->findOrCreate($orgUuid, $classSubjectUuid, $sensitivityUuid);
        $this->grantUsers($profile);

        return $profile;
    }

    /**
     * Grant the profile to all users whose SAML privilege constraints cover it.
     */
    public function grantUsers(AccessProfile $profile): void {
        $this->privilegeService->grantPrivilegedUsersToProfile(
            $profile->getId(),
            $profile->getOrgUuid(),
            (string)$profile->getClassSubjectUuid(),
            (string)$profile->getSensitivityUuid()
        );
    }

    private function resolveOrgUuid(array $input): string {
        if (!empty($input['org_uuid'])) {
            return (string)$input['org_uuid'];
        }
        if (!empty($input['organisation'])) {
            return $this->organisationMapper->findOrCreateByName((string)$input['organisation'])->getOrgUuid();
        }
        throw new \InvalidArgumentException('Organisation is required');
    }

    private function resolveClassSubjectUuid(array $input): string {
        if (!empty($input['class_subject_uuid'])) {
            return (string)$input['class_subject_uuid'];
        }
        if (!empty($input['classification_code'])) {
            return $this->classificationSubjectMapper->findOrCreateByCode((string)$input['classification_code'])->getUuid();
        }
        throw new \InvalidArgumentException('Classification code is required');
    }

    private function resolveSensitivityUuid(array $input): string {
        if (!empty($input['sensitivity_uuid'])) {
            return (string)$input['sensitivity_uuid'];
        }
        if (!empty($input['sensitivity'])) {
            return $this->sensitivityMapper->findOrCreateByKey((string)$input['sensitivity'])->getUuid();
        }
        throw new \InvalidArgumentException('Sensitivity is required');
    }
}
