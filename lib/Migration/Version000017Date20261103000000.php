<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Organisation membership from Nextcloud groups (e.g. Microsoft Entra ID),
 * for customers without the Serviceplatformen integration:
 *
 * - opencase_org.member_group_id — the Nextcloud group whose members belong
 *   to the organisation (the "group" column of the organisation CSV import).
 * - opencase_userorgs.source — where a membership comes from: 'privilege'
 *   (SAML token / Rettigheder / import, the existing rows) or 'group' (member
 *   of the organisation's member group, OrganisationMembershipSyncService).
 *   Each source only replaces its own rows.
 */
class Version000017Date20261103000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $org = $schema->getTable('opencase_org');
        if (!$org->hasColumn('member_group_id')) {
            $org->addColumn('member_group_id', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        }
        if (!$org->hasIndex('oc_org_member_group')) {
            $org->addIndex(['member_group_id'], 'oc_org_member_group');
        }

        $userOrgs = $schema->getTable('opencase_userorgs');
        if (!$userOrgs->hasColumn('source')) {
            $userOrgs->addColumn('source', Types::STRING, [
                'notnull' => true,
                'length'  => 16,
                'default' => 'privilege',
                'comment' => 'privilege = SAML token / Rettigheder; group = organisation member group',
            ]);
        }

        return $schema;
    }
}
