<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use OCP\Migration\SimpleMigrationStep;

/**
 * Version placeholder for 0.0.21.
 *
 * Stored procedure installation is handled by InstallStoredProcedures
 * (IRepairStep) which runs on both fresh install and upgrade, because
 * Nextcloud skips postSchemaChange during fresh installs.
 */
class Version000006Date20260408000000 extends SimpleMigrationStep {}
