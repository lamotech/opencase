<?php

declare(strict_types=1);

namespace OCA\OpenCase\Mount;

use OC\Files\Mount\MountPoint;
use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\FileMapper;
use OCA\OpenCase\Service\PermissionService;
use OCA\OpenCase\Storage\OpenCasePermissionWrapper;
use OCA\OpenCase\Storage\OpenCaseStorage;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Config\IMountProvider;
use OCP\Files\Storage\IStorageFactory;
use OCP\IConfig;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Registers a virtual "Sager" mount point in each user's file tree.
 *
 * Design (shared-storage model):
 *   ONE OpenCaseStorage instance (storageId = 'opencase') is created per
 *   process. All users mount the SAME underlying storage, giving a single
 *   set of oc_filecache rows shared across every user.
 *
 *   Per-user access control is applied by wrapping the shared storage in an
 *   OpenCasePermissionWrapper at mount time. Each user gets their own wrapper
 *   instance, but they all point at the same underlying storage object.
 *
 * This mirrors the Group Folders pattern and avoids the N-user × M-file
 * explosion in oc_filecache (was: users × files × access%; now: just files).
 */
class OpenCaseMountProvider implements IMountProvider {

    /**
     * Single shared storage instance, lazily created.
     * Shared across all getMountsForUser() calls within this process.
     */
    private ?OpenCaseStorage $sharedStorage = null;

    public function __construct(
        private PermissionService $permissionService,
        private CaseMapper $caseMapper,
        private FileMapper $fileMapper,
        private IEventDispatcher $eventDispatcher,
        private LoggerInterface $logger,
        private IConfig $config,
    ) {
    }

    /**
     * Return mount points for the given user.
     *
     * Returns an empty array for users without any access profile.
     *
     * @return MountPoint[]
     */
    public function getMountsForUser(IUser $user, IStorageFactory $loader): array {
        $profiles      = $this->permissionService->getAccessProfileIdsForUser($user->getUID());
        $hasCaseAccess = empty($profiles) && $this->permissionService->userHasAnyCaseAccess($user->getUID());
        $hasFileShares = empty($profiles) && !$hasCaseAccess
            ? $this->permissionService->userHasAnyFileShare($user->getUID())
            : false;

        if (empty($profiles) && !$hasCaseAccess && !$hasFileShares) {
            return [];
        }

        $mountName = $this->config->getAppValue('opencase', 'mount_name', '.Sager');

        // Wrap the shared storage with per-user ACL enforcement
        $wrappedStorage = new OpenCasePermissionWrapper([
            'storage'           => $this->getSharedStorage(),
            'user'              => $user,
            'permissionService' => $this->permissionService,
            'caseMapper'        => $this->caseMapper,
        ]);

        return [
            new MountPoint(
                $wrappedStorage,
                '/' . $user->getUID() . '/files/' . $mountName . '/',
                null,
                $loader,
            ),
        ];
    }

    /**
     * Return (or lazily create) the single shared OpenCaseStorage instance.
     *
     * The shared storage has no user-specific state; it describes the entire
     * virtual file tree. Per-user filtering is done by OpenCasePermissionWrapper.
     */
    private function getSharedStorage(): OpenCaseStorage {
        if ($this->sharedStorage === null) {
            $this->sharedStorage = new OpenCaseStorage([
                'baseDir'         => $this->config->getAppValue(
                    'opencase', 'storage_base_dir',
                    rtrim($this->config->getSystemValueString('datadirectory', ''), '/') . '/opencase_storage'
                ),
                'caseMapper'      => $this->caseMapper,
                'fileMapper'      => $this->fileMapper,
                'eventDispatcher' => $this->eventDispatcher,
                'logger'          => $this->logger,
            ]);
        }
        return $this->sharedStorage;
    }
}
