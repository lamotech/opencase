<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds access_level column to opencase_user_priv_match to support the
 * opencasereaduser SAML role, which grants read-only access with the same
 * foelsomhed/KLE/orgenhed constraints as opencaseuser.
 *
 * Existing rows default to 'write' (no behaviour change for current users).
 */
class Version000033Date20260609000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('opencase_user_priv_match')) {
            $table = $schema->getTable('opencase_user_priv_match');
            if (!$table->hasColumn('access_level')) {
                $table->addColumn('access_level', Types::STRING, [
                    'notnull' => true,
                    'length'  => 8,
                    'default' => 'write',
                    'comment' => 'read | write — derived from privilege_type of the source group',
                ]);
            }
        }

        return $schema;
    }
}
