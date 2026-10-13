<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\CustomFieldDef;
use OCA\OpenCase\Db\CustomFieldDefMapper;
use OCA\OpenCase\Db\CustomFieldScope;
use OCA\OpenCase\Db\CustomFieldScopeMapper;
use OCA\OpenCase\Db\CustomFieldValue;
use OCA\OpenCase\Db\CustomFieldValueMapper;

/**
 * Manages admin-defined custom fields: their definitions, the
 * casetype/organisation/KLE scoping rules that decide which cases they
 * apply to, and the per-case values.
 */
class CustomFieldService {

    private const FIELD_TYPES = ['text', 'dropdown', 'checkbox', 'date'];
    private const SCOPE_TYPES = ['casetype', 'organisation', 'kle'];

    public function __construct(
        private CustomFieldDefMapper $defMapper,
        private CustomFieldScopeMapper $scopeMapper,
        private CustomFieldValueMapper $valueMapper,
        private CaseScopeMatcher $scopeMatcher,
    ) {
    }

    // ── Definitions (admin) ─────────────────────────────────────────────

    /**
     * @return array{def: CustomFieldDef, scopes: CustomFieldScope[]}[]
     */
    public function listDefsWithScopes(bool $activeOnly = false): array {
        $defs = $this->defMapper->findAll($activeOnly);
        $scopesByField = $this->scopeMapper->findGroupedByFieldIds(
            array_map(fn(CustomFieldDef $d) => $d->getId(), $defs)
        );
        return array_map(fn(CustomFieldDef $d) => [
            'def' => $d,
            'scopes' => $scopesByField[$d->getId()] ?? [],
        ], $defs);
    }

    /**
     * @param array{scope_type: string, scope_value: string}[] $scopes
     */
    public function createDef(
        string $fieldKey,
        string $label,
        string $fieldType,
        array $options,
        bool $required,
        int $sortOrder,
        array $scopes,
        ?string $userId,
    ): CustomFieldDef {
        $fieldKey = $this->normalizeFieldKey($fieldKey);
        $this->validateFieldKey($fieldKey);
        $this->validateFieldType($fieldType);
        if ($this->defMapper->findByKey($fieldKey) !== null) {
            throw new \InvalidArgumentException("A custom field with key '{$fieldKey}' already exists");
        }

        $def = new CustomFieldDef();
        $def->setFieldKey($fieldKey);
        $def->setLabel($label);
        $def->setFieldType($fieldType);
        $def->setOptions($fieldType === 'dropdown' ? json_encode(array_values($options)) : null);
        $def->setRequired($required);
        $def->setSortOrder($sortOrder);
        $def->setActive(true);
        $def->setCreatedAt(new \DateTime());
        $def->setCreatedBy($userId);
        $def->setUpdatedAt(new \DateTime());
        $def->setUpdatedBy($userId);
        $def = $this->defMapper->insert($def);

        $this->replaceScopes($def->getId(), $scopes);

        return $def;
    }

    /**
     * @param array{scope_type: string, scope_value: string}[]|null $scopes null = leave scopes unchanged
     */
    public function updateDef(
        int $id,
        ?string $label,
        ?bool $required,
        ?int $sortOrder,
        ?bool $active,
        ?array $options,
        ?array $scopes,
        ?string $userId,
    ): CustomFieldDef {
        $def = $this->defMapper->find($id);
        if ($def === null) {
            throw new NotFoundException("Custom field {$id} not found");
        }

        if ($label !== null) {
            $def->setLabel($label);
        }
        if ($required !== null) {
            $def->setRequired($required);
        }
        if ($sortOrder !== null) {
            $def->setSortOrder($sortOrder);
        }
        if ($active !== null) {
            $def->setActive($active);
        }
        if ($options !== null && $def->getFieldType() === 'dropdown') {
            $def->setOptions(json_encode(array_values($options)));
        }
        $def->setUpdatedAt(new \DateTime());
        $def->setUpdatedBy($userId);
        $def = $this->defMapper->update($def);

        if ($scopes !== null) {
            $this->replaceScopes($def->getId(), $scopes);
        }

        return $def;
    }

