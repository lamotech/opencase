<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\CaseEntity;
use OCA\OpenCase\Db\ClassificationSubjectMapper;
use OCA\OpenCase\Db\SensitivityMapper;
use OCA\OpenCase\Service\AccessProfile\AccessProfileTypeResolver;

/**
 * KLE number and sensitivity stored on the case.
 *
 * With FK Adgang they are part of the access profile. With Grupper the
 * profile only holds the group, so with the KLE classification plan they are
 * stored on the case (opencase_cases.class_subject_uuid / sensitivity_uuid).
 * With Grupper and an Emneplan cases have neither — the Emneplan item
 * classifies them.
 *
 * Read them with CaseEntity::effectiveClassSubjectUuid() /
 * effectiveSensitivityUuid(), which cover both.
 */
class CaseClassificationService {

    /** Defaults for inbox cases when Konfiguration → Indkommende dokumenter has none. */
    private const DEFAULT_INBOX_KLE         = '00.01.00';
    private const DEFAULT_INBOX_SENSITIVITY = '31c09910-e011-46a5-86fb-254374421fe8';

    public function __construct(
        private AccessProfileTypeResolver $accessProfileTypeResolver,
        private ClassificationPlanService $classificationPlanService,
        private ClassificationSubjectMapper $classificationSubjectMapper,
        private SensitivityMapper $sensitivityMapper,
        private Configuration $configuration,
    ) {}

    /**
     * Whether new and edited cases keep their KLE number and sensitivity on
     * the case: Grupper with the KLE classification plan.
     */
    public function onCase(): bool {
        return $this->accessProfileTypeResolver->isGroupMode() && !$this->classificationPlanService->isEmneplan();
    }

    /**
     * Whether cases are classified by KLE number, sensitivity and
     * Handlingsfacet at all: always with FK Adgang (part of the access
     * profile), with Grupper only with the KLE classification plan.
     */
    public function usesKle(): bool {
        return !$this->accessProfileTypeResolver->isGroupMode() || !$this->classificationPlanService->isEmneplan();
    }

    /**
     * The fields a new case needs besides title and organisation: the access
     * profile type's, plus KLE number, sensitivity and Handlingsfacet when
     * cases are classified by KLE.
     *
     * @return string[]
     */
    public function requiredCreateFields(): array {
        $fields = $this->accessProfileTypeResolver->active()->requiredInputFields();
        if ($this->onCase()) {
            $fields[] = 'classification_code';
            $fields[] = 'sensitivity';
        }
        if ($this->usesKle()) {
            $fields[] = 'classification_facet_uuid';
        }
        return array_values(array_unique($fields));
    }

    /**
     * The KLE subject and sensitivity uuids given in $input — as uuids
     * (class_subject_uuid, sensitivity_uuid) or as KLE code / sensitivity key
     * (classification_code, sensitivity), resolved like FK Adgang does.
     * Missing ones are null.
     *
     * @param array<string, mixed> $input
     * @return array{class_subject_uuid: ?string, sensitivity_uuid: ?string}
     */
    public function resolve(array $input): array {
        $classSubjectUuid = null;
        if (!empty($input['class_subject_uuid'])) {
            $classSubjectUuid = (string)$input['class_subject_uuid'];
        } elseif (!empty($input['classification_code'])) {
            $classSubjectUuid = $this->classificationSubjectMapper->findOrCreateByCode((string)$input['classification_code'])->getUuid();
        }

        $sensitivityUuid = null;
        if (!empty($input['sensitivity_uuid'])) {
            $sensitivityUuid = (string)$input['sensitivity_uuid'];
        } elseif (!empty($input['sensitivity'])) {
            $sensitivityUuid = $this->sensitivityMapper->findOrCreateByKey((string)$input['sensitivity'])->getUuid();
        }

        return ['class_subject_uuid' => $classSubjectUuid, 'sensitivity_uuid' => $sensitivityUuid];
    }

    /**
     * Give a new case its KLE number and sensitivity from $input, when they
     * are kept on the case. Both are required then.
     *
     * @param array<string, mixed> $input
     * @throws \InvalidArgumentException when one is missing
     */
    public function applyToNewCase(CaseEntity $case, array $input): void {
        if (!$this->onCase()) {
            return;
        }
        $resolved = $this->resolve($input);
        if ($resolved['class_subject_uuid'] === null) {
            throw new \InvalidArgumentException('Classification code is required');
        }
        if ($resolved['sensitivity_uuid'] === null) {
            throw new \InvalidArgumentException('Sensitivity is required');
        }
        $case->setClassSubjectUuid($resolved['class_subject_uuid']);
        $case->setSensitivityUuid($resolved['sensitivity_uuid']);
    }

    /**
     * Give an inbox case its KLE number and sensitivity, when they are kept
     * on the case: the given ones (e.g. from a separation sheet), else the
     * incoming_kle / incoming_sensitivity config (Konfiguration → Indkommende
     * dokumenter), else the defaults. An unknown sensitivity falls back too.
     */
    public function applyToInboxCase(CaseEntity $case, ?string $classSubjectUuid = null, ?string $sensitivityUuid = null): void {
        if (!$this->onCase()) {
            return;
        }
        $kleCode = (string)$this->configuration->getConfigValue('incoming_kle', '');
        $sensitivityUuid = $sensitivityUuid ?: (string)$this->configuration->getConfigValue('incoming_sensitivity', '');
        if ($sensitivityUuid === '' || $this->sensitivityMapper->findByUuid($sensitivityUuid) === null) {
            $sensitivityUuid = self::DEFAULT_INBOX_SENSITIVITY;
        }
        $resolved = $this->resolve([
            'class_subject_uuid'  => $classSubjectUuid,
            'classification_code' => $kleCode !== '' ? $kleCode : self::DEFAULT_INBOX_KLE,
            'sensitivity_uuid'    => $sensitivityUuid,
        ]);
        $case->setClassSubjectUuid($resolved['class_subject_uuid']);
        $case->setSensitivityUuid($resolved['sensitivity_uuid']);
    }
}
