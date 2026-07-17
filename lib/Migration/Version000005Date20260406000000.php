<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000005Date20260406000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── opencase_klass_log ────────────────────────────────────────────
        if (!$schema->hasTable('opencase_klass_log')) {
            $table = $schema->createTable('opencase_klass_log');
            $table->addColumn('sync_time', 'datetime', ['notnull' => true]);
            $table->addColumn('count_received', 'integer', ['notnull' => true]);
            $table->addColumn('created', 'integer', ['notnull' => true]);
            $table->addColumn('updated', 'integer', ['notnull' => true]);
            $table->addColumn('deactivated', 'integer', ['notnull' => true]);
        }

        return $schema;
    }

}
