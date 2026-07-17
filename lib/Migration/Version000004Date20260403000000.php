<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000004Date20260403000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── opencase_class_facet ──────────────────────────────────────────
        if (!$schema->hasTable('opencase_class_facet')) {
            $table = $schema->createTable('opencase_class_facet');
            $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('code', Types::STRING, ['notnull' => true, 'length' => 255, 'comment' => 'KLE facet code, e.g. G01']);
            $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('description', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
            $table->setPrimaryKey(['uuid']);
            $table->addUniqueIndex(['code'], 'oc_cf_code');
            $table->addIndex(['active'], 'oc_cf_active');
        }

        return $schema;
    }

}
