<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds nc_file_id and nc_owner columns to opencase_templates.
 *
 * When a template is created as a blank via "Ny skabelon", the file lives in
 * the user's Nextcloud home (OpenCase/Skabeloner/<name>.odt) so it can be
 * opened for editing in Nextcloud Office.  These columns store the Nextcloud
 * file ID and owner so the template service can read the latest saved content
 * directly from the Nextcloud VFS instead of the static _templates/ copy.
 */
class Version000008Date20260412000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('opencase_templates');

        if (!$table->hasColumn('nc_file_id')) {
            $table->addColumn('nc_file_id', Types::BIGINT, [
                'notnull' => false,
                'default' => null,
            ]);
        }

        if (!$table->hasColumn('nc_owner')) {
            $table->addColumn('nc_owner', Types::STRING, [
                'notnull' => false,
                'length'  => 64,
                'default' => null,
            ]);
        }

        return $schema;
    }
}
