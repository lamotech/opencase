<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller\PublicApi;

use OCA\OpenCase\Db\CaseStatusMapper;
use OCA\OpenCase\Db\CaseTypeMapper;
use OCA\OpenCase\Db\ClassificationFacetRepository;
use OCA\OpenCase\Db\ClassificationSubjectMapper;
use OCA\OpenCase\Db\ContactRoleMapper;
use OCA\OpenCase\Db\DocumentCategoryMapper;
use OCA\OpenCase\Db\DocumentStatusMapper;
use OCA\OpenCase\Db\InsightLevelMapper;
use OCA\OpenCase\Db\ParticipantRoleMapper;
use OCA\OpenCase\Db\SensitivityMapper;
use OCA\OpenCase\Db\UserRoleMapper;
use OCA\OpenCase\Service\CaseService;
use OCA\OpenCase\Service\TemplateService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Public reference-data REST API: code lists and other lookup values used
 * elsewhere in the case/document API (e.g. StatusId, DocumentCategoryId).
 *
 * All endpoints accept an optional ?lang= query parameter (default 'da',
 * falling back to 'en') where the underlying lookup table is
 * language-specific, and only return active (non-expired) entries.
 *
 * See AbstractPublicDataApiController for auth and response-format handling.
 */
class PublicReferenceApiController extends AbstractPublicDataApiController {

    private const DEFAULT_LANGUAGE = 'da';
    private const FALLBACK_LANGUAGE = 'en';

    public function __construct(
        string $appName,
        IRequest $request,
        private CaseStatusMapper $caseStatusMapper,
        private CaseTypeMapper $caseTypeMapper,
        private ContactRoleMapper $contactRoleMapper,
        private DocumentCategoryMapper $documentCategoryMapper,
        private DocumentStatusMapper $documentStatusMapper,
        private InsightLevelMapper $insightLevelMapper,
        private ParticipantRoleMapper $participantRoleMapper,
        private ClassificationSubjectMapper $classificationSubjectMapper,
        private ClassificationFacetRepository $classificationFacetRepository,
        private SensitivityMapper $sensitivityMapper,
        private UserRoleMapper $userRoleMapper,
        private IUserManager $userManager,
        private CaseService $caseService,
        private TemplateService $templateService,
        IUserSession $userSession,
    ) {
        parent::__construct($appName, $request, $userSession);
    }

