<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\Process;

use OCA\OpenCase\BackgroundJob\ProcessMigrationJob;
use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\CaseProcess;
use OCA\OpenCase\Db\CaseProcessMapper;
use OCA\OpenCase\Db\ProcessDefMapper;
use OCA\OpenCase\Db\ProcessMigration;
use OCA\OpenCase\Db\ProcessMigrationMapper;
use OCA\OpenCase\Db\ProcessVersion;
use OCA\OpenCase\Db\ProcessVersionMapper;
use OCA\OpenCase\Service\AuditService;
use OCA\OpenCase\Service\NotFoundException;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Moves running processes to the published version of their process.
 *
 * Steps that exist in both versions (same element id) are kept; for a step
 * a process is at that the new version doesn't have, the administrator
 * chooses the step it continues from. Each process is checked by the
 * engine before it is moved, so one that can't be moved stays on its old
 * version and the rest are moved anyway.
 */
class ProcessMigrationService {

    /** Migrations of up to this many processes run right away, larger ones in the background. */
    private const INLINE_LIMIT = 25;

    public function __construct(
        private ProcessDefMapper $defMapper,
        private ProcessVersionMapper $versionMapper,
        private CaseProcessMapper $caseProcessMapper,
        private ProcessMigrationMapper $migrationMapper,
        private CaseMapper $caseMapper,
        private CaseProcessService $processService,
        private FlowableClient $flowable,
        private AuditService $auditService,
        private IJobList $jobList,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * What moving the running processes to the published version involves:
     * per old version, how many processes run on it and which of the steps
     * they are at are missing in the published version.
     *
     * @return array{
     *   target: ?array{id: int, version_no: int},
     *   running: int,
     *   versions: array{version_id: int, version_no: int, count: int, steps: array{id: string, name: string, count: int, exists: bool}[]}[],
     *   target_steps: array{id: string, name: string, type: string}[],
     *   unfinished: bool
     * }
     */
    public function plan(int $defId): array {
        $this->requireDef($defId);
        $target = $this->versionMapper->findPublished($defId);
        if ($target === null) {
            return ['target' => null, 'running' => 0, 'versions' => [], 'target_steps' => [], 'unfinished' => false];
        }
        $targetNodes = BpmnXml::flowNodes($target->getBpmnXml());

        $byVersion = [];
        foreach ($this->caseProcessMapper->findRunningNotOnVersion($defId, $target->getId()) as $process) {
            $byVersion[$process->getProcessVersionId()][] = $process;
        }

        $versions = [];
        $running = 0;
        foreach ($byVersion as $versionId => $processes) {
            $version = $this->versionMapper->find($versionId);
            $oldNodes = $version !== null ? BpmnXml::flowNodes($version->getBpmnXml()) : [];
            $steps = [];
            foreach ($processes as $process) {
                foreach ($process->getActiveActivityIds() as $activityId) {
                    $steps[$activityId] ??= [
                        'id' => $activityId,
                        'name' => $oldNodes[$activityId]['name'] ?? $activityId,
                        'count' => 0,
                        'exists' => isset($targetNodes[$activityId]),
                    ];
                    $steps[$activityId]['count']++;
                }
            }
            $versions[] = [
                'version_id' => $versionId,
                'version_no' => $version?->getVersionNo() ?? 0,
                'count' => count($processes),
                'steps' => array_values($steps),
            ];
            $running += count($processes);
        }
        usort($versions, fn(array $a, array $b) => $a['version_no'] <=> $b['version_no']);

        $targetSteps = [];
        foreach ($targetNodes as $id => $node) {
            $targetSteps[] = ['id' => $id, 'name' => $node['name'], 'type' => $node['type']];
        }

        return [
            'target' => ['id' => $target->getId(), 'version_no' => $target->getVersionNo()],
            'running' => $running,
            'versions' => $versions,
            'target_steps' => $targetSteps,
            'unfinished' => $this->migrationMapper->hasUnfinished($defId),
        ];
    }

    /**
     * Move all running processes of the process to its published version.
     *
     * @param array<string, string> $mappings old element id => new element id,
     *                                        for steps missing in the new version
     * @throws \InvalidArgumentException|ProcessConflictException
     */
    public function start(int $defId, array $mappings, string $userId): ProcessMigration {
        $plan = $this->plan($defId);
        if ($plan['target'] === null) {
            throw new \InvalidArgumentException('Processen har ingen udgivet version.');
        }
        if ($plan['unfinished']) {
            throw new ProcessConflictException('Der er allerede en flytning i gang.');
        }
        if ($plan['running'] === 0) {
            throw new \InvalidArgumentException('Der er ingen kørende processer på ældre versioner.');
        }

        $targetIds = array_column($plan['target_steps'], 'id');
        $clean = [];
        foreach ($plan['versions'] as $version) {
            foreach ($version['steps'] as $step) {
                if ($step['exists']) {
                    continue;
                }
                $to = (string)($mappings[$step['id']] ?? '');
                if (!in_array($to, $targetIds, true)) {
                    throw new \InvalidArgumentException("Vælg hvor processer ved \"{$step['name']}\" skal fortsætte.");
                }
                $clean[$step['id']] = $to;
            }
        }

        $migration = new ProcessMigration();
        $migration->setProcessDefId($defId);
        $migration->setToVersionId($plan['target']['id']);
        $migration->setMappings(json_encode((object)$clean));
        $migration->setStatus(ProcessMigration::STATUS_QUEUED);
        $migration->setTotal($plan['running']);
        $migration->setMigrated(0);
        $migration->setFailed(0);
        $migration->setCreatedAt(new \DateTime());
        $migration->setCreatedBy($userId);
        $migration = $this->migrationMapper->insert($migration);

        if ($plan['running'] <= self::INLINE_LIMIT) {
            $this->run($migration->getId());
            return $this->migrationMapper->find($migration->getId()) ?? $migration;
        }
        $this->jobList->add(ProcessMigrationJob::class, ['migration_id' => $migration->getId()]);
        return $migration;
    }

    /**
     * Run a queued migration (ProcessMigrationJob, or right away when small).
     */
    public function run(int $migrationId): void {
        $migration = $this->migrationMapper->find($migrationId);
        if ($migration === null || $migration->getStatus() === ProcessMigration::STATUS_DONE) {
            return;
        }
        $target = $this->versionMapper->find($migration->getToVersionId());
        $def = $this->defMapper->find($migration->getProcessDefId());
        if ($target === null || $def === null || $target->getFlowableProcDefId() === null) {
            $migration->setStatus(ProcessMigration::STATUS_DONE);
            $migration->setErrors(json_encode([['case_process_id' => 0, 'case_id' => 0, 'case_number' => '', 'error' => 'Målversionen findes ikke.']]));
            $migration->setFinishedAt(new \DateTime());
            $this->migrationMapper->update($migration);
            return;
        }

        $migration->setStatus(ProcessMigration::STATUS_RUNNING);
        $migration = $this->migrationMapper->update($migration);
        $mappings = $migration->getMappingList();
        $errors = $migration->getErrorList();
        $versionNos = [];

        foreach ($this->caseProcessMapper->findRunningNotOnVersion($def->getId(), $target->getId()) as $process) {
            $versionNos[$process->getProcessVersionId()] ??= $this->versionMapper->find($process->getProcessVersionId())?->getVersionNo() ?? 0;
            try {
                $this->migrateOne($process, $target, $mappings, $migration->getCreatedBy() ?? 'system', $def->getName(), $versionNos[$process->getProcessVersionId()]);
                $migration->setMigrated($migration->getMigrated() + 1);
            } catch (\Throwable $e) {
                $this->logger->warning("Could not move process {$process->getId()} to version {$target->getVersionNo()}: " . $e->getMessage());
                $errors[] = [
                    'case_process_id' => $process->getId(),
                    'case_id' => $process->getCaseId(),
                    'case_number' => $this->caseMapper->findById($process->getCaseId())?->getCaseNumber() ?? '',
                    'error' => $e->getMessage(),
                ];
                $migration->setFailed($migration->getFailed() + 1);
                $migration->setErrors(json_encode($errors));
            }
            // Progress is visible while it runs
            $migration = $this->migrationMapper->update($migration);
        }

        $migration->setStatus(ProcessMigration::STATUS_DONE);
        $migration->setFinishedAt(new \DateTime());
        $this->migrationMapper->update($migration);
    }

    /**
     * @return ProcessMigration[]
     */
    public function recent(int $defId): array {
        return $this->migrationMapper->findByDefId($defId);
    }

    /**
     * @param array<string, string> $mappings
     */
    private function migrateOne(CaseProcess $process, ProcessVersion $target, array $mappings, string $userId, string $processName, int $fromVersionNo): void {
        $instanceId = $process->getFlowableInstanceId();
        // Only the mappings for steps this process is at; Flowable checks the rest
        $relevant = array_intersect_key($mappings, array_flip($this->flowable->getActiveActivityIds($instanceId)));

        $problems = $this->flowable->validateMigration($instanceId, (string)$target->getFlowableProcDefId(), $relevant);
        if ($problems !== []) {
            throw new \InvalidArgumentException(implode(' ', $problems));
        }
        $this->flowable->migrateInstance($instanceId, (string)$target->getFlowableProcDefId(), $relevant);

        $process->setProcessVersionId($target->getId());
        $process = $this->caseProcessMapper->update($process);
        $this->processService->sync($process, null, true, true);
        $this->auditService->logProcessMigrated($process->getCaseId(), $userId, $process->getId(), $processName, $fromVersionNo, $target->getVersionNo());
    }

    private function requireDef(int $defId): void {
        if ($this->defMapper->find($defId) === null) {
            throw new NotFoundException("Process {$defId} not found");
        }
    }
}
