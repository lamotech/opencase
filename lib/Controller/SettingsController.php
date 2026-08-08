<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\ApiClientRepository;
use OCA\OpenCase\Db\UserInfoMapper;
use OCA\OpenCase\Db\UserOrgMapper;
use OCA\OpenCase\Service\AddinOriginService;
use OCA\OpenCase\Service\ApiClientPresenterService;
use OCA\OpenCase\Service\CaseExportService;
use OCA\OpenCase\Service\Certificate;
use OCA\OpenCase\Service\CaseService;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\ClassificationCsvImportService;
use OCA\OpenCase\Service\ClassificationSyncService;
use OCA\OpenCase\Service\OrganisationSyncService;
use OCA\OpenCase\Service\PrivilegeService;
use OCA\OpenCase\Service\RoleService;
use OCA\OpenCase\Service\SamlMetadataService;
use OCA\OpenCase\Service\TransactionLogService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCA\OpenCase\Service\TraceLogger;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;

/**
 * Admin-only settings endpoints called from the Nextcloud admin settings page.
 *
 * Routes (plain, not OCS):
 *   POST   /settings/config                                   — save a single config key/value
 *   POST   /settings/sync-organisations                       — trigger organisation sync
 *   POST   /settings/sync-klassifikation                      — trigger klassifikation sync
 *   POST   /settings/classification/subjects/upload           — manual CSV import of KLE classifications
 *   POST   /settings/classification/facets/upload             — manual CSV import of handling facets
 *   POST   /settings/certificate/validate                     — validate a PKCS#12 certificate
 *   GET    /settings/msoffice/{app}/manifest.xml               — download an MS Office add-in manifest
 *   GET    /settings/export/schema.xsd                         — download the case export XML schema
 *   POST   /settings/export/run                                — run the case export now (manual trigger)
 *   POST   /settings/transaction-log                          — search the transaction and audit logs
 *   GET    /settings/transaction-log/types                    — type values present in those logs
 *   POST   /settings/api-clients/{id}/active                  — activate/deactivate an API client
 *   POST   /settings/api-clients/{id}/expires                 — set an API client's expiry date
 *   POST   /settings/api-clients/{id}/delete                  — delete an API client
 *   GET    /settings/local-users/search                       — search local Nextcloud users
 *   GET    /settings/local-users/{userId}                     — fetch a local user's OpenCase user info
 *   POST   /settings/local-users/{userId}                     — create/update a local user's OpenCase user info
 *   GET    /settings/local-users/{userId}/privileges          — fetch a local user's privilege groups
 *   POST   /settings/local-users/{userId}/privileges          — add a privilege group and recompute access
 *   DELETE /settings/local-users/{userId}/privileges/{id}     — remove a privilege group and recompute access
 */
class SettingsController extends Controller {

    private const PRIVILEGE_TYPES = [
        'systemadministrator',
        'opencaseadministrator',
        'opencasetemplateadministrator',
        'opencaseuser',
        'opencasereaduser',
    ];

    private const MSOFFICE_APPS = ['outlook', 'word', 'excel', 'powerpoint'];