    public function deleteDef(int $id): void {
        $def = $this->defMapper->find($id);
        if ($def === null) {
            throw new NotFoundException("Custom field {$id} not found");
        }
        $this->scopeMapper->deleteByFieldId($id);
        $this->valueMapper->deleteByFieldId($id);
        $this->defMapper->delete($def);
    }

    /**
     * @param array{scope_type: string, scope_value: string}[] $scopes
     */
    private function replaceScopes(int $fieldId, array $scopes): void {
        $this->scopeMapper->deleteByFieldId($fieldId);
        foreach ($scopes as $s) {
            $scopeType = $s['scope_type'] ?? '';
            $scopeValue = trim((string)($s['scope_value'] ?? ''));
            if ($scopeValue === '') {
                continue;
            }
            if (!in_array($scopeType, self::SCOPE_TYPES, true)) {
                throw new \InvalidArgumentException("Invalid scope_type '{$scopeType}'");
            }
            $entity = new CustomFieldScope();
            $entity->setCustomfieldId($fieldId);
            $entity->setScopeType($scopeType);
            $entity->setScopeValue($scopeValue);
            $this->scopeMapper->insert($entity);
        }
    }

    /**
     * Turns arbitrary admin input (including Danish letters and spaces)
     * into a valid machine key: lowercase, æøå transliterated, anything
     * else collapsed to a single underscore.
     */
    private function normalizeFieldKey(string $fieldKey): string {
        $fieldKey = trim($fieldKey);
        $fieldKey = str_replace(
            ['æ', 'ø', 'å', 'Æ', 'Ø', 'Å'],
            ['ae', 'oe', 'aa', 'ae', 'oe', 'aa'],
            $fieldKey,
        );
        $fieldKey = strtolower($fieldKey);
        $fieldKey = preg_replace('/[^a-z0-9_]+/', '_', $fieldKey);
        $fieldKey = trim($fieldKey, '_');
        return substr($fieldKey, 0, 64);
    }

    private function validateFieldKey(string $fieldKey): void {
        if (!preg_match('/^[a-z0-9_]{1,64}$/', $fieldKey)) {
            throw new \InvalidArgumentException('field_key must be 1-64 lowercase letters, digits or underscores');
        }
    }

