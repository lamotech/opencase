<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the caseworkers relation table (case ↔ user).
 *
 * New table:
 *   opencase_caseworkers  — additional caseworkers linked to a case
 */
class Version000012Date20260418000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('opencase_caseworkers')) {
            $table = $schema->createTable('opencase_caseworkers');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('case_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['case_id', 'user_id'], 'oc_cw_case_user');
            $table->addIndex(['case_id'], 'oc_cw_case_id');
        }

        // ── opencase_api_client ───────────────────────────────────────────
        // Registry of systems authorised to call the public API via mTLS.
        // Each row maps a client certificate (identified by its SHA-256
        // fingerprint) to a named service identity and optional scope.
        if (!$schema->hasTable('opencase_api_client')) {
            $table = $schema->createTable('opencase_api_client');
            $table->addColumn('id', Types::BIGINT, ['notnull' => true, 'autoincrement' => true]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('fingerprint', Types::STRING, ['notnull' => true, 'length' => 64,
                'comment' => 'SHA-256 fingerprint of the client certificate, lowercase hex, no colons']);
            $table->addColumn('subject_dn', Types::STRING, ['notnull' => false, 'length' => 512, 'default' => null,
                'comment' => 'Certificate subject DN, stored for human reference only']);
            $table->addColumn('active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('expires_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['fingerprint'], 'oc_api_client_fp');
            $table->addIndex(['active'], 'oc_api_client_active');
        }

        // ── opencase_messagesreceived ─────────────────────────────────────
        // Inbox for messages received from Beskedfordeler (and other senders
        // via the public MessageReceiver endpoint).
        if (!$schema->hasTable('opencase_messagesreceived')) {
            $table = $schema->createTable('opencase_messagesreceived');
            $table->addColumn('id', Types::BIGINT, ['notnull' => true, 'autoincrement' => true]);
            $table->addColumn('cpr', Types::STRING, ['notnull' => true, 'length' => 20]);
            $table->addColumn('personhaendelse', Types::STRING, ['notnull' => true, 'length' => 100,
                'comment' => 'Event type code extracted from the personhaendelse URN, e.g. 004']);
            $table->addColumn('received_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 50, 'default' => 'Received']);
            $table->addColumn('status_updated_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['cpr'], 'oc_msgrec_cpr');
            $table->addIndex(['status'], 'oc_msgrec_status');
        }

        return $schema;
    }
}
