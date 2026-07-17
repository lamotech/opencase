<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000026Date20260530000000 extends SimpleMigrationStep {

    public function __construct(private IDBConnection $db) {}

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('opencase_userorgs');
        if (!$table->hasColumn('user_id')) {
            $table->addColumn('user_id', Types::STRING, ['notnull' => false, 'length' => 64]);
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_uuid')
            ->from('opencase_userorgs')
            ->where($qb->expr()->isNull('user_id'));
        $result = $qb->executeQuery();

        $userUuids = [];
        while ($row = $result->fetch()) {
            $userUuids[] = $row['user_uuid'];
        }
        $result->closeCursor();

        foreach (array_unique($userUuids) as $userUuid) {
            $userId = 'opencase_' . $userUuid;
            $qb = $this->db->getQueryBuilder();
            $qb->update('opencase_userorgs')
                ->set('user_id', $qb->createNamedParameter($userId))
                ->where($qb->expr()->eq('user_uuid', $qb->createNamedParameter($userUuid)));
            $qb->executeStatement();
        }

        $output->info('Backfilled user_id in opencase_userorgs.');
    }
}
