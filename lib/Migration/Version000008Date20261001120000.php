<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * opencase_dp_shipment.include_attachments — whether the files of the
 * document's attachments (document relations of type Bilag) were added to
 * the shipment.
 */
class Version000008Date20261001120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('opencase_dp_shipment');
        if (!$table->hasColumn('include_attachments')) {
            $table->addColumn('include_attachments', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
        }

        return $schema;
    }
}
