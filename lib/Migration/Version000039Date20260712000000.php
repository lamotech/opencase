<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Seeds the enterprise_version config entry, which gates the Distribution
 * enterprise components (PublicDistributionReceiverController,
 * ProcessDistributionReceivementsJob, DistributionWrapper). These files are
 * only shipped in the Enterprise deployment package, not the public
 * Nextcloud App Store release. Default '0' means "not licensed / not
 * installed" — the app must run without them.
 */
class Version000039Date20260712000000 extends SimpleMigrationStep {
    public function __construct(private IDBConnection $db) {}

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $this->seedConfigEntry(
            'enterprise_version',
            '0',
            'Enterprise version enabled',
            'Set to 1 only when the Enterprise deployment package (which installs the Distribution components) has been installed. Leave at 0 for the public App Store release.',
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
