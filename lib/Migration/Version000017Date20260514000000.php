<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000017Date20260514000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('opencase_dp_receivers')) {
            $table = $schema->getTable('opencase_dp_receivers');
            if (!$table->hasColumn('document_contact_id')) {
                $table->addColumn('document_contact_id', Types::BIGINT, [
                    'notnull'  => false,
                    'unsigned' => true,
                    'default'  => null,
                ]);
                $table->addIndex(['document_contact_id'], 'oc_opencase_dpr_contact_id');
            }
        }

        return $schema;
    }
}
