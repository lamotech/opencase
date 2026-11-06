<?php

declare(strict_types=1);

namespace OCA\OpenCase\Versions;

use OCA\Files_Versions\Versions\IVersion;
use OCA\Files_Versions\Versions\IVersionBackend;
use OCA\OpenCase\Storage\BrevdesignerStorage;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\IUser;

/**
 * Versions backend for template and phrase files (BrevdesignerStorage):
 * keeps no versions. Without it files_versions' default backend would store
 * a copy of each edited template/phrase in the editing user's own data.
 */
class BrevdesignerVersionsBackend implements IVersionBackend {

    public function useBackendForStorage(IStorage $storage): bool {
        return $storage->instanceOfStorage(BrevdesignerStorage::class);
    }

    public function getVersionsForFile(IUser $user, FileInfo $file): array {
        return [];
    }

    public function createVersion(IUser $user, FileInfo $file): void {
    }

    public function rollback(IVersion $version): bool {
        return false;
    }

    public function read(IVersion $version) {
        throw new NotFoundException('Template and phrase files have no versions');
    }

    public function getVersionFile(IUser $user, FileInfo $sourceFile, $revision): File {
        throw new NotFoundException('Template and phrase files have no versions');
    }

    public function getRevision(Node $node): int {
        return $node->getMTime();
    }
}
