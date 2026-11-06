<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * opencase_org.logo_file — the organisation's logo, an image file stored as
 * {storage_base_dir}/_logos/{logo_file}. Merged into templates at
 * {{organisation.logo}}; an organisation without a logo uses its
 * nearest ancestor's (see LogoService).
 */
class Version000013Date20261027000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $org = $schema->getTable('opencase_org');
        if (!$org->hasColumn('logo_file')) {
            $org->addColumn('logo_file', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        }

        return $schema;
    }
}
