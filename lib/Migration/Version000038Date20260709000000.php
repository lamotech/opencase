<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Seeds the msoffice_graph_client_id / msoffice_graph_tenant_id config
 * entries — the Entra ID (Azure AD) app registration used by the MS Office
 * add-ins to acquire Microsoft Graph tokens (MSAL.js, auth-code + PKCE),
 * needed to download the real file bytes when running in a browser (Excel,
 * Word, and PowerPoint on the web don't expose native file bytes via
 * Office.js — see msoffice/shared/src/opencase-common.js) or the raw email
 * MIME in Outlook. Both values are non-secret (public client / SPA app
 * registration, no client secret involved) so they're safe to expose in
 * client-side JS, same as msoffice_addin_port.
 */
class Version000038Date20260709000000 extends SimpleMigrationStep {
    public function __construct(private IDBConnection $db) {}

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $this->seedConfigEntry(
            'msoffice_graph_client_id',
            '',
            'MS Office Graph app (client) ID',
            'Application (client) ID of the Entra ID app registration used by the MS Office add-ins to call Microsoft Graph (downloading real file/email content when running in a browser). Register a Single-page application (SPA) with redirect URI https://<addin host>/shared/graph-auth.html and delegated Microsoft Graph permission Files.Read (mail add-in also needs Mail.Read), with admin consent granted.',
            $output,
        );

        $this->seedConfigEntry(
            'msoffice_graph_tenant_id',
            '',
            'MS Office Graph tenant ID',
            'Directory (tenant) ID of the Entra ID tenant that owns the app registration above.',
            $output,
        );
    }

    private function seedConfigEntry(
        string $key,
        string $defaultValue,
        string $name,
        string $description,
        IOutput $output,
    ): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('config_key')
            ->from('opencase_config')
            ->where($qb->expr()->eq('config_key', $qb->createNamedParameter($key)));
        $result = $qb->executeQuery();
        $exists = $result->fetchOne();
        $result->closeCursor();

        if ($exists === false) {
            $qb->resetQueryParts();
            $qb->insert('opencase_config')->values([
                'config_key'   => $qb->createNamedParameter($key),
                'config_value' => $qb->createNamedParameter($defaultValue),
                'name'         => $qb->createNamedParameter($name),
                'description'  => $qb->createNamedParameter($description),
            ])->executeStatement();
            $output->info("Seeded config: {$key}.");
        } else {
            $qb->resetQueryParts();
            $qb->update('opencase_config')
                ->set('name', $qb->createNamedParameter($name))
                ->set('description', $qb->createNamedParameter($description))
                ->where($qb->expr()->eq('config_key', $qb->createNamedParameter($key)))
                ->executeStatement();
            $output->info("Updated metadata for config: {$key}.");
        }
    }
}
