<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\CaseDocIndexScope;
use OCA\OpenCase\Db\CustomFieldScope;
use OCA\OpenCase\Db\OrganisationMapper;

/**
 * Matches a case's casetype / organisation / KLE code / Emneplan item against
 * scope rows (scope_type casetype | organisation | kle | class_plan). Shared
 * by custom fields, phrases, processes and the SagDokumentIndeks (SAPA)
 * transfer rules (which allow SAPA_SCOPE_TYPES only).
 *
 * A class_plan scope value is an Emneplan item uuid (opencase_class_plan) and
 * matches that item exactly — the Emneplan has no hierarchy to wildcard.
 */
class CaseScopeMatcher {

    public const SCOPE_TYPES = ['casetype', 'organisation', 'kle', 'class_plan'];

    /**
     * SagDokumentIndeks only receives FK Adgang cases with a KLE number, so
     * an Emneplan scope could never match a case that may be transferred.
     */
    public const SAPA_SCOPE_TYPES = ['casetype', 'organisation', 'kle'];

    public function __construct(
        private OrganisationMapper $organisationMapper,
    ) {
    }

    /**
     * True when the case attributes satisfy every scope type present in
     * $scopes (OR within a type, AND across types). An empty $scopes
     * matches everything.
     *
     * @param array<CustomFieldScope|CaseDocIndexScope> $scopes anything with getScopeType()/getScopeValue()
     * @param string[] $orgAncestors from resolveOrgAncestorUuids()
     * @param string|null $classPlanUuid the case's Emneplan item (opencase_cases.class_plan_uuid)
     */
    public function matches(array $scopes, ?int $casetypeId, array $orgAncestors, ?string $kleCode, ?string $classPlanUuid = null): bool {
        $byType = [];
        foreach ($scopes as $scope) {
            $byType[$scope->getScopeType()][] = $scope->getScopeValue();
        }

        foreach ($byType as $type => $values) {
            switch ($type) {
                case 'casetype':
                    if ($casetypeId === null || !in_array((string)$casetypeId, $values, true)) {
                        return false;
                    }
                    break;
                case 'organisation':
                    if (empty(array_intersect($orgAncestors, $values))) {
                        return false;
                    }
                    break;
                case 'kle':
                    if ($kleCode === null || !$this->kleCodeMatchesAny($kleCode, $values)) {
                        return false;
                    }
                    break;
                case 'class_plan':
                    if ($classPlanUuid === null || $classPlanUuid === '' || !in_array($classPlanUuid, $values, true)) {
                        return false;
                    }
                    break;
            }
        }
        return true;
    }

    /**
     * @param string[] $patterns
     */
    private function kleCodeMatchesAny(string $code, array $patterns): bool {
        foreach ($patterns as $pattern) {
            if ($this->kleCodeMatches($code, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Matches a concrete KLE code against a pattern using the same
     * "*"/".*" wildcard convention as PrivilegeService::toSqlLikePattern.
     */
    private function kleCodeMatches(string $code, string $pattern): bool {
        $pattern = trim($pattern);
        if ($pattern === '*' || $pattern === '%') {
            return true;
        }
        if (str_ends_with($pattern, '.*')) {
            $prefix = substr($pattern, 0, -1);
            return str_starts_with($code, $prefix);
        }
        if (str_ends_with($pattern, '.%')) {
            $prefix = substr($pattern, 0, -1);
            return str_starts_with($code, $prefix);
        }
        return $code === $pattern;
    }

    /**
     * The given organisations plus all their descendants — the inverse of
     * resolveOrgAncestorUuids(), for turning organisation scopes into a SQL
     * IN list.
     *
     * @param string[] $rootUuids
     * @return string[]
     */
    public function resolveOrgDescendantUuids(array $rootUuids): array {
        $childrenByParent = [];
        foreach ($this->organisationMapper->getParentMap() as $uuid => $parent) {
            $childrenByParent[$parent][] = $uuid;
        }
        $result = [];
        $queue = array_values($rootUuids);
        while ($queue !== []) {
            $uuid = array_shift($queue);
            if (isset($result[$uuid])) {
                continue;
            }
            $result[$uuid] = true;
            foreach ($childrenByParent[$uuid] ?? [] as $child) {
                $queue[] = $child;
            }
        }
        return array_keys($result);
    }

    /**
     * KLE scope pattern ("27.69.00", "27.%", "27.*", "*") as a SQL LIKE
     * pattern, or null when it matches every code.
     */
    public function kleLikePattern(string $pattern): ?string {
        $pattern = trim($pattern);
        if ($pattern === '*' || $pattern === '%') {
            return null;
        }
        if (str_ends_with($pattern, '.*')) {
            return substr($pattern, 0, -1) . '%';
        }
        return $pattern;
    }

    /**
     * Walks opencase_org's parent chain and returns the uuid of the org
     * itself plus every ancestor, so a scope on a parent org also
     * applies to cases belonging to its descendants.
     *
     * @return string[]
     */
    public function resolveOrgAncestorUuids(string $orgUuid): array {
        $chain = [];
        $current = $orgUuid;
        $depth = 0;
        while ($current !== null && $current !== '' && $depth < 20 && !in_array($current, $chain, true)) {
            $chain[] = $current;
            $org = $this->organisationMapper->findByUuid($current);
            $current = $org?->getOrgParentUuid();
            $depth++;
        }
        return $chain;
    }
}
