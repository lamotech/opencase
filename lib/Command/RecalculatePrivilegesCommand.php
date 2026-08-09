<?php

declare(strict_types=1);

namespace OCA\OpenCase\Command;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OCC command: opencase:privileges:recalculate
 *
 * Runs the opencase_recalc_privileges() stored procedure, which rebuilds
 * oc_opencase_user_priv_match and the grant_source='privilege' rows in
 * oc_opencase_user_access for ALL users in one set-based pass from the
 * privilege groups currently stored in oc_opencase_user_priv_groups.
 *
 * Mirrors the exact same matching rules as PrivilegeService::syncPrivileges(),
 * which normally runs per-user on SAML login — this command is for bulk
 * recalculation after manually editing privileges, changing access profiles,
 * or restoring from a backup.
 *
 * Manual grants (grant_source='manual') are never touched.
 *
 * Usage:
 *   occ opencase:privileges:recalculate
 */
class RecalculatePrivilegesCommand extends Command {

    public function __construct(
        private IDBConnection $db,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('opencase:privileges:recalculate')
            ->setDescription('Recalculate all users\' access rights from oc_opencase_user_priv_groups.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $output->writeln('Recalculating privilege-based access for all users…');
        $start = microtime(true);

        try {
            $this->db->executeStatement('CALL opencase_recalc_privileges()');
        } catch (\Throwable $e) {
            $output->writeln('<error>Recalculation failed: ' . $e->getMessage() . '</error>');
            $this->logger->error('OpenCase occ privileges recalculate failed: {error}', [
                'error' => $e->getMessage(),
            ]);
            return self::FAILURE;
        }

        $elapsed = round(microtime(true) - $start, 2);
        $output->writeln("Done in {$elapsed}s.");

        $qb = $this->db->getQueryBuilder();
        $matchCount = (int)$qb->select($qb->createFunction('COUNT(*)'))
            ->from('opencase_user_priv_match')
            ->executeQuery()->fetchOne();

        $qb = $this->db->getQueryBuilder();
        $accessCount = (int)$qb->select($qb->createFunction('COUNT(*)'))
            ->from('opencase_user_access')
            ->where($qb->expr()->eq('grant_source', $qb->createNamedParameter('privilege')))
            ->executeQuery()->fetchOne();

        $qb = $this->db->getQueryBuilder();
        $userCount = (int)$qb->select($qb->createFunction('COUNT(DISTINCT user_id)'))
            ->from('opencase_user_priv_groups')
            ->executeQuery()->fetchOne();

        $output->writeln("Privilege groups covered: {$userCount} user(s)");
        $output->writeln("oc_opencase_user_priv_match rows: {$matchCount}");
        $output->writeln("oc_opencase_user_access rows (grant_source='privilege'): {$accessCount}");

        return self::SUCCESS;
    }
}
