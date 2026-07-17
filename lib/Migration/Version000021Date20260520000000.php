<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000021Date20260520000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('opencase_favorites')) {
            $table = $schema->createTable('opencase_favorites');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            $table->addColumn('added_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->addColumn('entity', Types::STRING, [
                'notnull' => true,
                'length'  => 32,
            ]);
            $table->addColumn('entity_key', Types::BIGINT, [
                'notnull'  => true,
                'unsigned' => true,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_id', 'entity', 'entity_key'], 'oc_opencase_fav_unique');
            $table->addIndex(['user_id'], 'oc_opencase_fav_user');
        }

        return $schema;
    }
}