    private function validateFieldType(string $fieldType): void {
        if (!in_array($fieldType, self::FIELD_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid field_type '{$fieldType}'");
        }
    }

    // ── Applicability (which fields apply to a given case) ─────────────

    /**
     * @return CustomFieldDef[]
     */
    public function getApplicableFields(?int $casetypeId, ?string $orgUuid, ?string $kleCode): array {
        $defs = $this->defMapper->findAll(true);
        if (empty($defs)) {
            return [];
        }
        $scopesByField = $this->scopeMapper->findGroupedByFieldIds(
            array_map(fn(CustomFieldDef $d) => $d->getId(), $defs)
        );
        $orgAncestors = $orgUuid !== null ? $this->scopeMatcher->resolveOrgAncestorUuids($orgUuid) : [];

        return array_values(array_filter($defs, function (CustomFieldDef $def) use ($scopesByField, $casetypeId, $orgAncestors, $kleCode) {
            $scopes = $scopesByField[$def->getId()] ?? [];
            if (empty($scopes)) {
                return true;
            }
            return $this->scopeMatcher->matches($scopes, $casetypeId, $orgAncestors, $kleCode);
        }));
    }

    // ── Values ───────────────────────────────────────────────────────────

    /**
     * @return array<string, string|bool|null> field_key => value
     */
    public function getValuesForCase(int $caseId): array {
        $defs = $this->defMapper->findAll();
        $defsById = [];
        foreach ($defs as $def) {
            $defsById[$def->getId()] = $def;
        }
        $result = [];
        foreach ($this->valueMapper->findByCaseId($caseId) as $value) {
            $def = $defsById[$value->getCustomfieldId()] ?? null;
            if ($def !== null) {
                $result[$def->getFieldKey()] = $value->getValue();
            }
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $values field_key => value
     * @return array<string, string|bool|null> the saved values, keyed by field_key
     */
    public function setValuesForCase(int $caseId, array $values, ?string $userId): array {
        $defsByKey = $this->defsByFieldKey();

        foreach ($values as $fieldKey => $value) {
            $def = $defsByKey[$fieldKey] ?? null;
            if ($def === null) {
                throw new \InvalidArgumentException("Unknown custom field '{$fieldKey}'");
            }
            $this->validateValue($def, $value);
            $this->saveValue($caseId, $def, $value, $userId);
        }

        return $this->getValuesForCase($caseId);
    }

    /**
     * Validate a set of values against their field definitions without
     * persisting anything. Used to fail fast — e.g. before creating a case
     * via the public API — rather than ending up with a case that was
     * created but whose custom field values were silently rejected.
     *
     * @param array<string, mixed> $values field_key => value
     * @throws \InvalidArgumentException if any value is invalid for its field
     */
    public function validateValuesForFields(array $values): void {
        $defsByKey = $this->defsByFieldKey();
        foreach ($values as $fieldKey => $value) {
            $def = $defsByKey[$fieldKey] ?? null;
            if ($def === null) {
                throw new \InvalidArgumentException("Unknown custom field '{$fieldKey}'");
            }
            $this->validateValue($def, $value);
        }
    }

    /**
     * @return array<string, CustomFieldDef>
     */
    private function defsByFieldKey(): array {
        $defsByKey = [];
        foreach ($this->defMapper->findAll() as $def) {
            $defsByKey[$def->getFieldKey()] = $def;
        }
        return $defsByKey;
    }

    private function validateValue(CustomFieldDef $def, mixed $value): void {
        if ($value === null || $value === '') {
            if ($def->getRequired()) {
                throw new \InvalidArgumentException("Field '{$def->getLabel()}' is required");
            }
            return;
        }

        switch ($def->getFieldType()) {
            case 'dropdown':
                if (!in_array((string)$value, $def->getOptionsArray(), true)) {
                    throw new \InvalidArgumentException("Invalid option for field '{$def->getLabel()}'");
                }
                break;
            case 'checkbox':
                if (!is_bool($value) && !in_array($value, [0, 1, '0', '1'], true)) {
                    throw new \InvalidArgumentException("Field '{$def->getLabel()}' must be a boolean");
                }
                break;
            case 'date':
                if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    throw new \InvalidArgumentException("Field '{$def->getLabel()}' must be a date (YYYY-MM-DD)");
                }
                break;
            case 'text':
            default:
                if (!is_string($value) && !is_numeric($value)) {
                    throw new \InvalidArgumentException("Field '{$def->getLabel()}' must be text");
                }
                if (is_string($value) && strlen($value) > 500) {
                    throw new \InvalidArgumentException("Field '{$def->getLabel()}' must be 500 characters or fewer");
                }
                break;
        }
    }

    private function saveValue(int $caseId, CustomFieldDef $def, mixed $value, ?string $userId): void {
        $entity = $this->valueMapper->findByCaseAndField($caseId, $def->getId());
        $isNew = $entity === null;
        if ($entity === null) {
            $entity = new CustomFieldValue();
            $entity->setCaseId($caseId);
            $entity->setCustomfieldId($def->getId());
        }

        $entity->setValueText(null);
        $entity->setValueBool(null);
        $entity->setValueDate(null);

        if ($value === null || $value === '') {
            // Clear the value but keep the row for audit (updated_at/by).
        } elseif ($def->getFieldType() === 'checkbox') {
            $entity->setValueBool(in_array($value, [true, 1, '1'], true));
        } elseif ($def->getFieldType() === 'date') {
            $entity->setValueDate(new \DateTime((string)$value));
        } else {
            $entity->setValueText((string)$value);
        }

        $entity->setUpdatedAt(new \DateTime());
        $entity->setUpdatedBy($userId);

        if ($isNew) {
            $this->valueMapper->insert($entity);
        } else {
            $this->valueMapper->update($entity);
        }
    }
}
