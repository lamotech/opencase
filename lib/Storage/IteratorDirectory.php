<?php

declare(strict_types=1);

namespace OCA\OpenCase\Storage;

use Icewind\Streams\IteratorDirectory as IcewindIteratorDirectory;

/**
 * Wraps an array of filenames into a directory resource handle
 * that Nextcloud's storage layer expects from opendir().
 */
class IteratorDirectory {

    /**
     * Create a directory resource from an array of entry names.
     *
     * @param string[] $entries
     * @return resource|false
     */
    public static function wrap(array $entries) {
        return IcewindIteratorDirectory::wrap($entries);
    }
}
