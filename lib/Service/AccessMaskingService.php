<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\AccessMaskRule;
use OCA\OpenCase\Db\AccessMaskRuleMapper;
use OCA\OpenCase\Db\AccessMaskSuggestion;
use OCA\OpenCase\Db\AccessMaskSuggestionMapper;
use OCA\OpenCase\Db\AccessRequestItem;
use OCA\OpenCase\Db\FileMapper;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Automatic masking of access-request files via the external redaction
 * service (redaction-service/ in this repository). PDFs are masked directly;
 * office files are converted to PDF by the service, so their masked version
 * is a PDF.
 *
 * Flow: analyze() asks the service for personal data in a file and stores the
 * findings as suggestions; the caseworker accepts/rejects them (or adds own
 * search terms with addTerm()); apply() sends the accepted areas back to the
 * service and stores the returned PDF — with the text actually removed — as
 * the file's masked version, the same place a manually uploaded one goes.
 *
 * The feature is optional and Enterprise only: without
 * feature_redaction-service=1 (set by the Enterprise install script) and a
 * configured service URL isConfigured() is false and the UI only
 * offers manual upload.
 */
class AccessMaskingService {

    public const STATUSES = ['suggested', 'accepted', 'rejected'];

    /** File types the service can analyze; office files are converted to PDF by the service. */
    private const SUPPORTED_MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.oasis.opendocument.text',
        'application/rtf',
        'text/rtf',
        'text/plain',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.oasis.opendocument.spreadsheet',
        'text/csv',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.presentation',
    ];

    private const TIMEOUT_SECONDS = 120;

    public const RULE_KINDS      = ['regex', 'terms'];
    public const RULE_VALIDATORS = ['cpr_date', 'not_date'];

    public function __construct(
        private AccessMaskSuggestionMapper $suggestionMapper,
        private AccessMaskRuleMapper $ruleMapper,
        private FileMapper $fileMapper,
        private FileService $fileService,
        private Configuration $configuration,
        private IClientService $http,
        private IConfig $config,
        private AuditService $auditService,
        private LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool {
        return $this->isFeatureEnabled() && $this->serviceUrl() !== '';
    }

    public function isFeatureEnabled(): bool {
        return $this->configuration->getConfigValue('feature_redaction-service', '0') === '1';
    }

    public function supportsMimeType(?string $mimeType): bool {
        return in_array($mimeType, self::SUPPORTED_MIME_TYPES, true);
    }

    /**
     * Download name and MIME type for a masked file. Automatic masking always
     * produces a PDF, also for office originals, while a manually uploaded
     * masked file keeps the original's type.
     *
     * @return array{0: string, 1: string} [filename, mimeType]
     */
    public static function maskedDownloadInfo(string $maskedPath, string $originalName, ?string $originalMime): array {
        $head = @file_get_contents($maskedPath, false, null, 0, 5);
        if ($head === '%PDF-' && $originalMime !== 'application/pdf') {
            return [pathinfo($originalName, PATHINFO_FILENAME) . '.pdf', 'application/pdf'];
        }
        return [$originalName, $originalMime ?? 'application/octet-stream'];
    }

    /**
     * Run the rule detectors on a file, replacing earlier rule suggestions.
     * New findings start out accepted (pre-selected); the caseworker deselects
     * false positives. A finding that was already there (same page, category
     * and text) keeps the status the caseworker gave it, so re-analysing never
     * undoes their review. Manual suggestions (own search terms) are kept.
     *
     * @return array{suggestions: array, pages: int, pages_without_text: int}
     */
    public function analyze(AccessRequestItem $item, int $fileId, string $userId): array {
        $rules  = $this->ruleMapper->findEnabled();
        $result = $this->callAnalyze($fileId, [], $rules);

        $rulesById = [];
        foreach ($rules as $r) {
            $rulesById[$r->getId()] = $r;
        }

        $previous = [];
        foreach ($this->suggestionMapper->findByItemFile($item->getId(), $fileId) as $s) {
            if ($s->getSource() === 'rule') {
                $previous[$this->findingKey($s->getPage(), $s->getCategory(), $s->getText())] = $s->getStatus();
            }
        }

        $this->suggestionMapper->deleteByItemFileAndSource($item->getId(), $fileId, 'rule');
        $this->storeFindings($item->getId(), $fileId, $result['findings'] ?? [], 'rule', 'accepted', $userId, $previous, $rulesById);

        return [
            'suggestions'        => $this->listSuggestions($item->getId(), $fileId),
            'pages'              => (int) ($result['pages'] ?? 0),
            'pages_without_text' => (int) ($result['pages_without_text'] ?? 0),
        ];
    }

    /**
     * Search the file for a caseworker-supplied term (e.g. a name) and add
     * every hit as an accepted suggestion.
     *
     * @return int Number of pages the term was found on
     */
    public function addTerm(AccessRequestItem $item, int $fileId, string $term, string $userId): int {
        $term = trim($term);
        if ($term === '') {
            throw new \InvalidArgumentException('Term must not be empty');
        }

        $result   = $this->callAnalyze($fileId, [$term]);
        $findings = $result['findings'] ?? [];
        $this->storeFindings($item->getId(), $fileId, $findings, 'manual', 'accepted', $userId);

        return count($findings);
    }

    public function listSuggestions(int $itemId, int $fileId): array {
        return array_map(
            fn (AccessMaskSuggestion $s) => $this->serialize($s),
            $this->suggestionMapper->findByItemFile($itemId, $fileId),
        );
    }

    public function setStatus(AccessMaskSuggestion $s, string $status): AccessMaskSuggestion {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid status '{$status}'");
        }
        $s->setStatus($status);
        $s->setUpdatedAt(new \DateTime());
        return $this->suggestionMapper->update($s);
    }

    /**
     * Burn the accepted suggestions into the file and store the result as its
     * masked version.
     *
     * @return int Number of masked areas
     */
    public function apply(AccessRequestItem $item, int $fileId, int $caseId, string $userId): int {
        $accepted = array_filter(
            $this->suggestionMapper->findByItemFile($item->getId(), $fileId),
            fn (AccessMaskSuggestion $s) => $s->getStatus() === 'accepted',
        );

        if ($accepted === []) {
            throw new \InvalidArgumentException('No accepted suggestions to apply');
        }

        $areas      = [];
        $areaCount  = 0;
        $categories = [];
        foreach ($accepted as $s) {
            $rects = json_decode($s->getRects(), true) ?: [];
            $areas[] = ['page' => $s->getPage(), 'rects' => $rects];
            $areaCount += count($rects);
            $categories[$s->getCategory()] = ($categories[$s->getCategory()] ?? 0) + count($rects);
        }

        $file = $this->fileMapper->findById($fileId);
        if ($file === null) {
            throw new \RuntimeException("File {$fileId} not found");
        }

        $pdf = $this->post('/redact', $fileId, ['areas' => json_encode($areas)]);

        $dest = $this->maskedPath($item->getRequestId(), $item->getId(), $fileId, $file->getOriginalFilename());
        $dir  = dirname($dest);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        if (file_put_contents($dest, $pdf) === false) {
            throw new \RuntimeException('Could not write masked file');
        }

        $this->auditService->logAccessRequestMasked(
            $caseId, $userId, $item->getRequestId(), $fileId, $file->getOriginalFilename(), $areaCount, $categories,
        );

        return $areaCount;
    }

    public function serialize(AccessMaskSuggestion $s): array {
        return [
            'id'       => $s->getId(),
            'item_id'  => $s->getRequestItemId(),
            'file_id'  => $s->getFileId(),
            'page'     => $s->getPage(),
            'category' => $s->getCategory(),
            'label'    => $s->getLabel(),
            'legal_reference' => $s->getLegalReference(),
            'text'     => $s->getText(),
            'context'  => $s->getContext(),
            'count'    => count(json_decode($s->getRects(), true) ?: []),
            'source'   => $s->getSource(),
            'status'   => $s->getStatus(),
        ];
    }

    // ── Rules (Konfiguration → Maskeringsregler) ─────────────────────────

    public function listRules(): array {
        return array_map(fn (AccessMaskRule $r) => $this->serializeRule($r), $this->ruleMapper->findAll());
    }

    /**
     * Create ($rule null) or update a rule. The pattern is checked by the
     * service when it is configured, so a broken rule can't be saved and then
     * fail every analysis.
     *
     * @param array{label?: string, kind?: string, pattern?: string, validator?: ?string,
     *              legal_reference?: ?string, enabled?: bool, sort_order?: int} $data
     */
    public function saveRule(?AccessMaskRule $rule, array $data, string $userId): AccessMaskRule {
        $isNew = $rule === null;
        $rule ??= new AccessMaskRule();

        $label   = trim((string) ($data['label'] ?? $rule->getLabel()));
        $kind    = (string) ($data['kind'] ?? $rule->getKind());
        $pattern = (string) ($data['pattern'] ?? $rule->getPattern());
        $validator = array_key_exists('validator', $data) ? ($data['validator'] ?: null) : $rule->getValidator();
        $legal   = array_key_exists('legal_reference', $data)
            ? (trim((string) $data['legal_reference']) ?: null)
            : $rule->getLegalReference();

        if ($label === '') {
            throw new \InvalidArgumentException('Navn skal udfyldes.');
        }
        if (!in_array($kind, self::RULE_KINDS, true)) {
            throw new \InvalidArgumentException("Ukendt regeltype '{$kind}'.");
        }
        if ($kind === 'terms') {
            $validator = null;
            $pattern   = implode("\n", array_values(array_filter(array_map('trim', explode("\n", $pattern)), 'strlen')));
        }
        if (trim($pattern) === '') {
            throw new \InvalidArgumentException($kind === 'terms' ? 'Ordlisten er tom.' : 'Mønsteret skal udfyldes.');
        }
        if ($validator !== null && !in_array($validator, self::RULE_VALIDATORS, true)) {
            throw new \InvalidArgumentException("Ukendt kontrol '{$validator}'.");
        }

        $rule->setLabel(mb_substr($label, 0, 255));
        $rule->setKind($kind);
        $rule->setPattern($pattern);
        $rule->setValidator($validator);
        $rule->setLegalReference($legal !== null ? mb_substr($legal, 0, 255) : null);
        if (array_key_exists('enabled', $data)) {
            $rule->setEnabled((bool) $data['enabled']);
        }
        if (array_key_exists('sort_order', $data)) {
            $rule->setSortOrder((int) $data['sort_order']);
        } elseif ($isNew) {
            $max = array_reduce($this->ruleMapper->findAll(), fn (int $m, AccessMaskRule $r) => max($m, $r->getSortOrder()), 0);
            $rule->setSortOrder($max + 10);
        }
        $rule->setUpdatedAt(new \DateTime());
        $rule->setUpdatedBy($userId);

        if ($this->isConfigured()) {
            try {
                $this->detect('', [$rule]);
            } catch (\InvalidArgumentException $e) {
                throw $e;
            } catch (\RuntimeException) {
                // Service unreachable: save anyway, the rule is checked on use
            }
        }

        return $isNew ? $this->ruleMapper->insert($rule) : $this->ruleMapper->update($rule);
    }

    /**
     * Check that the service is reachable and accepts the API key.
     *
     * @throws \RuntimeException with a message for the administrator
     */
    public function checkService(): void {
        $this->detect('', []);
    }

    public function deleteRule(AccessMaskRule $rule): void {
        $this->ruleMapper->delete($rule);
    }

    /**
     * Run rules on a piece of text, e.g. to test a rule before saving it.
     *
     * @param AccessMaskRule[] $rules
     * @return array<int, array{rule_id: mixed, category: string, text: string}>
     */
    public function detect(string $text, array $rules): array {
        $body = $this->send('/detect', ['json' => [
            'text'  => $text,
            'rules' => array_map(fn (AccessMaskRule $r) => $this->rulePayload($r), $rules),
        ]]);
        return json_decode($body, true)['matches'] ?? [];
    }

    /**
     * A rule built from request data without saving it, for testing.
     */
    public function draftRule(array $data): AccessMaskRule {
        $rule = new AccessMaskRule();
        $rule->setLabel((string) ($data['label'] ?? ''));
        $rule->setKind((string) ($data['kind'] ?? 'regex'));
        $rule->setPattern((string) ($data['pattern'] ?? ''));
        $rule->setValidator(($data['validator'] ?? null) ?: null);
        return $rule;
    }

    public function serializeRule(AccessMaskRule $r): array {
        return [
            'id'              => $r->getId(),
            'label'           => $r->getLabel(),
            'category'        => $r->getCategory(),
            'kind'            => $r->getKind(),
            'pattern'         => $r->getPattern(),
            'validator'       => $r->getValidator(),
            'legal_reference' => $r->getLegalReference(),
            'enabled'         => $r->getEnabled(),
            'sort_order'      => $r->getSortOrder(),
            'updated_at'      => $r->getUpdatedAt()?->format('c'),
            'updated_by'      => $r->getUpdatedBy(),
        ];
    }

    private function rulePayload(AccessMaskRule $r): array {
        return [
            'id'        => $r->getId(),
            'category'  => $r->getCategory(),
            'kind'      => $r->getKind(),
            'pattern'   => $r->getPattern(),
            'validator' => $r->getValidator(),
        ];
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function findingKey(int $page, string $category, string $text): string {
        return $page . "\0" . $category . "\0" . $text;
    }

    /**
     * @param array<string, string> $previousStatus Status per findingKey() to carry over
     * @param array<int, AccessMaskRule> $rulesById Rules the findings' rule_id refers to
     */
    private function storeFindings(
        int $itemId, int $fileId, array $findings, string $source, string $status, string $userId,
        array $previousStatus = [], array $rulesById = [],
    ): void {
        $now = new \DateTime();
        foreach ($findings as $f) {
            $rule = $rulesById[(int) ($f['rule_id'] ?? 0)] ?? null;
            $key = $this->findingKey((int) ($f['page'] ?? 0), (string) ($f['category'] ?? 'other'), (string) ($f['text'] ?? ''));
            $s = new AccessMaskSuggestion();
            $s->setRequestItemId($itemId);
            $s->setFileId($fileId);
            $s->setPage((int) ($f['page'] ?? 0));
            $s->setCategory(mb_substr((string) ($f['category'] ?? 'other'), 0, 32));
            $s->setText((string) ($f['text'] ?? ''));
            $s->setContext(isset($f['context']) ? (string) $f['context'] : null);
            $s->setRects(json_encode($f['rects'] ?? []));
            $s->setLabel($rule?->getLabel() ?? ($source === 'manual' ? 'Egen tekst' : null));
            $s->setLegalReference($rule?->getLegalReference());
            $s->setSource($source);
            $s->setStatus($previousStatus[$key] ?? $status);
            $s->setCreatedBy($userId);
            $s->setCreatedAt($now);
            $this->suggestionMapper->insert($s);
        }
    }

    /**
     * @param AccessMaskRule[]|null $rules Rules to run; null = only search for $terms
     */
    private function callAnalyze(int $fileId, array $terms, ?array $rules = null): array {
        $fields = ['terms' => json_encode(array_values($terms))];
        if ($rules !== null) {
            $fields['rules'] = json_encode(array_map(fn (AccessMaskRule $r) => $this->rulePayload($r), $rules));
        }
        $body = $this->post('/analyze', $fileId, $fields);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid response from redaction service');
        }
        return $data;
    }

    /**
     * POST the file plus form fields to the service and return the raw body.
     */
    private function post(string $path, int $fileId, array $fields): string {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Maskeringstjenesten er ikke sat op.');
        }

        $download = $this->fileService->downloadInternal($fileId);
        $entity   = $download['entity'];
        if (!$this->supportsMimeType($entity->getMimeType())) {
            fclose($download['stream']);
            throw new \InvalidArgumentException('This file type cannot be masked automatically');
        }

        $multipart = [[
            'name'     => 'file',
            'contents' => $download['stream'],
            'filename' => $entity->getOriginalFilename(),
        ]];
        foreach ($fields as $name => $value) {
            $multipart[] = ['name' => $name, 'contents' => $value];
        }

        return $this->send($path, ['multipart' => $multipart]);
    }

    /**
     * Send a request to the service and return the raw body, turning
     * failures into a RuntimeException with a message for the user.
     */
    private function send(string $path, array $options): string {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Maskeringstjenesten er ikke sat op.');
        }
        $url = $this->serviceUrl();

        $headers = [];
        $apiKey  = (string) $this->configuration->getConfigValue('redaction_service_api_key', '');
        if ($apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        try {
            $response = $this->http->newClient()->post($url . $path, $options + [
                'headers'   => $headers,
                'timeout'   => self::TIMEOUT_SECONDS,
                // The service usually runs on the same host or private network
                'nextcloud' => ['allow_local_address' => true],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Redaction service call failed: ' . $e->getMessage(), ['exception' => $e]);
            $errorResponse = method_exists($e, 'getResponse') ? $e->getResponse() : null;
            $status = $errorResponse?->getStatusCode();
            if ($status === 400) {
                $detail = json_decode((string) $errorResponse->getBody(), true)['detail'] ?? null;
                $reason = is_array($detail) ? ($detail['message'] ?? '') : (string) $detail;
                throw new \InvalidArgumentException('En maskeringsregel er ugyldig: ' . $reason, 0, $e);
            }
            $message = match ($status) {
                401     => 'Maskeringstjenesten afviste API-nøglen. Kontrollér at redaction_service_api_key svarer til tjenestens REDACTION_API_KEY.',
                413     => 'Filen er for stor til maskeringstjenesten.',
                415     => 'Filtypen understøttes ikke af maskeringstjenesten.',
                422     => 'Maskeringstjenesten kunne ikke læse filen (beskadiget, beskyttet eller kunne ikke konverteres til PDF).',
                504     => 'Konvertering til PDF tog for lang tid.',
                null    => 'Maskeringstjenesten kunne ikke kontaktes. Kontrollér redaction_service_url og at tjenesten kører.',
                default => 'Maskeringstjenesten returnerede en fejl (HTTP ' . $status . ').',
            };
            throw new \RuntimeException($message, 0, $e);
        }

        return (string) $response->getBody();
    }

    private function serviceUrl(): string {
        return rtrim(trim((string) $this->configuration->getConfigValue('redaction_service_url', '')), '/');
    }

    public function maskedPath(int $requestId, int $itemId, int $fileId, string $filename): string {
        $safe    = preg_replace('/[^a-zA-Z0-9æøåÆØÅ._\-]/', '_', basename($filename));
        $dataDir = rtrim($this->config->getSystemValueString('datadirectory', ''), '/');
        return "{$dataDir}/opencase_storage/_accessrequests/{$requestId}/{$itemId}_{$fileId}_{$safe}";
    }
}
