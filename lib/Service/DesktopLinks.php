<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\CaseEntity;
use OCA\OpenCase\Db\FileEntity;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;

/**
 * URLs and small shared pieces of the desktop API (DesktopApiController,
 * DesktopTemplateApiController).
 */
class DesktopLinks {

    /** Editor clients supported for editorUrl generation. */
    public const CLIENTS = ['eurooffice'];

    public function __construct(
        private IURLGenerator $urlGenerator,
        private IUserManager $userManager,
        private FileService $fileService,
        private IConfig $config,
    ) {
    }

    public function isSupportedClient(string $client): bool {
        return in_array($client, self::CLIENTS, true);
    }

    /**
     * URL that opens a file in the given editor client.
     *
     * @param string $filePath path in the user's files, e.g. "/.Sager/2026/…/x.docx"
     */
    public function editorUrl(string $client, int $ncFileId, string $filePath): string {
        $encodedPath = str_replace('%2F', '/', rawurlencode($filePath));
        return match ($client) {
            'eurooffice' => $this->urlGenerator->getAbsoluteURL(
                '/index.php/apps/eurooffice/' . $ncFileId . '?filePath=' . $encodedPath
            ),
        };
    }

    /**
     * fileId / name / editorUrl for a case file, or null if it isn't in the
     * user's virtual mount (deleted, or no access).
     *
     * @return array{fileId: int, name: string, editorUrl: string}|null
     */
    public function editorFile(FileEntity $file, CaseEntity $case, string $client, string $userId): ?array {
        try {
            $ncFileId = $this->fileService->getNextcloudFileId($file->getId(), $userId);
        } catch (NotFoundException) {
            return null;
        }

        $filePath = '/' . implode('/', [
            $this->config->getAppValue('opencase', 'mount_name', '.Sager'),
            $case->getCreatedYear(), $case->getCreatedMonth(), $case->getCreatedDay(),
            $case->getCaseNumber(),
            $file->getVirtualFilename(),
        ]);

        return [
            'fileId'    => $ncFileId,
            'name'      => $file->getVirtualFilename(),
            'editorUrl' => $this->editorUrl($client, $ncFileId, $filePath),
        ];
    }

    /** Absolute URL of a path on this server, e.g. "/index.php/apps/opencase/…". */
    public function absoluteUrl(string $path): string {
        return $this->urlGenerator->getAbsoluteURL($path);
    }

    /** Absolute URL of a page in the OpenCase web app, e.g. "document/6". */
    public function appUrl(string $path): string {
        return $this->urlGenerator->linkToRouteAbsolute('opencase.page.index') . $path;
    }

    /**
     * @return array{id: string, displayName: string}|null
     */
    public function user(?string $uid): ?array {
        if ($uid === null || $uid === '') {
            return null;
        }
        return ['id' => $uid, 'displayName' => $this->userManager->get($uid)?->getDisplayName() ?? $uid];
    }
}
