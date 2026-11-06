<?php

declare(strict_types=1);

namespace OCA\OpenCase\Mount;

use OC\Files\Mount\MountPoint;
use OC\Files\Storage\Wrapper\PermissionsMask;
use OCA\OpenCase\Service\RoleService;
use OCA\OpenCase\Storage\BrevdesignerStorage;
use OCP\Constants;
use OCP\Files\Config\IMountProvider;
use OCP\Files\Storage\IStorageFactory;
use OCP\IConfig;
use OCP\IUser;

/**
 * Mounts the Brevdesigner storage folders — {storage_base_dir}/_templates and
 * {storage_base_dir}/_phrases — as hidden folders in the file tree of users
 * who may manage templates (Administrator / Template Designer).
 *
 * This lets Nextcloud Office open and save the stored template and phrase
 * files in place: there are no per-user copies. Each folder is ONE shared
 * storage (one set of oc_filecache rows, so a file has the same id for
 * every user), restricted to read + update — files cannot be created,
 * renamed or deleted through the Files app; that is done in OpenCase. No
 * versions are kept (BrevdesignerVersionsBackend).
 */
class BrevdesignerMountProvider implements IMountProvider {

    /** Mount folder names in the user's file tree, by storage folder. */
    public const MOUNTS = [
        '_templates' => '.opencase-skabeloner',
        '_phrases'   => '.opencase-fraser',
    ];

    /** @var array<string, BrevdesignerStorage> shared storage per storage folder */
    private array $storages = [];

    /** @var array<string, bool> per-user role check cache */
    private array $canManage = [];

    public function __construct(
        private RoleService $roleService,
        private IConfig $config,
    ) {
    }

    /**
     * Path of a stored file relative to the user's files root, e.g.
     * ".opencase-skabeloner/3f2a….docx".
     */
    public static function userPath(string $storageFolder, string $storageFilename): string {
        return self::MOUNTS[$storageFolder] . '/' . $storageFilename;
    }

    public function getMountsForUser(IUser $user, IStorageFactory $loader): array {
        $uid = $user->getUID();
        $this->canManage[$uid] ??= $this->roleService->userHasRole($uid, 'Administrator')
            || $this->roleService->userHasRole($uid, 'Template Designer');
        if (!$this->canManage[$uid]) {
            return [];
        }

        $mounts = [];
        foreach (self::MOUNTS as $folder => $mountName) {
            $storage = $this->storage($folder);
            if ($storage === null) {
                continue;
            }
            $mounts[] = new MountPoint(
                new PermissionsMask([
                    'storage' => $storage,
                    'mask'    => Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE,
                ]),
                '/' . $uid . '/files/' . $mountName . '/',
                null,
                $loader,
            );
        }
        return $mounts;
    }

    private function storage(string $folder): ?BrevdesignerStorage {
        if (!isset($this->storages[$folder])) {
            $defaultDir = rtrim($this->config->getSystemValueString('datadirectory', ''), '/') . '/opencase_storage';
            $baseDir = rtrim($this->config->getAppValue('opencase', 'storage_base_dir', $defaultDir), '/');
            $path = $baseDir . '/' . $folder;
            if (!is_dir($path) && !@mkdir($path, 0750, true) && !is_dir($path)) {
                return null;
            }
            $this->storages[$folder] = new BrevdesignerStorage(['datadir' => $path]);
        }
        return $this->storages[$folder];
    }
}
