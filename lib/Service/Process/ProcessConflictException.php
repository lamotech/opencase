<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\Process;

/**
 * Thrown when a process version can't be changed in its current state:
 * the draft was saved by someone else since it was loaded, the version is
 * no longer a draft, or a draft already exists. Mapped to 409 Conflict.
 */
class ProcessConflictException extends \Exception {
}