    public function __construct(
        string $appName,
        IRequest $request,
        private Configuration $configuration,
        private CaseExportService $caseExportService,
        private OrganisationSyncService $orgSyncService,
        private ClassificationCsvImportService $classificationCsvImportService,
        private SamlMetadataService $samlMetadataService,
        private TransactionLogService $transactionLogService,
        private ApiClientRepository $apiClientRepository,
        private ApiClientPresenterService $apiClientPresenterService,
        private UserInfoMapper $userInfoMapper,
        private UserOrgMapper $userOrgMapper,
        private PrivilegeService $privilegeService,
        private RoleService $roleService,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private IDBConnection $db,
        private TraceLogger $traceLogger,
        private IAppManager $appManager,
        private IConfig $systemConfig,
        private AddinOriginService $addinOriginService,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Download the SP SAML metadata XML file.
     */
    public function downloadMetadata(): DataDownloadResponse {
        $xml = $this->samlMetadataService->createSAMLMetadata();
        return new DataDownloadResponse($xml, 'sp-metadata.xml', 'application/xml');
    }

    /**
     * Download one of the MS Office add-in manifest.xml files
     * (msoffice/{outlook,word,excel,powerpoint}/manifest.xml).
     *
     * Read-only, no side effects — exempt from CSRF so a plain <a href>
     * click (not an axios/XHR call, which auto-attaches the token) works.
     */
    #[NoCSRFRequired]
    public function downloadMsofficeManifest(string $app): DataDownloadResponse {
        if (!in_array($app, self::MSOFFICE_APPS, true)) {
            $response = new DataDownloadResponse('', 'not-found.xml', 'text/plain');
            $response->setStatus(Http::STATUS_NOT_FOUND);
            return $response;
        }

        $path = $this->appManager->getAppPath('opencase') . '/msoffice/' . $app . '/manifest.xml';
        if (!is_file($path)) {
            $response = new DataDownloadResponse('', 'not-found.xml', 'text/plain');
            $response->setStatus(Http::STATUS_NOT_FOUND);
            return $response;
        }

        $opencaseHost = $this->addinOriginService->getOpencaseHost();
        $addinName = htmlspecialchars(
            $this->configuration->getConfigValue('msoffice_addin_name', 'OpenCase') ?: 'OpenCase',
            ENT_XML1 | ENT_QUOTES,
        );
        $xml = str_replace(
            ['ADDIN_HOST', 'OPENCASE_HOST', 'ADDIN_NAME'],
            [$this->addinOriginService->getAddinHost($opencaseHost), $opencaseHost, $addinName],
            file_get_contents($path),
        );
        $xml = preg_replace(
            '#<Id>[^<]*</Id>#',
            '<Id>' . $this->resolveManifestId($app) . '</Id>',
            $xml,
            1,
        );

        return new DataDownloadResponse($xml, $app . '-manifest.xml', 'application/xml');
    }

    /**
     * Look up this environment's stable manifest <Id> (GUID) for one of the
     * MS Office add-ins, from config key msoffice_id_{app}. A customer with
     * separate test/prod tenants needs each environment to install the add-in
     * under a distinct Id, so one is generated and persisted on first use
     * rather than baked into the manifest template.
     */
    private function resolveManifestId(string $app): string {
        $key = 'msoffice_id_' . $app;
        $id = $this->configuration->getConfigValue($key);
        if ($id === null || $id === '') {
            $id = CaseService::generateUuid();
            $this->configuration->setConfigValue($key, $id);
        }

        return $id;
    }

    /**
     * Manually run CaseExportService::exportPendingCases() — the same work
     * ExportClosedCasesJob does hourly, but triggered on demand regardless
     * of the export_enabled toggle (so an admin can test/backfill without
     * turning the automatic job on).
     */
    public function runExport(): JSONResponse {
        $result = $this->caseExportService->exportPendingCases();
        return new JSONResponse($result);
    }

    /**
     * Download the XML schema (namespace https://opencase.dk/case) that
     * ExportClosedCasesJob's case.xml / logs/*.xml output validates against.
     *
     * Read-only, no side effects — exempt from CSRF so a plain <a href>
     * click (not an axios/XHR call, which auto-attaches the token) works.
     */
    #[NoCSRFRequired]
    public function downloadExportSchema(): DataDownloadResponse {
        $path = $this->appManager->getAppPath('opencase') . '/appinfo/schema/case-export.xsd';
        if (!is_file($path)) {
            $response = new DataDownloadResponse('', 'not-found.xsd', 'text/plain');
            $response->setStatus(Http::STATUS_NOT_FOUND);
            return $response;
        }

        return new DataDownloadResponse(file_get_contents($path), 'case-export.xsd', 'application/xml');
    }

    /**
     * Save a single config value.
     *
     * Request body: { "key": "...", "value": "..." }
     */
    public function saveConfig(): JSONResponse {
        $key   = (string)($this->request->getParam('key', ''));
        $value = (string)($this->request->getParam('value', ''));

        if ($key === '') {
            return new JSONResponse(['error' => 'key is required'], Http::STATUS_BAD_REQUEST);
        }

        $this->configuration->setConfigValue($key, $value);

        return new JSONResponse(['saved' => true]);
    }

    /**
     * Trigger an organisation synchronisation from Serviceplatformen.
     *
     * Starts sync in a background process and returns immediately.
     * Response: { status: 'started' | 'running' }
     */
    public function syncOrganisations(): JSONResponse {
        $status = $this->configuration->getConfigValue('org_sync_status', 'idle');
        if ($status === 'running') {
            return new JSONResponse(['status' => 'running']);
        }

        $this->configuration->setConfigValue('org_sync_status', 'running');
        $this->configuration->setConfigValue('org_sync_result', null);

        // Under the php-fpm SAPI, PHP_BINARY points at the php-fpm binary itself,
        // not a CLI-capable interpreter, so it can't be used to run occ. PHP_BINDIR
        // is the configured bin directory (e.g. /usr/bin) shared by the cli and fpm
        // builds, where the actual php-cli executable lives.
        $phpBin = PHP_BINDIR . '/php';
        if (!is_executable($phpBin)) {
            $phpBin = PHP_BINARY;
        }
        $occ = \OC::$SERVERROOT . '/occ';
        // setsid detaches the child from the PHP-FPM worker's process group, so it
        // isn't killed once this request finishes and can run to completion.
        $cmd = 'setsid ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($occ) . ' opencase:sync-org < /dev/null > /dev/null 2>&1 &';

        $this->traceLogger->trace('org_sync_spawn', ['cmd' => $cmd, 'sapi' => PHP_SAPI, 'php_binary' => PHP_BINARY]);
        exec($cmd, $execOutput, $execReturnCode);
        $this->traceLogger->trace('org_sync_spawn_result', ['exec_return_code' => $execReturnCode]);

        return new JSONResponse(['status' => 'started']);
    }

    /**
     * Return the current organisation sync status.
     *
     * Response: { status: 'running' | 'idle', result: {...} | null, error: string | null }
     */
    public function orgSyncStatus(): JSONResponse {
        $status = $this->configuration->getConfigValue('org_sync_status', 'idle');
        $resultJson = $this->configuration->getConfigValue('org_sync_result', null);
        $result = $resultJson !== null ? json_decode($resultJson, true) : null;
        $error  = $this->configuration->getConfigValue('org_sync_error', null);

        return new JSONResponse([
            'status' => $status,
            'result' => $result,
            'error'  => $error,
        ]);
    }

    /**
     * Trigger a klassifikation synchronisation from Serviceplatformen.
     *
     * ClassificationSyncService is an Enterprise-only component (not
     * present in the public App Store package) — guard against the class
     * simply not being present on disk, rather than injecting it directly
     * in the constructor (which would break every route on this controller
     * if the class doesn't exist).
     *
     * Response: { fetched, created, updated, deactivated }
     */
    public function syncKlassifikation(): JSONResponse {
        $classificationSyncAvailable = $this->configuration->getConfigValue('enterprise_version', '0') === '1'
            && class_exists(ClassificationSyncService::class);

        if (!$classificationSyncAvailable) {
            return new JSONResponse(
                ['error' => 'Klassifikationssynkronisering er en Enterprise-funktion og er ikke aktiveret på denne instans.'],
                Http::STATUS_NOT_IMPLEMENTED,
            );
        }

        try {
            $classificationSyncService = \OC::$server->get(ClassificationSyncService::class);
            $result = $classificationSyncService->sync();
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }

    /**
     * Manually import KLE classifications (Emneplan) from an uploaded CSV
     * file — the enterprise_version=0 fallback for classification sync,
     * since ClassificationSyncService (Datafordeler) isn't available then.
     *
     * Expects multipart/form-data with: file — the CSV file
     * (';'-delimited, header row "uuid;code;title;description;active").
     *
     * Response: { fetched, created, updated, deactivated }
     */
    public function uploadClassificationSubjects(): JSONResponse {
        return $this->handleClassificationCsvUpload(fn (string $content) => $this->classificationCsvImportService->importSubjects($content));
    }

    /**
     * Manually import handling facets (Handlingsfacetter) from an uploaded
     * CSV file — same fallback as uploadClassificationSubjects(), for the
     * facet classification instead.
     *
     * Response: { fetched, created, updated, deactivated }
     */
    public function uploadClassificationFacets(): JSONResponse {
        return $this->handleClassificationCsvUpload(fn (string $content) => $this->classificationCsvImportService->importFacets($content));
    }

    private function handleClassificationCsvUpload(callable $import): JSONResponse {
        $file = $this->request->getUploadedFile('file');

        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            $errorMsg = match ($errorCode) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds maximum upload size',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                default => 'File upload failed',
            };
            return new JSONResponse(['error' => $errorMsg], Http::STATUS_BAD_REQUEST);
        }

        $content = file_get_contents($file['tmp_name']);
        if ($content === false) {
            return new JSONResponse(['error' => 'Failed to read uploaded file'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        try {
            return new JSONResponse($import($content));
        } catch (\Throwable $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }

    /**
     * Validate a PKCS#12 certificate file.
     *
     * Request body: { "filepath": "...", "password": "..." }
     * Response (success): { valid: true, subject, serialNumber, expiresAt }
     * Response (failure): { valid: false, error }
     */
    public function validateCertificate(): JSONResponse {
        $filepath = (string)($this->request->getParam('filepath', ''));
        $password = (string)($this->request->getParam('password', ''));

        return new JSONResponse(Certificate::parseCertificateFile($filepath, $password));
    }

    /**
     * Search the transaction log — both the search/lookup transactions and
     * the case/document audit trail.
     *
     * Request body: { "username": "...", "from": "YYYY-MM-DD HH:MM:SS", "to": "...",
     *                 "type": "...", "source": "transaction"|"audit"|"" }
     * Response: array of log rows
     */
    public function searchTransactionLog(): JSONResponse {
        $username = (string)($this->request->getParam('username', ''));
        $from     = (string)($this->request->getParam('from', ''));
        $to       = (string)($this->request->getParam('to', ''));
        $type     = (string)($this->request->getParam('type', ''));
        $source   = (string)($this->request->getParam('source', ''));

        $rows = $this->transactionLogService->search(
            $username !== '' ? $username : null,
            $from     !== '' ? $from     : null,
            $to       !== '' ? $to       : null,
            $type     !== '' ? $type     : null,
            $source   !== '' ? $source   : null,
        );

        // Resolve each user id once — a busy log repeats the same handful.
        $displayNames = [];
        foreach ($rows as &$row) {
            $userId = $row['user_id'] ?? '';
            if (!array_key_exists($userId, $displayNames)) {
                $user = $userId !== '' ? $this->userManager->get($userId) : null;
                $displayNames[$userId] = $user ? $user->getDisplayName() : $userId;
            }
            $row['display_name'] = $displayNames[$userId];
        }
        unset($row);

        return new JSONResponse($rows);
    }

    /**
     * The transaction/event types present in the two logs, for the admin
     * filter dropdown.
     *
     * Response: { transaction: string[], audit: string[] }
     */
    public function transactionLogTypes(): JSONResponse {
        return new JSONResponse($this->transactionLogService->availableTypes());
    }

    /**
     * Activate or deactivate a registered API client.
     *
     * Request body: { "active": true|false }
     * Response: the updated list of API clients
     */
    public function setApiClientActive(int $id): JSONResponse {
        $active = (bool)$this->request->getParam('active', false);
        $this->apiClientRepository->setActive($id, $active);

        return new JSONResponse(['clients' => $this->apiClientPresenterService->enrich($this->apiClientRepository->findAll())]);
    }

    /**
     * Set (or clear) the expiry date of a registered API client.
     *
     * Request body: { "expires_at": "YYYY-MM-DD" | "" }
     * Response: the updated list of API clients
     */
    public function setApiClientExpires(int $id): JSONResponse {
        $expiresAt = (string)$this->request->getParam('expires_at', '');

        $date = null;
        if ($expiresAt !== '') {
            $date = \DateTimeImmutable::createFromFormat('Y-m-d', $expiresAt);
            if ($date === false) {
                return new JSONResponse(['error' => 'Invalid date format, expected YYYY-MM-DD'], Http::STATUS_BAD_REQUEST);
            }
        }

        $this->apiClientRepository->setExpiresAt($id, $date);

        return new JSONResponse(['clients' => $this->apiClientPresenterService->enrich($this->apiClientRepository->findAll())]);
    }

    /**
     * Delete a registered API client.
     *
     * Response: the updated list of API clients
     */
    public function deleteApiClient(int $id): JSONResponse {
        $this->apiClientRepository->delete($id);

        return new JSONResponse(['clients' => $this->apiClientPresenterService->enrich($this->apiClientRepository->findAll())]);
    }

    /**
     * Search local Nextcloud users (excludes Serviceplatformen-synced "opencase_*" accounts).
     *
     * Query param: ?q=...
     * Response: { users: [{ uid, displayname }] }
     */
    public function searchLocalUsers(): JSONResponse {
        $query = trim((string)$this->request->getParam('q', ''));

        $qb = $this->db->getQueryBuilder();
        $qb->select(['u.uid', 'u.displayname', 'p.configvalue AS pref_displayname'])
            ->from('users', 'u')
            ->leftJoin('u', 'preferences', 'p', $qb->expr()->andX(
                $qb->expr()->eq('p.userid', 'u.uid'),
                $qb->expr()->eq('p.appid', $qb->createNamedParameter('settings')),
                $qb->expr()->eq('p.configkey', $qb->createNamedParameter('displayname'))
            ))
            ->orderBy('u.uid', 'ASC')
            ->setMaxResults(200);

        if ($query !== '') {
            $like = '%' . $this->db->escapeLikeParameter($query) . '%';
            $qb->where($qb->expr()->orX(
                $qb->expr()->iLike('u.uid', $qb->createNamedParameter($like)),
                $qb->expr()->iLike('u.displayname', $qb->createNamedParameter($like)),
                $qb->expr()->iLike('p.configvalue', $qb->createNamedParameter($like))
            ));
        }

        $result = $qb->executeQuery();
        $users = [];
        while ($row = $result->fetch()) {
            $uid = (string)$row['uid'];
            if (str_starts_with($uid, 'opencase_')) {
                continue;
            }
            $displayName = $row['pref_displayname'] ?: ($row['displayname'] ?: $uid);
            $users[] = ['uid' => $uid, 'displayname' => $displayName];
            if (count($users) >= 50) {
                break;
            }
        }
        $result->closeCursor();

        return new JSONResponse(['users' => $users]);
    }

    /**
     * Fetch a local user's OpenCase user info, defaulting unset fields from the Nextcloud account.
     *
     * Response: { uid, displayname, userinfo: { uuid, username, personname, email, phone, location } }
     */
    public function getLocalUserInfo(string $userId): JSONResponse {
        $user = $this->userManager->get($userId);
        if ($user === null || str_starts_with($userId, 'opencase_')) {
            return new JSONResponse(['error' => 'User not found'], Http::STATUS_NOT_FOUND);
        }

        $info = $this->userInfoMapper->findByUserId($userId);

        return new JSONResponse([
            'uid'         => $userId,
            'displayname' => $user->getDisplayName(),
            'userinfo'    => [
                'uuid'       => $info?->getUuid() ?? '',
                'username'   => $info?->getUsername() ?? $userId,
                'personname' => $info?->getPersonname() ?? $user->getDisplayName(),
                'email'      => $info?->getEmail() ?? ($user->getEMailAddress() ?? ''),
                'phone'      => $info?->getPhone() ?? '',
                'location'   => $info?->getLocation() ?? '',
            ],
        ]);
    }

    /**
     * Create or update a local user's OpenCase user info.
     *
     * Request body: { "personname": "...", "email": "...", "phone": "...", "location": "..." }
     * Response: the saved user info record
     */
    public function saveLocalUserInfo(string $userId): JSONResponse {
        $user = $this->userManager->get($userId);
        if ($user === null || str_starts_with($userId, 'opencase_')) {
            return new JSONResponse(['error' => 'User not found'], Http::STATUS_NOT_FOUND);
        }

        $personname = (string)$this->request->getParam('personname', '');
        $email      = (string)$this->request->getParam('email', '');
        $phone      = (string)$this->request->getParam('phone', '');
        $location   = (string)$this->request->getParam('location', '');

        $existing = $this->userInfoMapper->findByUserId($userId);
        $uuid = $existing?->getUuid() ?? CaseService::generateUuid();

        $this->userInfoMapper->upsert($uuid, $userId, $personname, $email, $phone, $location, $userId);

        $info = $this->userInfoMapper->findByUuid($uuid);

        return new JSONResponse([
            'uuid'       => $info->getUuid(),
            'username'   => $info->getUsername(),
            'personname' => $info->getPersonname(),
            'email'      => $info->getEmail(),
            'phone'      => $info->getPhone(),
            'location'   => $info->getLocation(),
        ]);
    }

    /**
     * Fetch a local user's privilege groups, with foelsomhed/orgenhed UUIDs
     * resolved to display titles.
     *
     * Response: { privileges: [{ id, privilege_type, foelsomhed: [{uuid,title}], kle: string[], orgenhed: [{uuid,title}] }] }
     */
    public function getLocalUserPrivileges(string $userId): JSONResponse {
        if ($this->userManager->get($userId) === null || str_starts_with($userId, 'opencase_')) {
            return new JSONResponse(['error' => 'User not found'], Http::STATUS_NOT_FOUND);
        }

        $groups = $this->readPrivilegeGroups($userId);

        $allSensUuids = [];
        $allOrgUuids  = [];
        foreach ($groups as $group) {
            array_push($allSensUuids, ...$group['foelsomhed']);
            array_push($allOrgUuids, ...$group['orgenhed']);
        }

        $sensMap = $this->resolveTitles('opencase_sensitivity', 'uuid', 'title', array_unique($allSensUuids));
        $orgMap  = $this->resolveTitles('opencase_org', 'org_uuid', 'org_name', array_unique($allOrgUuids));

        $privileges = array_map(fn($group) => [
            'id'             => $group['id'],
            'privilege_type' => $group['privilege_type'],
            'foelsomhed'     => array_map(fn($u) => ['uuid' => $u, 'title' => $sensMap[$u] ?? $u], $group['foelsomhed']),
            'kle'            => $group['kle'],
            'orgenhed'       => array_map(fn($u) => ['uuid' => $u, 'title' => $orgMap[$u] ?? $u], $group['orgenhed']),
        ], $groups);

        return new JSONResponse(['privileges' => $privileges]);
    }

    /**
     * Add a privilege group to a local user and recompute their access,
     * using the same PrivilegeService logic applied on SAML login.
     *
     * Request body: { "privilege_type": "...", "foelsomhed": string[], "kle": string[], "orgenhed": string[] }
     * Response: same shape as getLocalUserPrivileges()
     */
    public function addLocalUserPrivilege(string $userId): JSONResponse {
        if ($this->userManager->get($userId) === null || str_starts_with($userId, 'opencase_')) {
            return new JSONResponse(['error' => 'User not found'], Http::STATUS_NOT_FOUND);
        }

        $privilegeType = (string)$this->request->getParam('privilege_type', '');
        if (!in_array($privilegeType, self::PRIVILEGE_TYPES, true)) {
            return new JSONResponse(['error' => 'Invalid privilege_type'], Http::STATUS_BAD_REQUEST);
        }

        $foelsomhed = array_values(array_filter((array)$this->request->getParam('foelsomhed', [])));
        $kle        = array_values(array_filter((array)$this->request->getParam('kle', [])));
        $orgenhed   = array_values(array_filter((array)$this->request->getParam('orgenhed', [])));

        $hasConstraints = in_array($privilegeType, ['opencaseuser', 'opencasereaduser'], true);
        if ($hasConstraints) {
            if (empty($foelsomhed) || empty($kle)) {
                return new JSONResponse(['error' => 'Følsomhed og KLE skal angives for denne rettighedstype'], Http::STATUS_BAD_REQUEST);
            }
        } else {
            // Administrator roles are unconstrained — ignore any constraint fields sent for them
            $foelsomhed = [];
            $kle        = [];
            $orgenhed   = [];
        }

        $groups   = $this->readPrivilegeGroups($userId);
        $groups[] = [
            'privilege_type' => $privilegeType,
            'foelsomhed'     => $foelsomhed,
            'kle'            => $kle,
            'orgenhed'       => $orgenhed,
        ];

        $this->privilegeService->syncPrivileges($userId, $groups);
        $this->roleService->syncRolesFromPrivilegeGroups($userId, $groups);
        $this->syncOrganisationMemberships($userId, $groups);

        return $this->getLocalUserPrivileges($userId);
    }

    /**
     * Remove a privilege group from a local user and recompute their access.
     *
     * Response: same shape as getLocalUserPrivileges()
     */
    public function deleteLocalUserPrivilege(string $userId, int $id): JSONResponse {
        if ($this->userManager->get($userId) === null || str_starts_with($userId, 'opencase_')) {
            return new JSONResponse(['error' => 'User not found'], Http::STATUS_NOT_FOUND);
        }

        $qb = $this->db->getQueryBuilder();
        $qb->delete('opencase_user_priv_groups')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();

        $groups = $this->readPrivilegeGroups($userId);
        $this->privilegeService->syncPrivileges($userId, $groups);
        $this->roleService->syncRolesFromPrivilegeGroups($userId, $groups);
        $this->syncOrganisationMemberships($userId, $groups);

        return $this->getLocalUserPrivileges($userId);
    }

    /**
     * Sync a local user's org_<uuid> group membership (grants group-folder access)
     * and oc_opencase_userorgs rows from their privilege groups' "orgenhed"
     * constraints — mirrors what SamlController::acs() does on every SAML login.
     *
     * @param array<int, array{privilege_type:string, foelsomhed:string[], kle:string[], orgenhed:string[]}> $groups
     */
    private function syncOrganisationMemberships(string $userId, array $groups): void {
        $grantedOrgUuids = [];
        foreach ($groups as $group) {
            if (!in_array($group['privilege_type'], ['opencaseuser', 'opencasereaduser'], true)) {
                continue;
            }
            foreach ($group['orgenhed'] as $orgUuid) {
                if (!in_array($orgUuid, $grantedOrgUuids, true)) {
                    $grantedOrgUuids[] = $orgUuid;
                }
            }
        }

        $user = $this->userManager->get($userId);
        if ($user === null) {
            return;
        }

        // Sync Nextcloud org_<uuid> group membership — this is what actually
        // grants access to each organisation's group folder.
        $grantedGroupIds = array_map(fn(string $uuid) => 'org_' . $uuid, $grantedOrgUuids);
        foreach ($this->groupManager->getUserGroups($user) as $group) {
            $groupId = $group->getGID();
            if (str_starts_with($groupId, 'org_') && !in_array($groupId, $grantedGroupIds, true)) {
                $group->removeUser($user);
            }
        }
        foreach ($grantedGroupIds as $groupId) {
            $group = $this->groupManager->get($groupId) ?? $this->groupManager->createGroup($groupId);
            if ($group !== null && !$group->inGroup($user)) {
                $group->addUser($user);
            }
        }

        // Ensure the user has an opencase_userinfo row (required as the join key
        // for opencase_userorgs), then sync their organisation membership rows.
        $userInfo = $this->userInfoMapper->findByUserId($userId);
        if ($userInfo === null) {
            $uuid = CaseService::generateUuid();
            $this->userInfoMapper->upsert($uuid, $userId, $user->getDisplayName(), $user->getEMailAddress() ?? '', '', '', $userId);
        } else {
            $uuid = $userInfo->getUuid();
        }

        $organisations = array_map(fn(string $orgUuid) => (object)['orgUuid' => $orgUuid, 'role' => 'Sagsbehandler'], $grantedOrgUuids);
        $this->userOrgMapper->syncForUser($uuid, $organisations);
    }

    /**
     * Read raw privilege groups for a user from opencase_user_priv_groups.
     *
     * @return array<int, array{id:int, privilege_type:string, foelsomhed:string[], kle:string[], orgenhed:string[]}>
     */
    private function readPrivilegeGroups(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(['id', 'privilege_type', 'foelsomhed_raw', 'kle_raw', 'orgenhed_raw'])
            ->from('opencase_user_priv_groups')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('id', 'ASC');

        $result = $qb->executeQuery();
        $groups = [];
        while ($row = $result->fetch()) {
            $groups[] = [
                'id'             => (int)$row['id'],
                'privilege_type' => $row['privilege_type'],
                'foelsomhed'     => $this->splitRaw($row['foelsomhed_raw']),
                'kle'            => $this->splitRaw($row['kle_raw']),
                'orgenhed'       => $this->splitRaw($row['orgenhed_raw']),
            ];
        }
        $result->closeCursor();

        return $groups;
    }

    /**
     * @return string[]
     */
    private function splitRaw(?string $raw): array {
        if ($raw === null || $raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * @param string[] $ids
     * @return array<string,string>
     */
    private function resolveTitles(string $table, string $idColumn, string $titleColumn, array $ids): array {
        $ids = array_values(array_filter($ids));
        if (empty($ids)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select([$idColumn, $titleColumn])
            ->from($table)
            ->where($qb->expr()->in($idColumn, $qb->createNamedParameter($ids, IQueryBuilder::PARAM_STR_ARRAY)));

        $result = $qb->executeQuery();
        $map = [];
        while ($row = $result->fetch()) {
            $map[$row[$idColumn]] = $row[$titleColumn];
        }
        $result->closeCursor();

        return $map;
    }
}