    // ── Code lists ───────────────────────────────────────────────────────────

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function caseStatuses(): Response {
        $items = array_map(fn ($s) => [
            'Id'       => $s->getId(),
            'Name'     => $s->getName(),
            'IsClosed' => (bool)$s->getIsClosed(),
            'Expired'  => (bool)$s->getExpired(),
        ], $this->caseStatusMapper->findAllForLanguage($this->language(), self::FALLBACK_LANGUAGE, true));

        return $this->respondList('CaseStatuses', 'CaseStatus', $items);
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function caseTypes(): Response {
        $items = array_map(fn ($t) => [
            'Id'                 => $t->getId(),
            'Name'               => $t->getName(),
            'PrimaryParticipant' => $t->getPrimaryParticipant(),
            'Expired'            => (bool)$t->getExpired(),
        ], $this->caseTypeMapper->findAllForLanguage($this->language(), self::FALLBACK_LANGUAGE, true));

        return $this->respondList('CaseTypes', 'CaseType', $items);
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function contactRoles(): Response {
        $items = array_map(fn (array $r) => [
            'Id'      => $r['id'],
            'Name'    => $r['name'],
            'Expired' => $r['expired'],
        ], $this->rowsWithFallback(fn ($lang) => $this->contactRoleMapper->findByLanguage($lang, true)));

        return $this->respondList('ContactRoles', 'ContactRole', $items);
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function documentCategories(): Response {
        $items = array_map(fn (array $c) => [
            'Id'      => $c['id'],
            'Name'    => $c['name'],
            'Expired' => $c['expired'],
        ], $this->rowsWithFallback(fn ($lang) => $this->documentCategoryMapper->findByLanguage($lang, true)));

        return $this->respondList('DocumentCategories', 'DocumentCategory', $items);
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function documentStatuses(): Response {
        $items = array_map(fn (array $s) => [
            'Id'      => $s['id'],
            'Name'    => $s['name'],
            'IsFinal' => $s['is_final'],
            'Expired' => $s['expired'],
        ], $this->rowsWithFallback(fn ($lang) => $this->documentStatusMapper->findByLanguage($lang, true)));

        return $this->respondList('DocumentStatuses', 'DocumentStatus', $items);
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function insightLevels(): Response {
        $items = array_map(fn ($l) => [
            'Id'          => $l->getId(),
            'Name'        => $l->getName(),
            'Description' => $l->getDescription(),
        ], $this->insightLevelMapper->findAllForLanguage($this->language(), self::FALLBACK_LANGUAGE, true));

        return $this->respondList('InsightLevels', 'InsightLevel', $items);
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function participantRoles(): Response {
        $items = array_map(fn (array $r) => [
            'Id'      => $r['id'],
            'Name'    => $r['name'],
            'Expired' => $r['expired'],
        ], $this->rowsWithFallback(fn ($lang) => $this->participantRoleMapper->findByLanguage($lang, true)));

        return $this->respondList('ParticipantRoles', 'ParticipantRole', $items);
    }

    // ── Other reference values ──────────────────────────────────────────────

    /**
     * Organisations accessible to the API client's mapped user (matches the
     * scoping of the internal CaseController::organisations() endpoint).
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function organisations(): Response {
        $userId = $this->actingUserId();
        $orgs   = $userId !== null ? $this->caseService->getAccessibleOrganisationsWithUuid($userId) : [];

        $items = array_map(fn (array $org) => [
            'Uuid' => $org['org_uuid'],
            'Name' => $org['org_name'],
        ], $orgs);

        return $this->respondList('Organisations', 'Organisation', $items);
    }

    /**
     * KLE numbers (classification subjects / "emneord").
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function kleNumbers(): Response {
        $items = array_map(fn ($s) => [
            'Uuid'  => $s->getUuid(),
            'Code'  => $s->getCode(),
            'Title' => $s->getTitle(),
        ], $this->classificationSubjectMapper->findAllActive());

        return $this->respondList('KleNumbers', 'KleNumber', $items);
    }

    /**
     * Handlingsfacetter (classification facets).
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function classificationFacets(): Response {
        $items = array_map(fn (array $f) => [
            'Uuid'  => $f['uuid'],
            'Code'  => $f['code'],
            'Title' => $f['title'],
        ], $this->classificationFacetRepository->findAllActive());

        return $this->respondList('ClassificationFacets', 'ClassificationFacet', $items);
    }

    /**
     * Sensitivity levels. The Key is what POST .../cases expects as the
     * "sensitivity" field when creating a case.
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function sensitivities(): Response {
        $items = array_map(fn ($s) => [
            'Uuid'  => $s->getUuid(),
            'Key'   => $s->getKey(),
            'Title' => $s->getTitle(),
        ], $this->sensitivityMapper->findAllActive());

        return $this->respondList('Sensitivities', 'Sensitivity', $items);
    }

    /**
     * Nextcloud users that have an OpenCase role assigned (i.e. real
     * caseworkers/staff — matches the scope used by the internal
     * caseworker-assignment search).
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function users(): Response {
        $items = [];
        foreach ($this->userRoleMapper->findAllDistinctUserIds() as $userId) {
            $user = $this->userManager->get($userId);
            if ($user === null) {
                continue;
            }
            $items[] = [
                'Id'          => $userId,
                'DisplayName' => $user->getDisplayName(),
            ];
        }

        usort($items, fn ($a, $b) => $a['DisplayName'] <=> $b['DisplayName']);

        return $this->respondList('Users', 'User', $items);
    }

    /**
     * Document templates available for merge/creation.
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function templates(): Response {
        $items = array_map(fn ($t) => [
            'Id'               => $t->getId(),
            'Name'             => $t->getName(),
            'OriginalFilename' => $t->getOriginalFilename(),
            'MimeType'         => $t->getMimeType(),
            'Size'             => $t->getSize(),
            'UploadedBy'       => $t->getUploadedBy(),
            'CreatedAt'        => $t->getCreatedAt()?->format('c'),
        ], $this->templateService->list());

        return $this->respondList('Templates', 'Template', $items);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function language(): string {
        $lang = trim((string)$this->request->getParam('lang', self::DEFAULT_LANGUAGE));
        return $lang !== '' ? $lang : self::DEFAULT_LANGUAGE;
    }

    /**
     * Run $finder for the requested language; if it comes back empty and the
     * requested language wasn't already the fallback, retry with the
     * fallback language.
     */
    private function rowsWithFallback(callable $finder): array {
        $lang = $this->language();
        $rows = $finder($lang);
        if (empty($rows) && $lang !== self::FALLBACK_LANGUAGE) {
            $rows = $finder(self::FALLBACK_LANGUAGE);
        }
        return $rows;
    }
}
