<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\SeparationSheet;
use OCA\OpenCase\Db\SeparationSheetMapper;

/**
 * Creates and lists separation sheets (Separationsark) — printable,
 * QR-identified sheets used to mark where a scanned/imported document
 * should be filed.
 */
class SeparationSheetService {

    public const TYPES = ['existing case', 'new case', 'inbox case', 'attachment'];

    /** Types whose values can be changed after creation. */
    public const EDITABLE_TYPES = ['existing case', 'new case', 'inbox case'];

    public function __construct(
        private SeparationSheetMapper $mapper,
        private CaseMapper $caseMapper,
    ) {}

    /**
     * @return SeparationSheet[]
     */
    public function list(): array {
        return $this->mapper->findAllOrdered();
    }

    public function findById(int $id): ?SeparationSheet {
        return $this->mapper->findById($id);
    }

    /**
     * @param array<string, mixed> $params
     * @throws \InvalidArgumentException
     */
    public function create(string $type, array $params): SeparationSheet {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Invalid type: {$type}");
        }

        $sheet = new SeparationSheet();
        $sheet->setName($this->generateName());
        $sheet->setType($type);
        $this->applyFields($sheet, $type, $params);

        return $this->mapper->insert($sheet);
    }

    /**
     * Update the values of an existing sheet. The type and the QR name are
     * immutable — a printed sheet must keep identifying the same record.
     *
     * @param array<string, mixed> $params
     * @throws \InvalidArgumentException
     */
    public function update(SeparationSheet $sheet, array $params): SeparationSheet {
        $type = $sheet->getType();
        if (!in_array($type, self::EDITABLE_TYPES, true)) {
            throw new \InvalidArgumentException("Separation sheets of type \"{$type}\" cannot be edited");
        }
        $this->applyFields($sheet, $type, $params);

        return $this->mapper->update($sheet);
    }

    public function delete(SeparationSheet $sheet): void {
        $this->mapper->delete($sheet);
    }

    /**
     * Write the type-specific values of $params onto $sheet.
     *
     * @param array<string, mixed> $params
     * @throws \InvalidArgumentException
     */
    private function applyFields(SeparationSheet $sheet, string $type, array $params): void {
        switch ($type) {
            case 'existing case':
                $caseNumber = trim((string)($params['case_number'] ?? ''));
                if ($caseNumber === '') {
                    throw new \InvalidArgumentException('case_number is required');
                }
                $case = $this->caseMapper->findByCaseNumber($caseNumber);
                if ($case === null) {
                    throw new \InvalidArgumentException("Case not found: {$caseNumber}");
                }
                $sheet->setCaseNumber($case->getCaseNumber());
                $sheet->setTitle($case->getTitle());
                break;

            case 'new case':
                $title = trim((string)($params['title'] ?? ''));
                if ($title === '') {
                    throw new \InvalidArgumentException('title is required');
                }
                $sheet->setTitle($title);
                $sheet->setOrgUuid($this->nullableString($params['org_uuid'] ?? null));
                $sheet->setClassSubjectUuid($this->nullableString($params['class_subject_uuid'] ?? null));
                $sheet->setSensitivityUuid($this->nullableString($params['sensitivity_uuid'] ?? null));
                $sheet->setClassificationFacetUuid($this->nullableString($params['classification_facet_uuid'] ?? null));
                $sheet->setInsightLevelId($this->nullableInt($params['insight_level_id'] ?? null));
                $sheet->setResponsibleUserId($this->nullableString($params['responsible_user_id'] ?? null));
                break;

            case 'inbox case':
                $sheet->setOrgUuid($this->nullableString($params['org_uuid'] ?? null));
                $sheet->setClassSubjectUuid($this->nullableString($params['class_subject_uuid'] ?? null));
                $sheet->setSensitivityUuid($this->nullableString($params['sensitivity_uuid'] ?? null));
                $sheet->setClassificationFacetUuid($this->nullableString($params['classification_facet_uuid'] ?? null));
                $sheet->setInsightLevelId($this->nullableInt($params['insight_level_id'] ?? null));
                break;

            case 'attachment':
                // No fields — just the type and a fresh QR-identifiable name.
                break;
        }
    }

    private function generateName(): string {
        return 'SEP-' . strtoupper(bin2hex(random_bytes(4)));
    }

    private function nullableString(mixed $value): ?string {
        if ($value === null || $value === '') {
            return null;
        }
        return (string)$value;
    }

    private function nullableInt(mixed $value): ?int {
        if ($value === null || $value === '') {
            return null;
        }
        return (int)$value;
    }
}
