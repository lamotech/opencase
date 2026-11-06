<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\Process;

use OCA\OpenCase\Service\Configuration;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * REST client for the Flowable process engine (process-engine/). The engine
 * is only reachable from the server: browsers never call it, so case access
 * is enforced by OpenCase before anything is sent here.
 *
 * Config: feature_process-engine ('1' = on), process_engine_url,
 * process_engine_user, process_engine_password.
 */
class FlowableClient {

    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        private Configuration $configuration,
        private IClientService $http,
        private LoggerInterface $logger,
    ) {
    }

    public function isFeatureEnabled(): bool {
        return $this->configuration->getConfigValue('feature_process-engine', '0') === '1';
    }

    public function isConfigured(): bool {
        return $this->isFeatureEnabled() && $this->serviceUrl() !== '';
    }

    /**
     * Check that the engine is reachable and accepts the credentials.
     *
     * @return array{name: string, version: string}
     * @throws \RuntimeException with a message for the administrator
     */
    public function checkEngine(): array {
        $engine = $this->request('GET', '/process-api/management/engine');
        return ['name' => (string)($engine['name'] ?? ''), 'version' => (string)($engine['version'] ?? '')];
    }

    /**
     * Deploy one BPMN file and return the ids of the deployment and of the
     * process definition it created.
     *
     * @return array{deployment_id: string, process_definition_id: string, flowable_version: int}
     * @throws \InvalidArgumentException when Flowable rejects the BPMN
     * @throws \RuntimeException when the engine can't be reached
     */
    public function deploy(string $deploymentKey, string $deploymentName, string $bpmnXml): array {
        $deployment = $this->request('POST', '/process-api/repository/deployments', [
            'query' => ['deploymentKey' => $deploymentKey, 'deploymentName' => $deploymentName],
            'multipart' => [[
                'name' => 'file',
                'contents' => $bpmnXml,
                // Flowable only parses resources ending in .bpmn20.xml or .bpmn
                'filename' => $deploymentKey . '.bpmn20.xml',
                'headers' => ['Content-Type' => 'application/xml'],
            ]],
        ], 'Procesmotoren afviste processen: ');
        $deploymentId = (string)($deployment['id'] ?? '');
        if ($deploymentId === '') {
            throw new \RuntimeException('Procesmotoren returnerede ikke et deployment-id.');
        }

        $definitions = $this->request('GET', '/process-api/repository/process-definitions', [
            'query' => ['deploymentId' => $deploymentId],
        ]);
        $definition = $definitions['data'][0] ?? null;
        if ($definition === null) {
            throw new \InvalidArgumentException('Procesmotoren fandt ingen eksekverbar proces i BPMN-filen.');
        }

        return [
            'deployment_id' => $deploymentId,
            'process_definition_id' => (string)$definition['id'],
            'flowable_version' => (int)($definition['version'] ?? 0),
        ];
    }

    /**
     * Start an instance of a deployed process definition.
     *
     * @param array<string, string|int|bool|null> $variables process variables; ints are sent as long
     * @return string the process instance id
     */
    public function startProcess(string $processDefinitionId, string $businessKey, array $variables): string {
        $instance = $this->request('POST', '/process-api/runtime/process-instances', [
            'json' => [
                'processDefinitionId' => $processDefinitionId,
                'businessKey' => $businessKey,
                'variables' => $this->variablesPayload($variables),
            ],
        ]);
        $id = (string)($instance['id'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('Procesmotoren returnerede ikke et proces-id.');
        }
        return $id;
    }

    /**
     * Whether the instance is still running (Flowable removes ended
     * instances from the runtime API).
     */
    public function isRunning(string $instanceId): bool {
        return $this->request('GET', '/process-api/runtime/process-instances/' . rawurlencode($instanceId), [], nullOn404: true) !== null;
    }

    /**
     * @return array{end_time: ?string, delete_reason: ?string}|null null if unknown to the engine
     */
    public function getHistoricInstance(string $instanceId): ?array {
        $instance = $this->request('GET', '/process-api/history/historic-process-instances/' . rawurlencode($instanceId), [], nullOn404: true);
        if ($instance === null) {
            return null;
        }
        return ['end_time' => $instance['endTime'] ?? null, 'delete_reason' => $instance['deleteReason'] ?? null];
    }

    /**
     * Cancel a running instance. Its open tasks end with $reason as delete reason.
     */
    public function cancelProcess(string $instanceId, string $reason): void {
        $this->request('DELETE', '/process-api/runtime/process-instances/' . rawurlencode($instanceId), [
            'query' => ['deleteReason' => $reason],
        ], nullOn404: true);
    }

    /**
     * Element ids the instance is currently at.
     *
     * @return string[]
     */
    public function getActiveActivityIds(string $instanceId): array {
        $executions = $this->request('GET', '/process-api/runtime/executions', [
            'query' => ['processInstanceId' => $instanceId, 'size' => 1000],
        ]);
        $ids = [];
        foreach ($executions['data'] ?? [] as $execution) {
            if (!empty($execution['activityId'])) {
                $ids[] = (string)$execution['activityId'];
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Element ids (activities and sequence flows) the instance has passed.
     *
     * @return string[]
     */
    public function getCompletedActivityIds(string $instanceId): array {
        $activities = $this->request('GET', '/process-api/history/historic-activity-instances', [
            'query' => ['processInstanceId' => $instanceId, 'finished' => 'true', 'size' => 1000],
        ]);
        $ids = [];
        foreach ($activities['data'] ?? [] as $activity) {
            if (!empty($activity['activityId'])) {
                $ids[] = (string)$activity['activityId'];
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Open user tasks of an instance.
     *
     * @return array{id: string, activity_id: string, name: ?string, assignee: ?string, due_date: ?string, created_at: ?string}[]
     */
    public function getTasks(string $instanceId): array {
        $tasks = $this->request('GET', '/process-api/runtime/tasks', [
            'query' => ['processInstanceId' => $instanceId, 'size' => 1000],
        ]);
        return array_map(fn(array $t) => [
            'id' => (string)$t['id'],
            'activity_id' => (string)($t['taskDefinitionKey'] ?? ''),
            'name' => $t['name'] ?? null,
            'assignee' => ($t['assignee'] ?? '') !== '' ? (string)$t['assignee'] : null,
            'due_date' => $t['dueDate'] ?? null,
            'created_at' => $t['createTime'] ?? null,
        ], $tasks['data'] ?? []);
    }

    /**
     * @return array{end_time: ?string, delete_reason: ?string, assignee: ?string}|null
     */
    public function getHistoricTask(string $taskId): ?array {
        $tasks = $this->request('GET', '/process-api/history/historic-task-instances', [
            'query' => ['taskId' => $taskId],
        ]);
        $task = $tasks['data'][0] ?? null;
        if ($task === null) {
            return null;
        }
        return [
            'end_time' => $task['endTime'] ?? null,
            'delete_reason' => $task['deleteReason'] ?? null,
            'assignee' => $task['assignee'] ?? null,
        ];
    }

    /**
     * Candidate groups of a task, e.g. ["org:<uuid>"].
     *
     * @return string[]
     */
    public function getCandidateGroups(string $taskId): array {
        $links = $this->request('GET', '/process-api/runtime/tasks/' . rawurlencode($taskId) . '/identitylinks');
        $groups = [];
        foreach ($links ?? [] as $link) {
            if (($link['type'] ?? '') === 'candidate' && !empty($link['group'])) {
                $groups[] = (string)$link['group'];
            }
        }
        return $groups;
    }

    /**
     * All form properties of a task.
     *
     * @return array{id: string, name: string, type: string, required: bool, options: array{id: string, name: string}[]}[]
     */
    public function getFormProperties(string $taskId): array {
        $form = $this->request('GET', '/process-api/form/form-data', ['query' => ['taskId' => $taskId]]);
        return array_map(fn(array $p) => [
            'id' => (string)$p['id'],
            'name' => (string)($p['name'] ?? $p['id']),
            'type' => (string)($p['type'] ?? 'string'),
            'required' => (bool)($p['required'] ?? false),
            'options' => array_map(fn(array $v) => [
                'id' => (string)$v['id'],
                'name' => (string)($v['name'] ?? $v['id']),
            ], $p['enumValues'] ?? []),
        ], $form['formProperties'] ?? []);
    }

    /**
     * Problems that would stop the instance from being moved to another
     * process definition; empty when it can be moved.
     *
     * @param array<string, string> $activityMappings from element id => to element id
     * @return string[]
     */
    public function validateMigration(string $instanceId, string $toProcessDefinitionId, array $activityMappings): array {
        $result = $this->request('POST', '/process-api/runtime/process-instances/' . rawurlencode($instanceId) . '/validate-migration', [
            'json' => $this->migrationDocument($toProcessDefinitionId, $activityMappings),
        ]);
        return array_map('strval', $result['validationMessages'] ?? []);
    }

    /**
     * Move a running instance to another process definition. Open tasks on
     * steps that exist in both keep their id; mapped steps get new tasks.
     *
     * @param array<string, string> $activityMappings from element id => to element id
     */
    public function migrateInstance(string $instanceId, string $toProcessDefinitionId, array $activityMappings): void {
        $this->request('POST', '/process-api/runtime/process-instances/' . rawurlencode($instanceId) . '/migrate', [
            'json' => $this->migrationDocument($toProcessDefinitionId, $activityMappings),
        ], 'Processen kunne ikke flyttes: ');
    }

    private function migrationDocument(string $toProcessDefinitionId, array $activityMappings): array {
        $document = ['toProcessDefinitionId' => $toProcessDefinitionId];
        foreach ($activityMappings as $from => $to) {
            $document['activityMappings'][] = ['fromActivityId' => (string)$from, 'toActivityId' => (string)$to];
        }
        return $document;
    }

    /**
     * Assign the task to $userId, or release it with null.
     */
    public function claimTask(string $taskId, ?string $userId): void {
        $this->request('POST', '/process-api/runtime/tasks/' . rawurlencode($taskId), [
            'json' => ['action' => 'claim', 'assignee' => $userId],
        ]);
    }

    /**
     * Complete a task. With form properties, they are validated by the
     * engine (e.g. an enum value must be one of the choices) and stored as
     * process variables.
     *
     * @param array<string, string> $formProperties
     */
    public function completeTask(string $taskId, array $formProperties): void {
        if ($formProperties === []) {
            $this->request('POST', '/process-api/runtime/tasks/' . rawurlencode($taskId), [
                'json' => ['action' => 'complete'],
            ]);
            return;
        }
        $properties = [];
        foreach ($formProperties as $id => $value) {
            $properties[] = ['id' => $id, 'value' => $value];
        }
        $this->request('POST', '/process-api/form/form-data', [
            'json' => ['taskId' => $taskId, 'properties' => $properties],
        ]);
    }

    /**
     * Lock up to $max jobs of a topic for this worker.
     *
     * @return array{id: string, element_id: string, element_name: ?string, process_instance_id: string, process_definition_id: string, retries: int, created_at: ?string}[]
     */
    public function acquireJobs(string $topic, int $max, string $workerId, string $lockDuration): array {
        $jobs = $this->request('POST', '/external-job-api/acquire/jobs', [
            'json' => [
                'topic' => $topic,
                'lockDuration' => $lockDuration,
                'numberOfTasks' => $max,
                'workerId' => $workerId,
            ],
        ]);
        return array_map(fn(array $j) => [
            'id' => (string)$j['id'],
            'element_id' => (string)($j['elementId'] ?? ''),
            'element_name' => $j['elementName'] ?? null,
            'process_instance_id' => (string)($j['processInstanceId'] ?? ''),
            'process_definition_id' => (string)($j['processDefinitionId'] ?? ''),
            'retries' => (int)($j['retries'] ?? 0),
            'created_at' => $j['createTime'] ?? null,
        ], $jobs ?? []);
    }

    /**
     * A variable of a running process instance.
     *
     * @return array{type: string, value: mixed}|null null when the process has no such variable
     */
    public function getVariable(string $instanceId, string $name): ?array {
        $variable = $this->request('GET', '/process-api/runtime/process-instances/' . rawurlencode($instanceId) . '/variables/' . rawurlencode($name), [], nullOn404: true);
        return $variable !== null ? ['type' => (string)($variable['type'] ?? 'string'), 'value' => $variable['value'] ?? null] : null;
    }

    /**
     * Set (or create) a variable on a running process instance.
     */
    public function setVariable(string $instanceId, string $name, string|int|bool|null $value): void {
        $this->request('PUT', '/process-api/runtime/process-instances/' . rawurlencode($instanceId) . '/variables/' . rawurlencode($name), [
            'json' => $this->variablesPayload([$name => $value])[0],
        ], nullOn404: true);
    }

    /**
     * Report a job done, so the process continues.
     *
     * @param array<string, string|int|bool|null> $variables set on the process
     * @return bool false if the engine no longer has the job (already completed)
     */
    public function completeJob(string $jobId, string $workerId, array $variables): bool {
        return $this->request('POST', '/external-job-api/acquire/jobs/' . rawurlencode($jobId) . '/complete', [
            'json' => ['workerId' => $workerId, 'variables' => $this->variablesPayload($variables)],
        ], nullOn404: true) !== null;
    }

    /**
     * Report a job failed. With $retries 0 it becomes a dead-letter job and
     * the process waits until it is moved back (moveDeadLetterJob).
     */
    public function failJob(string $jobId, string $workerId, string $message, int $retries, string $retryTimeout): void {
        $this->request('POST', '/external-job-api/acquire/jobs/' . rawurlencode($jobId) . '/fail', [
            'json' => [
                'workerId' => $workerId,
                'errorMessage' => mb_substr($message, 0, 255),
                'errorDetails' => $message,
                'retries' => max(0, $retries),
                'retryTimeout' => $retryTimeout,
            ],
        ], nullOn404: true);
    }

    /**
     * Steps of the instance that failed for good.
     *
     * @return array{id: string, message: ?string, element_id: ?string}[]
     */
    public function getDeadLetterJobs(string $instanceId): array {
        $jobs = $this->request('GET', '/process-api/management/deadletter-jobs', [
            'query' => ['processInstanceId' => $instanceId],
        ]);
        return array_map(fn(array $j) => [
            'id' => (string)$j['id'],
            'message' => $j['exceptionMessage'] ?? null,
            'element_id' => $j['elementId'] ?? null,
        ], $jobs['data'] ?? []);
    }

    /**
     * Make a dead-letter job runnable again (with its retries reset).
     */
    public function moveDeadLetterJob(string $jobId): void {
        $this->request('POST', '/process-api/management/deadletter-jobs/' . rawurlencode($jobId), [
            'json' => ['action' => 'move'],
        ], nullOn404: true);
    }

    /**
     * @param array<string, string|int|bool|null> $variables
     */
    private function variablesPayload(array $variables): array {
        $payload = [];
        foreach ($variables as $name => $value) {
            $type = match (true) {
                is_int($value) => 'long',
                is_bool($value) => 'boolean',
                default => 'string',
            };
            $payload[] = ['name' => $name, 'type' => $type, 'value' => $value];
        }
        return $payload;
    }

    private function serviceUrl(): string {
        return rtrim(trim((string)$this->configuration->getConfigValue('process_engine_url', '')), '/');
    }

    /**
     * Send a request and return the decoded JSON body, turning failures into
     * exceptions with a message for the user.
     *
     * @param string $rejectedPrefix message prefix when Flowable rejects the request (400/500 with a reason)
     * @param bool $nullOn404 return null instead of throwing when the resource doesn't exist
     * @throws \InvalidArgumentException when Flowable rejects the request
     * @throws ProcessConflictException on 409, e.g. a task already claimed by someone else
     * @throws \RuntimeException when the engine can't be reached or fails
     */
    private function request(
        string $method,
        string $path,
        array $options = [],
        string $rejectedPrefix = 'Procesmotoren afviste handlingen: ',
        bool $nullOn404 = false,
    ): ?array {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Procesmotoren er ikke sat op.');
        }

        $options += [
            'auth' => [
                (string)$this->configuration->getConfigValue('process_engine_user', 'opencase'),
                (string)$this->configuration->getConfigValue('process_engine_password', ''),
            ],
            'headers' => ['Accept' => 'application/json'],
            'timeout' => self::TIMEOUT_SECONDS,
            // The engine usually runs on the same host or private network
            'nextcloud' => ['allow_local_address' => true],
        ];

        try {
            $client = $this->http->newClient();
            $url = $this->serviceUrl() . $path;
            $response = match ($method) {
                'POST' => $client->post($url, $options),
                'PUT' => $client->put($url, $options),
                'DELETE' => $client->delete($url, $options),
                default => $client->get($url, $options),
            };
        } catch (\Throwable $e) {
            $errorResponse = method_exists($e, 'getResponse') ? $e->getResponse() : null;
            $status = $errorResponse?->getStatusCode();
            if ($status === 404 && $nullOn404) {
                return null;
            }
            $this->logger->error('Process engine call failed: ' . $e->getMessage(), ['exception' => $e]);
            // Flowable answers BPMN parse and validation errors with 400 or
            // 500, and puts the reason in "exception"
            $body = $errorResponse !== null ? json_decode((string)$errorResponse->getBody(), true) : null;
            $reason = is_array($body) ? trim((string)($body['exception'] ?? '')) : '';
            if ($status === 409) {
                throw new ProcessConflictException('Opgaven er allerede taget af en anden.', 0, $e);
            }
            if (($status === 400 || $status === 500) && $reason !== '') {
                // "javax...TransformerException: ...: org.xml.sax.SAXParseException; lineNumber: ..." → the last part
                $pos = strrpos($reason, 'Exception: ');
                if ($pos !== false) {
                    $reason = substr($reason, $pos + strlen('Exception: '));
                }
                throw new \InvalidArgumentException($rejectedPrefix . $reason, 0, $e);
            }
            $message = match ($status) {
                401 => 'Procesmotoren afviste brugernavn eller adgangskode. Kontrollér process_engine_user og process_engine_password.',
                null => 'Procesmotoren kunne ikke kontaktes. Kontrollér process_engine_url og at tjenesten kører.',
                default => 'Procesmotoren returnerede en fejl (HTTP ' . $status . ').',
            };
            throw new \RuntimeException($message, 0, $e);
        }

        $decoded = json_decode((string)$response->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
