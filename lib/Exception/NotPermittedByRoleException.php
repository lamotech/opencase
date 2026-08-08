<?php

declare(strict_types=1);

namespace OCA\OpenCase\Exception;

class NotPermittedByRoleException extends \RuntimeException {
    public function __construct() {
        parent::__construct('You do not have permission to access OpenCase.');
    }
}
