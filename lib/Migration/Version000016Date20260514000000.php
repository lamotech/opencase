<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000016Date20260514000000 extends SimpleMigrationStep {

    public function __construct(private IDBConnection $db) {}

    // ---------------------------------------------------------------
    // Schema changes
    // ---------------------------------------------------------------

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // --- Add uuid to cases ---
        if ($schema->hasTable('opencase_cases')) {
            $table = $schema->getTable('opencase_cases');
            if (!$table->hasColumn('uuid')) {
                $table->addColumn('uuid', Types::STRING, [
                    'length'  => 36,
                    'notnull' => false,
                    'default' => null,
                ]);
                $table->addIndex(['uuid'], 'oc_opencase_cases_uuid');
            }
        }

        // --- Add uuid to documents ---
        if ($schema->hasTable('opencase_documents')) {
            $table = $schema->getTable('opencase_documents');
            if (!$table->hasColumn('uuid')) {
                $table->addColumn('uuid', Types::STRING, [
                    'length'  => 36,
                    'notnull' => false,
                    'default' => null,
                ]);
                $table->addIndex(['uuid'], 'oc_opencase_docs_uuid');
            }
        }

        // --- Digital Post shipment ---
        if (!$schema->hasTable('opencase_dp_shipment')) {
            $table = $schema->createTable('opencase_dp_shipment');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);
            $table->addColumn('document_id', Types::BIGINT, [
                'notnull'  => true,
                'unsigned' => true,
            ]);
            $table->addColumn('can_be_replied', Types::BOOLEAN, [
                'notnull' => true,
                'default' => true,
            ]);
            $table->addColumn('case_uuid', Types::STRING, [
                'length'  => 36,
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('document_uuid', Types::STRING, [
                'length'  => 36,
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => false,
            ]);
            $table->addColumn('created_by', Types::STRING, [
                'length'  => 64,
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('updated_at', Types::DATETIME, [
                'notnull' => false,
            ]);
            $table->addColumn('status', Types::STRING, [
                'length'  => 32,
                'notnull' => true,
                'default' => 'Pending',
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['document_id'], 'oc_opencase_dps_doc_id');
        }

        // --- Digital Post shipment files ---
        if (!$schema->hasTable('opencase_dp_files')) {
            $table = $schema->createTable('opencase_dp_files');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);
            $table->addColumn('dp_shipment_id', Types::BIGINT, [
                'notnull'  => true,
                'unsigned' => true,
            ]);
            $table->addColumn('file_id', Types::BIGINT, [
                'notnull'  => true,
                'unsigned' => true,
            ]);
            $table->addColumn('file_name', Types::STRING, [
                'length'  => 512,
                'notnull' => true,
                'default' => '',
            ]);
            $table->addColumn('storage_filename', Types::STRING, [
                'length'  => 255,
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('is_main', Types::BOOLEAN, [
                'notnull' => true,
                'default' => false,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['dp_shipment_id'], 'oc_opencase_dpf_ship_id');
        }

        // --- Digital Post shipment receivers ---
        if (!$schema->hasTable('opencase_dp_receivers')) {
            $table = $schema->createTable('opencase_dp_receivers');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);
            $table->addColumn('dp_shipment_id', Types::BIGINT, [
                'notnull'  => true,
                'unsigned' => true,
            ]);
            $table->addColumn('title', Types::STRING, [
                'length'  => 512,
                'notnull' => true,
                'default' => '',
            ]);
            $table->addColumn('cpr_cvr', Types::STRING, [
                'length'  => 32,
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('receiver_name', Types::STRING, [
                'length'  => 255,
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('dp_subscription', Types::BOOLEAN, [
                'notnull' => true,
                'default' => false,
            ]);
            $table->addColumn('storage_filename', Types::STRING, [
                'length'  => 255,
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('message_id', Types::STRING, [
                'length'  => 36,
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('message_created_at', Types::DATETIME, [
                'notnull' => false,
            ]);
            $table->addColumn('status', Types::STRING, [
                'length'  => 32,
                'notnull' => true,
                'default' => 'Pending',
            ]);
            $table->addColumn('message_advis_id', Types::STRING, [
                'length'  => 32,
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('message_advis_tekst', Types::STRING, [
                'length'  => 32,
                'notnull' => false,
                'default' => null,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['dp_shipment_id'], 'oc_opencase_dpr_ship_id');
        }

        return $schema;
    }

    // ---------------------------------------------------------------
    // Data migration — populate uuid for all existing rows
    // ---------------------------------------------------------------

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $this->populateUuids('opencase_cases', $output);
        $this->populateUuids('opencase_documents', $output);
    }

    private function populateUuids(string $tableName, IOutput $output): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($tableName)
            ->where($qb->expr()->isNull('uuid'));

        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['id'];
        }
        $result->closeCursor();

        foreach ($ids as $id) {
            $this->db->executeStatement(
                "UPDATE `*PREFIX*{$tableName}` SET `uuid` = ? WHERE `id` = ?",
                [self::generateUuid(), $id]
            );
        }

        $output->info("Populated " . count($ids) . " uuid(s) in {$tableName}.");
    }

    private static function generateUuid(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
