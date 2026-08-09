<?php

declare(strict_types=1);

namespace OCA\OpenCase\Storage;

use OC\Files\Cache\Scanner;
use OCP\Files\Storage\IStorage;

/**
 * Scanner for OpenCaseStorage.
 *
 * On-demand scanning (SCAN_SHALLOW, triggered by getCacheEntry when a path
 * is not yet in the Nextcloud file cache) is delegated to the parent Scanner,
 * which populates oc_filecache so that Node::getId() returns a valid file ID.
 * This is required for /f/{ncFileId} routing and Nextcloud Office (WOPI).
 *
 * Background / recursive scanning is disabled as a no-op to prevent Nextcloud
 * from walking the entire virtual hierarchy via background jobs.
 */
class OpenCaseScanner extends Scanner {

    public function __construct(IStorage $storage) {
        parent::__construct($storage);
    }

    /**
     * Prevent background scanning — the virtual hierarchy is populated on demand.
     */
    public function backgroundScan(): void {
        // Intentionally empty
    }
}
