<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Service\CodeListService;
use OCA\OpenCase\Service\RoleService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Code-list (Værdilister) API accessible to OpenCase Administrators.
 *
 * Routes:
 *   GET  /api/v1/codelists/{list}      — list entries (merged da/en)
 *   POST /api/v1/codelists/{list}      — create one or more new entries
 *   PUT  /api/v1/codelists/{list}/{id} — update an existing entry
 */
class CodeListController extends OCSController {

    public function __construct(
        string $appName,
        IRequest $request,
        private ?string $userId,
        private CodeListService $codeListService,
        private RoleService $roleService,
    ) {
        parent::__construct($appName, $request);
    }

    private function checkAdministrator(): ?DataResponse {
        if ($this->userId === null || !$this->roleService->userHasRole($this->userId, 'Administrator')) {
            return new DataResponse(
                ['error' => 'OpenCase Administrator role required.'],
                Http::STATUS_FORBIDDEN,
            );
        }
        return null;
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/codelists/{list}')]
    public function index(string $list): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }
        if (!$this->codeListService->isValidList($list)) {
            return new DataResponse(['error' => 'Unknown code list.'], Http::STATUS_NOT_FOUND);
        }

        return new DataResponse(['entries' => $this->codeListService->getList($list)]);
    }

    /**
     * Create one or more new entries.
     *
     * Request body: either a single row { da, en, expired } or
     * { rows: [{ da, en, expired }, ...] } to add several at once.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/codelists/{list}')]
    public function create(string $list): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }
        if (!$this->codeListService->isValidList($list)) {
            return new DataResponse(['error' => 'Unknown code list.'], Http::STATUS_NOT_FOUND);
        }

        $rows = $this->request->getParam('rows');
        if (!is_array($rows)) {
            $rows = [[
                'da'                  => $this->request->getParam('da', ''),
                'en'                  => $this->request->getParam('en', ''),
                'expired'             => $this->request->getParam('expired', false),
                'primary_participant' => $this->request->getParam('primary_participant'),
            ]];
        }

        $entries = [];
        foreach ($rows as $row) {
            $da = (string)($row['da'] ?? '');
            $en = (string)($row['en'] ?? '');
            if (trim($da) === '' && trim($en) === '') {
                continue;
            }
            $expired = filter_var($row['expired'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $primaryParticipant = isset($row['primary_participant']) ? (string)$row['primary_participant'] : null;
            $entries[] = $this->codeListService->create($list, $da, $en, $expired, (string)$this->userId, $primaryParticipant);
        }

        if (empty($entries)) {
            return new DataResponse(['error' => 'At least one row with a da or en value is required.'], Http::STATUS_BAD_REQUEST);
        }

        return new DataResponse(['entries' => $entries]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/codelists/{list}/{id}')]
    public function update(string $list, int $id): DataResponse {
        if ($denied = $this->checkAdministrator()) {
            return $denied;
        }
        if (!$this->codeListService->isValidList($list)) {
            return new DataResponse(['error' => 'Unknown code list.'], Http::STATUS_NOT_FOUND);
        }

        $da = (string)$this->request->getParam('da', '');
        $en = (string)$this->request->getParam('en', '');
        if (trim($da) === '' && trim($en) === '') {
            return new DataResponse(['error' => 'At least one of da or en is required.'], Http::STATUS_BAD_REQUEST);
        }
        $expired = filter_var($this->request->getParam('expired', false), FILTER_VALIDATE_BOOLEAN);
        $primaryParticipantRaw = $this->request->getParam('primary_participant');
        $primaryParticipant = $primaryParticipantRaw !== null ? (string)$primaryParticipantRaw : null;

        $entry = $this->codeListService->update($list, $id, $da, $en, $expired, (string)$this->userId, $primaryParticipant);
        return new DataResponse(['entry' => $entry]);
    }
}
