<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\Process;

use OCA\OpenCase\Db\ProcessDef;
use OCA\OpenCase\Db\ProcessDefMapper;
use OCA\OpenCase\Db\ProcessScope;
use OCA\OpenCase\Db\ProcessScopeMapper;
use OCA\OpenCase\Db\ProcessVersion;
use OCA\OpenCase\Db\ProcessVersionMapper;
use OCA\OpenCase\Service\CaseScopeMatcher;
use OCA\OpenCase\Service\DigitalPostService;
use OCA\OpenCase\Service\NotFoundException;
use OCP\IDBConnection;

/**
 * Manages processes (opencase_process_def), their BPMN versions and the
 * scopes deciding which cases they can be started on.
 *
 * Versioning: each process has at most one draft, which is the only
 * editable version. Publishing deploys the draft to Flowable and marks the
 * previously published version as superseded. Instances already running
 * stay on the version they were started with.
 */
class ProcessDefinitionService {

    public function __construct(
        private ProcessDefMapper $defMapper,
        private ProcessVersionMapper $versionMapper,
        private ProcessScopeMapper $scopeMapper,
        private FlowableClient $flowable,
        private DigitalPostService $digitalPost,
        private IDBConnection $db,
    ) {
    }

    /**
     * @return array{def: ProcessDef, scopes: ProcessScope[], versions: ProcessVersion[]}[]
     */
    public function listProcesses(): array {
        $defs = $this->defMapper->findAll();
        $ids = array_map(fn(ProcessDef $d) => $d->getId(), $defs);
        $scopesByDef = $this->scopeMapper->findGroupedByDefIds($ids);
        $versionsByDef = $this->versionMapper->findGroupedByDefIds($ids);
        return array_map(fn(ProcessDef $d) => [
            'def' => $d,
            'scopes' => $scopesByDef[$d->getId()] ?? [],
            'versions' => $versionsByDef[$d->getId()] ?? [],
        ], $defs);
    }

    /**
     * @return array{def: ProcessDef, scopes: ProcessScope[], versions: ProcessVersion[]}
     */
    public function getProcess(int $id): array {
        $def = $this->requireDef($id);
        return [
            'def' => $def,
            'scopes' => $this->scopeMapper->findGroupedByDefIds([$id])[$id] ?? [],
            'versions' => $this->versionMapper->findByDefId($id),
        ];
    }

    /**
     * Create a process with version 1 as a draft, from $bpmnXml (an
     * imported diagram) or an empty diagram.
     *
     * @param array{scope_type: string, scope_value: string}[] $scopes
     */
    public function createProcess(
        string $name,
        ?string $processKey,
        ?string $description,
        array $scopes,
        ?string $bpmnXml,
        ?string $userId,
    ): ProcessDef {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Navn skal udfyldes');
        }
        $this->validateScopes($scopes);

