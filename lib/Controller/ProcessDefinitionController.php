<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\ProcessDef;
use OCA\OpenCase\Db\ProcessScope;
use OCA\OpenCase\Db\ProcessVersion;
use OCA\OpenCase\Service\NotFoundException;
use OCA\OpenCase\Service\Process\ProcessConflictException;
use OCA\OpenCase\Db\CaseProcessMapper;
use OCA\OpenCase\Db\ProcessMigration;
use OCA\OpenCase\Service\Process\ProcessDefinitionService;
use OCA\OpenCase\Service\Process\ProcessMigrationService;
use OCA\OpenCase\Service\Process\ProcessStatisticsService;
use OCA\OpenCase\Service\RoleService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * REST API for designing processes ("Administrer processer"). Requires the
 * OpenCase "Process Designer" app role.
 *
 * Routes:
 *   GET    /api/v1/admin/process-topics                              — Automatic actions for service tasks
 *   GET    /api/v1/admin/processes                                   — List processes with scopes and versions
 *   POST   /api/v1/admin/processes                                   — Create a process (version 1 as draft)
 *   GET    /api/v1/admin/processes/{id}                              — One process with scopes and versions
 *   PUT    /api/v1/admin/processes/{id}                              — Update name, description, active, scopes
 *   DELETE /api/v1/admin/processes/{id}                              — Delete a never-published process
 *   POST   /api/v1/admin/processes/{id}/versions                     — Start a new draft
 *   GET    /api/v1/admin/processes/{id}/versions/{versionId}         — A version including BPMN XML
 *   PUT    /api/v1/admin/processes/{id}/versions/{versionId}         — Save the draft's BPMN XML
 *   DELETE /api/v1/admin/processes/{id}/versions/{versionId}         — Delete the draft
 *   GET    /api/v1/admin/processes/{id}/versions/{versionId}/validate — Problems blocking publishing, and warnings
 *   POST   /api/v1/admin/processes/{id}/versions/{versionId}/publish — Deploy the draft to the process engine
 *   GET    /api/v1/admin/processes/{id}/statistics                   — Figures for running and ended processes
 *   GET    /api/v1/admin/processes/{id}/migration-plan               — Running processes on older versions
 *   GET    /api/v1/admin/processes/{id}/migrations                   — Latest migrations with progress
 *   POST   /api/v1/admin/processes/{id}/migrations                   — Move running processes to the published version
 */
