<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\CaseDocIndexDef;
use OCA\OpenCase\Db\CaseDocIndexDefMapper;
use OCA\OpenCase\Db\CaseDocIndexScope;
use OCA\OpenCase\Db\CaseDocIndexScopeMapper;
use OCA\OpenCase\Db\CaseMapper;

/**
 * Manages the rules (opencase_casedocindex_def + _scope) deciding which
 * cases are transferred to SagDokumentIndeks (SAPA). A case is transferred
 * when it matches at least one active rule; a rule without scopes matches
 * every case, and with no active rules nothing is transferred.
 */
class CaseDocIndexRuleService {

    public function __construct(
        private CaseDocIndexDefMapper $defMapper,
        private CaseDocIndexScopeMapper $scopeMapper,
        private CaseScopeMatcher $scopeMatcher,
        private CaseMapper $caseMapper,
    ) {
    }

    /**
     * @return array{def: CaseDocIndexDef, scopes: CaseDocIndexScope[]}[]
     */
    public function listRulesWithScopes(bool $activeOnly = false): array {
        $defs = $this->defMapper->findAll($activeOnly);
        $scopesByDef = $this->scopeMapper->findGroupedByDefIds(
            array_map(fn(CaseDocIndexDef $d) => $d->getId(), $defs)
        );
        return array_map(fn(CaseDocIndexDef $d) => [
            'def' => $d,
            'scopes' => $scopesByDef[$d->getId()] ?? [],
        ], $defs);
    }

    /**
     * @return array{def: CaseDocIndexDef, scopes: CaseDocIndexScope[]}
     */
    public function getRuleWithScopes(int $id): array {
        $def = $this->defMapper->find($id);
        if ($def === null) {
            throw new NotFoundException("SAPA rule {$id} not found");
        }
        return [
            'def' => $def,
            'scopes' => $this->scopeMapper->findGroupedByDefIds([$id])[$id] ?? [],
        ];
    }

    /**
     * @param array{scope_type: string, scope_value: string}[] $scopes
     */
    public function createRule(string $name, int $sortOrder, array $scopes, ?string $userId): CaseDocIndexDef {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Name is required');
        }

        $def = new CaseDocIndexDef();
        $def->setName($name);
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
    public function updateRule(
        int $id,
        ?string $name,
        ?int $sortOrder,
        ?bool $active,
        ?array $scopes,
        ?string $userId,
    ): CaseDocIndexDef {
        $def = $this->defMapper->find($id);
        if ($def === null) {
            throw new NotFoundException("SAPA rule {$id} not found");
        }

        if ($name !== null) {
            $name = trim($name);
            if ($name === '') {
                throw new \InvalidArgumentException('Name is required');
            }
            $def->setName($name);
        }
        if ($sortOrder !== null) {
            $def->setSortOrder($sortOrder);
        }
        if ($active !== null) {
            $def->setActive($active);
        }
        $def->setUpdatedAt(new \DateTime());
        $def->setUpdatedBy($userId);
        $def = $this->defMapper->update($def);

        if ($scopes !== null) {
            $this->replaceScopes($def->getId(), $scopes);
        }

        return $def;
    }

    public function deleteRule(int $id): void {
        $def = $this->defMapper->find($id);
        if ($def === null) {
            throw new NotFoundException("SAPA rule {$id} not found");
        }
        $this->scopeMapper->deleteByDefId($id);
        $this->defMapper->delete($def);
    }

    /**
     * Cases matching one rule that need sending (never sent) or, with
     * $resend, re-sending (changed since they were last sent).
     *
     * @param CaseDocIndexScope[] $scopes
     * @return array<int, array{id: int, uuid: string}>
     */
    public function findCasesForRule(array $scopes, bool $resend, int $limit): array {
        [$casetypeIds, $orgUuids, $klePatterns] = $this->scopeFilters($scopes);
        return $this->caseMapper->findForSapaTransfer($casetypeIds, $orgUuids, $klePatterns, $resend, $limit);
    }

    /**
     * How many cases match a rule, and how many of those have been sent
     * to SagDokumentIndeks (by this or any other rule).
     *
     * @return array{matching: int, sent: int}
     */
    public function countCasesForRule(int $id): array {
        $rule = $this->getRuleWithScopes($id);
        [$casetypeIds, $orgUuids, $klePatterns] = $this->scopeFilters($rule['scopes']);
        return $this->caseMapper->countForSapaRule($casetypeIds, $orgUuids, $klePatterns);
    }

    /**
     * A rule's scopes as CaseMapper filter lists: casetype ids, org uuids
     * (with descendants) and KLE LIKE patterns.
     *
     * @param CaseDocIndexScope[] $scopes
     * @return array{0: int[], 1: string[], 2: string[]}
     */
    private function scopeFilters(array $scopes): array {
        $casetypeIds = [];
        $orgRoots = [];
        $klePatterns = [];
        $kleMatchesAll = false;
        foreach ($scopes as $scope) {
            switch ($scope->getScopeType()) {
                case 'casetype':
                    $casetypeIds[] = (int)$scope->getScopeValue();
                    break;
                case 'organisation':
                    $orgRoots[] = $scope->getScopeValue();
                    break;
                case 'kle':
                    $like = $this->scopeMatcher->kleLikePattern($scope->getScopeValue());
                    if ($like === null) {
                        $kleMatchesAll = true;
                    } else {
                        $klePatterns[] = $like;
                    }
                    break;
            }
        }
        if ($kleMatchesAll) {
            $klePatterns = [];
        }
        $orgUuids = $orgRoots !== [] ? $this->scopeMatcher->resolveOrgDescendantUuids($orgRoots) : [];

        return [$casetypeIds, $orgUuids, $klePatterns];
    }

    /**
     * Whether a case with these attributes should be transferred to SAPA,
     * i.e. matches at least one active rule.
     */
    public function shouldTransfer(?int $casetypeId, ?string $orgUuid, ?string $kleCode): bool {
        $rules = $this->listRulesWithScopes(true);
        if (empty($rules)) {
            return false;
        }
        $orgAncestors = $orgUuid !== null ? $this->scopeMatcher->resolveOrgAncestorUuids($orgUuid) : [];
        foreach ($rules as $rule) {
            if ($this->scopeMatcher->matches($rule['scopes'], $casetypeId, $orgAncestors, $kleCode)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array{scope_type: string, scope_value: string}[] $scopes
     */
    private function replaceScopes(int $defId, array $scopes): void {
        $this->scopeMapper->deleteByDefId($defId);
        foreach ($scopes as $s) {
            $scopeType = $s['scope_type'] ?? '';
            $scopeValue = trim((string)($s['scope_value'] ?? ''));
            if ($scopeValue === '') {
                continue;
            }
            if (!in_array($scopeType, CaseScopeMatcher::SCOPE_TYPES, true)) {
                throw new \InvalidArgumentException("Invalid scope_type '{$scopeType}'");
            }
            $entity = new CaseDocIndexScope();
            $entity->setCasedocindexDefId($defId);
            $entity->setScopeType($scopeType);
            $entity->setScopeValue($scopeValue);
            $this->scopeMapper->insert($entity);
        }
    }
}
