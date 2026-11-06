<?php

declare(strict_types=1);

namespace OCA\OpenCase\Storage;

use OC\Files\Storage\Local;

/**
 * Local storage for a Brevdesigner folder ({storage_base_dir}/_templates or
 * /_phrases), mounted by BrevdesignerMountProvider. A class of its own so
 * BrevdesignerVersionsBackend can claim it and no version copies are made.
 */
class BrevdesignerStorage extends Local {
}