class ProcessDefinitionController extends ApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private ProcessDefinitionService $processService,
        private ProcessMigrationService $migrationService,
        private ProcessStatisticsService $statisticsService,
        private CaseProcessMapper $caseProcessMapper,
        private RoleService $roleService,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    private function checkProcessDesigner(): ?DataResponse {
        if ($this->userId === null
            || !$this->roleService->userHasRole($this->userId, 'Process Designer')) {
            return new DataResponse(
                ['error' => 'OpenCase Process Designer role required.'],
                Http::STATUS_FORBIDDEN,
            );
        }
        return null;
    }

    /**
     * The automatic actions a service task can perform, with their
     * parameters, for the designer.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/admin/process-topics')]
    public function topics(): DataResponse {
        if ($denied = $this->checkProcessDesigner()) {
            return $denied;
        }
        $topics = [];
        foreach ($this->processService->availableTopics() as $topic => $definition) {
            unset($definition['requires']);
            $topics[] = ['topic' => $topic] + $definition;
        }
        return new DataResponse(['topics' => $topics]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/admin/processes')]
    public function index(): DataResponse {
        if ($denied = $this->checkProcessDesigner()) {
            return $denied;
        }
        $rows = $this->processService->listProcesses();
        $running = $this->caseProcessMapper->countRunningByDef();
        return new DataResponse(['processes' => array_map(
            fn(array $row) => $this->serializeProcess($row['def'], $row['scopes'], $row['versions'])
                + ['running_count' => $running[$row['def']->getId()] ?? 0],
            $rows,
        )]);
    }

    /**
     * Request body (JSON):
     *   name         — Required
     *   process_key  — Optional; derived from name when empty. Can't be changed later.
     *   description  — Optional
     *   scopes       — Optional array of {scope_type, scope_value}
     *   bpmn_xml     — Optional diagram to import as version 1
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/admin/processes')]
    public function create(): DataResponse {
        if ($denied = $this->checkProcessDesigner()) {
            return $denied;
        }
        $params = $this->request->getParams();

        return $this->handle(function () use ($params) {
            $def = $this->processService->createProcess(
                (string)($params['name'] ?? ''),
                isset($params['process_key']) ? (string)$params['process_key'] : null,
                isset($params['description']) ? (string)$params['description'] : null,
                is_array($params['scopes'] ?? null) ? $params['scopes'] : [],
                isset($params['bpmn_xml']) ? (string)$params['bpmn_xml'] : null,
                $this->userId,
            );
            return new DataResponse(['process' => $this->processResponse($def->getId())], Http::STATUS_CREATED);
        });
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/admin/processes/{id}')]
    public function show(int $id): DataResponse {
        if ($denied = $this->checkProcessDesigner()) {
            return $denied;
        }
        return $this->handle(fn() => new DataResponse(['process' => $this->processResponse($id)]));
    }

    /**
     * Request body (JSON): any of name, description, active, scopes.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/admin/processes/{id}')]
    public function update(int $id): DataResponse {
        if ($denied = $this->checkProcessDesigner()) {
            return $denied;
        }
        $params = $this->request->getParams();

        return $this->handle(function () use ($id, $params) {
            $this->processService->updateProcess(
                $id,
                array_key_exists('name', $params) ? (string)$params['name'] : null,
                array_key_exists('description', $params) ? (string)$params['description'] : null,
                array_key_exists('active', $params) ? (bool)$params['active'] : null,
                is_array($params['scopes'] ?? null) ? $params['scopes'] : null,
                $this->userId,
            );
            return new DataResponse(['process' => $this->processResponse($id)]);
        });
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/admin/processes/{id}')]
    public function destroy(int $id): DataResponse {
        if ($denied = $this->checkProcessDesigner()) {
            return $denied;
        }
        return $this->handle(function () use ($id) {
            $this->processService->deleteProcess($id);
            return new DataResponse([]);
        });
    }

    /**
     * Request body (JSON):
     *   from_version_id — Optional; copy this version instead of the newest
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/admin/processes/{id}/versions')]
    public function createDraft(int $id): DataResponse {
        if ($denied = $this->checkProcessDesigner()) {
            return $denied;
        }
        $from = $this->request->getParam('from_version_id');

        return $this->handle(function () use ($id, $from) {
            $version = $this->processService->createDraft($id, $from !== null && $from !== '' ? (int)$from : null, $this->userId);
            return new DataResponse(['version' => $this->serializeVersion($version, true)], Http::STATUS_CREATED);
        });
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/admin/processes/{id}/versions/{versionId}')]
    public function showVersion(int $id, int $versionId): DataResponse {
        if ($denied = $this->checkProcessDesigner()) {
            return $denied;
        }
        return $this->handle(fn() => new DataResponse([
            'version' => $this->serializeVersion($this->processService->getVersion($id, $versionId), true),
        ]));
    }

    /**
     * Request body (JSON):
     *   bpmn_xml       — Required
     *   base_checksum  — Checksum of the version the editor loaded; the save
     *                    is refused (409) if the draft has changed since.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/admin/processes/{id}/versions/{versionId}')]
    public function saveDraft(int $id, int $versionId): DataResponse {
        if ($denied = $this->checkProcessDesigner()) {
            return $denied;
        }
        $xml = (string)$this->request->getParam('bpmn_xml', '');
        $baseChecksum = $this->request->getParam('base_checksum');

        return $this->handle(function () use ($id, $versionId, $xml, $baseChecksum) {
            $version = $this->processService->saveDraft($id, $versionId, $xml, $baseChecksum !== null ? (string)$baseChecksum : null, $this->userId);
            return new DataResponse(['version' => $this->serializeVersion($version, true)]);
        });
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/admin/processes/{id}/versions/{versionId}')]
    public function deleteDraft(int $id, int $versionId): DataResponse {
        if ($denied = $this->checkProcessDesigner()) {
            return $denied;
        }
        return $this->handle(function () use ($id, $versionId) {
            $this->processService->deleteDraft($id, $versionId);
            return new DataResponse([]);
        });
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/admin/processes/{id}/versions/{versionId}/validate')]
    public function validateDraft(int $id, int $versionId): DataResponse {
        if ($denied = $this->checkProcessDesigner()) {
            return $denied;
        }
        return $this->handle(fn() => new DataResponse([
            'problems' => $this->processService->validateDraft($id, $versionId),
            'warnings' => $this->processService->draftWarnings($id, $versionId),
        ]));
    }

    /**
     * Request body (JSON):
     *   change_note — Optional description of what changed
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/admin/processes/{id}/versions/{versionId}/publish')]
    public function publish(int $id, int $versionId): DataResponse {
        if ($denied = $this->checkProcessDesigner()) {
            return $denied;
        }
        $changeNote = $this->request->getParam('change_note');

        return $this->handle(function () use ($id, $versionId, $changeNote) {
            $version = $this->processService->publish($id, $versionId, $changeNote !== null ? (string)$changeNote : null, $this->userId);
            return new DataResponse([
                'version' => $this->serializeVersion($version, false),
                'process' => $this->processResponse($id),
            ]);
        });
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/admin/processes/{id}/statistics')]
    public function statistics(int $id): DataResponse {
        if ($denied = $this->checkProcessDesigner()) {
            return $denied;
        }
        return $this->handle(function () use ($id) {
            $this->processService->getProcess($id);
            return new DataResponse(['statistics' => $this->statisticsService->forProcess($id)]);
        });
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/admin/processes/{id}/migration-plan')]
    public function migrationPlan(int $id): DataResponse {
        if ($denied = $this->checkProcessDesigner()) {
            return $denied;
        }
        return $this->handle(fn() => new DataResponse(['plan' => $this->migrationService->plan($id)]));
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/admin/processes/{id}/migrations')]
    public function migrations(int $id): DataResponse {
        if ($denied = $this->checkProcessDesigner()) {
            return $denied;
        }
        return $this->handle(fn() => new DataResponse([
            'migrations' => array_map(fn(ProcessMigration $m) => $this->serializeMigration($m), $this->migrationService->recent($id)),
        ]));
    }

    /**
     * Request body (JSON):
     *   mappings — {old element id: new element id} for steps the published
     *              version doesn't have
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/admin/processes/{id}/migrations')]
    public function startMigration(int $id): DataResponse {
        if ($denied = $this->checkProcessDesigner()) {
            return $denied;
        }
        $mappings = $this->request->getParam('mappings', []);
        return $this->handle(function () use ($id, $mappings) {
            $migration = $this->migrationService->start($id, is_array($mappings) ? $mappings : [], (string)$this->userId);
            return new DataResponse(['migration' => $this->serializeMigration($migration)], Http::STATUS_CREATED);
        });
    }

    private function serializeMigration(ProcessMigration $m): array {
        return [
            'id' => $m->getId(),
            'to_version_no' => $this->processVersionNo($m->getToVersionId()),
            'status' => $m->getStatus(),
            'total' => $m->getTotal(),
            'migrated' => $m->getMigrated(),
            'failed' => $m->getFailed(),
            'errors' => $m->getErrorList(),
            'mappings' => $m->getMappingList(),
            'created_at' => $m->getCreatedAt()?->format('c'),
            'created_by' => $m->getCreatedBy(),
            'finished_at' => $m->getFinishedAt()?->format('c'),
        ];
    }

    private function processVersionNo(int $versionId): ?int {
        try {
            return $this->processService->getVersionNo($versionId);
        } catch (NotFoundException) {
            return null;
        }
    }

    /**
     * Run $action, mapping service exceptions to error responses.
     */
    private function handle(callable $action): DataResponse {
        try {
            return $action();
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (ProcessConflictException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        } catch (\RuntimeException $e) {
            // Process engine unreachable or misconfigured
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
        }
    }

    private function processResponse(int $id): array {
        $row = $this->processService->getProcess($id);
        return $this->serializeProcess($row['def'], $row['scopes'], $row['versions']);
    }

    /**
     * @param ProcessScope[] $scopes
     * @param ProcessVersion[] $versions newest first
     */
    private function serializeProcess(ProcessDef $def, array $scopes, array $versions): array {
        $published = null;
        $draft = null;
        foreach ($versions as $v) {
            if ($v->getStatus() === ProcessVersion::STATUS_PUBLISHED) {
                $published ??= $v;
            } elseif ($v->getStatus() === ProcessVersion::STATUS_DRAFT) {
                $draft ??= $v;
            }
        }

        return [
            'id' => $def->getId(),
            'process_key' => $def->getProcessKey(),
            'name' => $def->getName(),
            'description' => $def->getDescription(),
            'active' => $def->getActive(),
            'created_at' => $def->getCreatedAt()?->format('c'),
            'created_by' => $def->getCreatedBy(),
            'updated_at' => $def->getUpdatedAt()?->format('c'),
            'updated_by' => $def->getUpdatedBy(),
            'published_version_id' => $published?->getId(),
            'published_version_no' => $published?->getVersionNo(),
            'draft_version_id' => $draft?->getId(),
            'scopes' => array_map(fn(ProcessScope $s) => [
                'scope_type' => $s->getScopeType(),
                'scope_value' => $s->getScopeValue(),
            ], $scopes),
            'versions' => array_map(fn(ProcessVersion $v) => $this->serializeVersion($v, false), $versions),
        ];
    }

    private function serializeVersion(ProcessVersion $v, bool $withXml): array {
        $data = [
            'id' => $v->getId(),
            'process_def_id' => $v->getProcessDefId(),
            'version_no' => $v->getVersionNo(),
            'status' => $v->getStatus(),
            'checksum' => $v->getChecksum(),
            'change_note' => $v->getChangeNote(),
            'flowable_deployment_id' => $v->getFlowableDeploymentId(),
            'flowable_proc_def_id' => $v->getFlowableProcDefId(),
            'created_at' => $v->getCreatedAt()?->format('c'),
            'created_by' => $v->getCreatedBy(),
            'updated_at' => $v->getUpdatedAt()?->format('c'),
            'updated_by' => $v->getUpdatedBy(),
            'published_at' => $v->getPublishedAt()?->format('c'),
            'published_by' => $v->getPublishedBy(),
        ];
        if ($withXml) {
            $data['bpmn_xml'] = $v->getBpmnXml();
        }
        return $data;
    }
}
