<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\CaseStatusMapper;
use OCA\OpenCase\Db\CaseTypeMapper;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\FavoriteMapper;
use OCA\OpenCase\Db\HistoryMapper;
use OCA\OpenCase\Db\OrganisationMapper;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

class WidgetController extends ApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private CaseMapper $caseMapper,
        private CaseStatusMapper $caseStatusMapper,
        private CaseTypeMapper $caseTypeMapper,
        private OrganisationMapper $organisationMapper,
        private FavoriteMapper $favoriteMapper,
        private DocumentMapper $documentMapper,
        private HistoryMapper $historyMapper,
        private IConfig $config,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Return cases where the current user is responsible and status is not closed.
     * Supports limit/offset for pagination (widget uses limit=8).
     */
    #[NoAdminRequired]
    public function myCases(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['cases' => [], 'total' => 0]);
        }

        $limit  = min((int)($this->request->getParam('limit', 8)), 200);
        $offset = max((int)($this->request->getParam('offset', 0)), 0);

        $cases = $this->caseMapper->findResponsibleNotClosed($this->userId, $limit, $offset);
        $total = $this->caseMapper->countResponsibleNotClosed($this->userId);

        $orgUuids  = array_unique(array_map(fn($c) => $c->getOrgUuid(), $cases));
        $orgNames  = $this->organisationMapper->getNamesByUuids($orgUuids);

        $statusIds   = array_unique(array_map(fn($c) => $c->getStatusId(), $cases));
        $statusNames = $this->caseStatusMapper->getNamesByIds($statusIds, $this->userLanguage());

        $casetypeIds   = array_unique(array_filter(array_map(fn($c) => $c->getCasetypeId(), $cases)));
        $casetypeNames = $this->caseTypeMapper->getNamesByIds($casetypeIds, $this->userLanguage());

        $data = array_map(function ($c) use ($orgNames, $statusNames, $casetypeNames) {
            $sid        = $c->getStatusId();
            $statusInfo = $statusNames[$sid] ?? ['name' => '', 'is_closed' => false];
            $ctId       = $c->getCasetypeId();
            return [
                'id'             => $c->getId(),
                'case_number'    => $c->getCaseNumber(),
                'title'          => $c->getTitle(),
                'organisation'   => $orgNames[$c->getOrgUuid()] ?? '',
                'status_id'      => $sid,
                'status_name'    => $statusInfo['name'],
                'status_class'   => $this->statusCssClass($sid),
                'casetype_id'    => $ctId,
                'casetype_name'  => $ctId ? ($casetypeNames[$ctId] ?? '') : '',
                'updated_at'     => $c->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }, $cases);

        return new DataResponse([
            'cases'  => $data,
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Return the current user's favorites (cases + documents) sorted newest first.
     */
    #[NoAdminRequired]
    public function favorites(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['items' => []]);
        }

        $limit     = min((int)($this->request->getParam('limit', 8)), 200);
        $favs      = $this->favoriteMapper->findByUser($this->userId);
        $items     = [];

        foreach ($favs as $fav) {
            if (count($items) >= $limit) {
                break;
            }

            if ($fav->getEntity() === 'case') {
                $case = $this->caseMapper->findById((int)$fav->getEntityKey());
                if ($case === null) {
                    continue;
                }
                $items[] = [
                    'entity'        => 'case',
                    'id'            => $case->getId(),
                    'title'         => $case->getTitle(),
                    'subtitle'      => $case->getCaseNumber(),
                    'added_at'      => $fav->getAddedAt()?->format(\DateTimeInterface::ATOM),
                ];
            } elseif ($fav->getEntity() === 'document') {
                $doc = $this->documentMapper->findById((int)$fav->getEntityKey());
                if ($doc === null) {
                    continue;
                }
                $items[] = [
                    'entity'        => 'document',
                    'id'            => $doc->getId(),
                    'title'         => $doc->getTitle(),
                    'subtitle'      => $doc->getDocumentNumber(),
                    'added_at'      => $fav->getAddedAt()?->format(\DateTimeInterface::ATOM),
                ];
            }
        }

        return new DataResponse(['items' => $items]);
    }

    /**
     * Return the current user's recently accessed cases and documents,
     * newest first.
     */
    #[NoAdminRequired]
    public function recent(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['items' => []]);
        }

        $limit   = min((int)($this->request->getParam('limit', 8)), 200);
        $history = $this->historyMapper->findByUser($this->userId);
        $items   = [];

        foreach ($history as $entry) {
            if (count($items) >= $limit) {
                break;
            }

            if ($entry->getEntity() === 'case') {
                $case = $this->caseMapper->findById($entry->getEntityKey());
                if ($case === null) {
                    continue;
                }
                $items[] = [
                    'entity'      => 'case',
                    'id'          => $case->getId(),
                    'title'       => $case->getTitle(),
                    'subtitle'    => $case->getCaseNumber(),
                    'accessed_at' => $entry->getAccessedAt()?->format(\DateTimeInterface::ATOM),
                ];
            } elseif ($entry->getEntity() === 'document') {
                $doc = $this->documentMapper->findById($entry->getEntityKey());
                if ($doc === null) {
                    continue;
                }
                $items[] = [
                    'entity'      => 'document',
                    'id'          => $doc->getId(),
                    'title'       => $doc->getTitle(),
                    'subtitle'    => $doc->getDocumentNumber(),
                    'accessed_at' => $entry->getAccessedAt()?->format(\DateTimeInterface::ATOM),
                ];
            }
        }

        return new DataResponse(['items' => $items]);
    }

    private function userLanguage(): string {
        $lang = $this->config->getUserValue($this->userId ?? '', 'core', 'lang', 'en');
        return substr($lang, 0, 2) ?: 'en';
    }

    private function statusCssClass(int $statusId): string {
        return match ($statusId) {
            1 => 'open',
            2 => 'closed',
            3 => 'archived',
            default => 'unknown',
        };
    }
}