        $processKey = trim((string)$processKey);
        if ($processKey === '') {
            $processKey = $this->uniqueKey($this->keyFromName($name));
        } else {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_\-]{0,63}$/', $processKey)) {
                throw new \InvalidArgumentException('Procesnøglen må kun indeholde bogstaver (a-z), tal, _ og - og skal starte med et bogstav');
            }
            if ($this->defMapper->findByKey($processKey) !== null) {
                throw new \InvalidArgumentException("Procesnøglen '{$processKey}' er allerede i brug");
            }
        }

        $xml = $bpmnXml !== null && trim($bpmnXml) !== ''
            ? BpmnXml::normalize($bpmnXml, $processKey, $name)
            : BpmnXml::initial($processKey, $name);

        $this->db->beginTransaction();
        try {
            $def = new ProcessDef();
            $def->setProcessKey($processKey);
            $def->setName($name);
            $def->setDescription($this->nullIfBlank($description));
            $def->setActive(true);
            $def->setCreatedAt(new \DateTime());
            $def->setCreatedBy($userId);
            $def->setUpdatedAt(new \DateTime());
            $def->setUpdatedBy($userId);
            $def = $this->defMapper->insert($def);

            $this->replaceScopes($def->getId(), $scopes);
            $this->insertDraft($def->getId(), 1, $xml, $userId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $def;
    }

    /**
     * @param array{scope_type: string, scope_value: string}[]|null $scopes null = leave scopes unchanged
     */
    public function updateProcess(
        int $id,
        ?string $name,
        ?string $description,
        ?bool $active,
        ?array $scopes,
        ?string $userId,
    ): ProcessDef {
        $def = $this->requireDef($id);

        if ($name !== null) {
            $name = trim($name);
            if ($name === '') {
                throw new \InvalidArgumentException('Navn skal udfyldes');
            }
            $def->setName($name);
        }
        if ($description !== null) {
            $def->setDescription($this->nullIfBlank($description));
        }
        if ($active !== null) {
            $def->setActive($active);
        }
        if ($scopes !== null) {
            $this->validateScopes($scopes);
        }
        $def->setUpdatedAt(new \DateTime());
        $def->setUpdatedBy($userId);

        $this->db->beginTransaction();
        try {
            $def = $this->defMapper->update($def);
            if ($scopes !== null) {
                $this->replaceScopes($id, $scopes);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $def;
    }

    /**
     * Delete a process that has never been published. A published process
     * may have run on cases and is deactivated instead.
     */
    public function deleteProcess(int $id): void {
        $def = $this->requireDef($id);
        foreach ($this->versionMapper->findByDefId($id) as $version) {
            if ($version->getPublishedAt() !== null) {
                throw new ProcessConflictException('Processen har været udgivet og kan ikke slettes. Deaktivér den i stedet.');
            }
        }

        $this->db->beginTransaction();
        try {
            $this->scopeMapper->deleteByDefId($id);
            $this->versionMapper->deleteByDefId($id);
            $this->defMapper->delete($def);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * A version including its BPMN XML.
     */
    public function getVersion(int $defId, int $versionId): ProcessVersion {
        return $this->requireVersion($defId, $versionId);
    }

    /**
     * Start a new draft as a copy of $fromVersionId, or of the newest
     * version when null.
     */
    public function createDraft(int $defId, ?int $fromVersionId, ?string $userId): ProcessVersion {
        $def = $this->requireDef($defId);
        if ($this->versionMapper->findDraft($defId) !== null) {
            throw new ProcessConflictException('Processen har allerede en kladde.');
        }

        if ($fromVersionId !== null) {
            $source = $this->requireVersion($defId, $fromVersionId);
        } else {
            $newest = $this->versionMapper->findByDefId($defId)[0] ?? null;
            $source = $newest !== null ? $this->versionMapper->find($newest->getId()) : null;
        }
        $xml = $source !== null
            ? $source->getBpmnXml()
            : BpmnXml::initial($def->getProcessKey(), $def->getName());

        return $this->insertDraft($defId, $this->versionMapper->maxVersionNo($defId) + 1, $xml, $userId);
    }

    /**
     * Save the diagram of a draft. $baseChecksum is the checksum the editor
     * loaded; if the draft has been saved since, nothing is saved.
     */
    public function saveDraft(int $defId, int $versionId, string $bpmnXml, ?string $baseChecksum, ?string $userId): ProcessVersion {
        $def = $this->requireDef($defId);
        $version = $this->requireDraft($defId, $versionId);
        if ($baseChecksum !== null && $baseChecksum !== '' && $baseChecksum !== $version->getChecksum()) {
            throw new ProcessConflictException('Kladden er blevet gemt af en anden, siden du åbnede den. Genindlæs og prøv igen.');
        }

        $xml = BpmnXml::normalize($bpmnXml, $def->getProcessKey(), $def->getName());
        $version->setBpmnXml($xml);
        $version->setChecksum(hash('sha256', $xml));
        $version->setUpdatedAt(new \DateTime());
        $version->setUpdatedBy($userId);
        return $this->versionMapper->update($version);
    }

    public function deleteDraft(int $defId, int $versionId): void {
        $version = $this->requireDraft($defId, $versionId);
        if (count($this->versionMapper->findByDefId($defId)) === 1) {
            throw new ProcessConflictException('Den eneste version af en proces kan ikke slettes. Slet processen i stedet.');
        }
        $this->versionMapper->delete($version);
    }

    /**
     * Problems that would stop the draft from being published, without
     * publishing it.
     *
     * @return string[]
     */
    public function validateDraft(int $defId, int $versionId): array {
        return $this->publishProblems($this->requireDraft($defId, $versionId)->getBpmnXml());
    }

    /**
     * Things in the draft that can be published but probably aren't meant.
     *
     * @return string[]
     */
    public function draftWarnings(int $defId, int $versionId): array {
        return BpmnXml::publishWarnings($this->requireDraft($defId, $versionId)->getBpmnXml());
    }

    /**
     * The automatic actions a service task can perform on this instance.
     *
     * @return array<string, array>
     */
    public function availableTopics(): array {
        return ProcessTopics::available($this->digitalPost->canCheckSubscriptions());
    }

    /**
     * @return string[]
     */
    private function publishProblems(string $xml): array {
        $problems = BpmnXml::publishProblems($xml);
        $available = $this->availableTopics();
        foreach (BpmnXml::serviceTaskTopics($xml) as $name => $topic) {
            if (ProcessTopics::get($topic) !== null && !isset($available[$topic])) {
                $problems[] = "Serviceopgaven \"{$name}\" bruger \"" . ProcessTopics::get($topic)['label'] . "\", som ikke er aktiveret på denne instans.";
            }
        }
        return $problems;
    }

    /**
     * Deploy a draft to Flowable and make it the published version.
     *
     * @throws \InvalidArgumentException when the diagram can't be published
     * @throws \RuntimeException when the engine can't be reached
     */
    public function publish(int $defId, int $versionId, ?string $changeNote, ?string $userId): ProcessVersion {
        $def = $this->requireDef($defId);
        $version = $this->requireDraft($defId, $versionId);

        // The process may have been renamed since the draft was last saved
        $xml = BpmnXml::normalize($version->getBpmnXml(), $def->getProcessKey(), $def->getName());
        $version->setBpmnXml($xml);
        $version->setChecksum(hash('sha256', $xml));

        $problems = $this->publishProblems($xml);
        if ($problems !== []) {
            throw new \InvalidArgumentException(implode(' ', $problems));
        }

        $deployment = $this->flowable->deploy(
            $def->getProcessKey(),
            $def->getName() . ' v' . $version->getVersionNo(),
            $version->getBpmnXml(),
        );

        // Deployed: if the update below fails, the deployment is just an
        // unused process definition in Flowable and the draft can be
        // published again.
        $this->db->beginTransaction();
        try {
            $previous = $this->versionMapper->findPublished($defId);
            if ($previous !== null) {
                $previous->setStatus(ProcessVersion::STATUS_SUPERSEDED);
                $this->versionMapper->update($previous);
            }

            $version->setStatus(ProcessVersion::STATUS_PUBLISHED);
            $version->setChangeNote($this->nullIfBlank($changeNote));
            $version->setFlowableDeploymentId($deployment['deployment_id']);
            $version->setFlowableProcDefId($deployment['process_definition_id']);
            $version->setPublishedAt(new \DateTime());
            $version->setPublishedBy($userId);
            $version = $this->versionMapper->update($version);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $version;
    }

    /**
     * @throws NotFoundException
     */
    public function getVersionNo(int $versionId): int {
        $version = $this->versionMapper->find($versionId);
        if ($version === null) {
            throw new NotFoundException("Process version {$versionId} not found");
        }
        return $version->getVersionNo();
    }

    private function insertDraft(int $defId, int $versionNo, string $xml, ?string $userId): ProcessVersion {
        $version = new ProcessVersion();
        $version->setProcessDefId($defId);
        $version->setVersionNo($versionNo);
        $version->setStatus(ProcessVersion::STATUS_DRAFT);
        $version->setBpmnXml($xml);
        $version->setChecksum(hash('sha256', $xml));
        $version->setCreatedAt(new \DateTime());
        $version->setCreatedBy($userId);
        $version->setUpdatedAt(new \DateTime());
        $version->setUpdatedBy($userId);
        return $this->versionMapper->insert($version);
    }

    private function requireDef(int $id): ProcessDef {
        $def = $this->defMapper->find($id);
        if ($def === null) {
            throw new NotFoundException("Process {$id} not found");
        }
        return $def;
    }

    private function requireVersion(int $defId, int $versionId): ProcessVersion {
        $version = $this->versionMapper->find($versionId);
        if ($version === null || $version->getProcessDefId() !== $defId) {
            throw new NotFoundException("Process version {$versionId} not found");
        }
        return $version;
    }

    private function requireDraft(int $defId, int $versionId): ProcessVersion {
        $version = $this->requireVersion($defId, $versionId);
        if ($version->getStatus() !== ProcessVersion::STATUS_DRAFT) {
            throw new ProcessConflictException('Versionen er udgivet og kan ikke ændres. Opret en ny kladde.');
        }
        return $version;
    }

    /**
     * "Behandling af byggesag" → "behandling_af_byggesag"
     */
    private function keyFromName(string $name): string {
        $key = mb_strtolower($name);
        $key = strtr($key, ['æ' => 'ae', 'ø' => 'oe', 'å' => 'aa', 'é' => 'e', 'ü' => 'u', 'ö' => 'oe', 'ä' => 'ae']);
        $key = preg_replace('/[^a-z0-9]+/', '_', $key);
        $key = trim((string)$key, '_');
        if ($key === '' || !preg_match('/^[a-z_]/', $key)) {
            $key = 'proces_' . $key;
        }
        return rtrim(substr($key, 0, 56), '_');
    }

    private function uniqueKey(string $base): string {
        $key = $base;
        for ($i = 2; $this->defMapper->findByKey($key) !== null; $i++) {
            $key = $base . '_' . $i;
        }
        return $key;
    }

    private function nullIfBlank(?string $value): ?string {
        $value = $value !== null ? trim($value) : null;
        return $value === '' ? null : $value;
    }

    /**
     * Check scopes before anything is changed, so an invalid scope can't
     * leave a process half-updated.
     *
     * @param array{scope_type: string, scope_value: string}[] $scopes
     */
    private function validateScopes(array $scopes): void {
        foreach ($scopes as $s) {
            $scopeType = is_array($s) ? ($s['scope_type'] ?? '') : '';
            if (!in_array($scopeType, CaseScopeMatcher::SCOPE_TYPES, true)) {
                throw new \InvalidArgumentException("Invalid scope_type '{$scopeType}'");
            }
        }
    }

    /**
     * @param array{scope_type: string, scope_value: string}[] $scopes validated by validateScopes()
     */
    private function replaceScopes(int $defId, array $scopes): void {
        $this->scopeMapper->deleteByDefId($defId);
        foreach ($scopes as $s) {
            $scopeType = $s['scope_type'];
            $scopeValue = trim((string)($s['scope_value'] ?? ''));
            if ($scopeValue === '') {
                continue;
            }
            $entity = new ProcessScope();
            $entity->setProcessDefId($defId);
            $entity->setScopeType($scopeType);
            $entity->setScopeValue($scopeValue);
            $this->scopeMapper->insert($entity);
        }
    }
}
