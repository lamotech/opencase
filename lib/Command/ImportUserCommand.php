<?php

declare(strict_types=1);

namespace OCA\OpenCase\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Db\UserInfoMapper;
use OCA\OpenCase\Service\CaseService;
use OCP\IDBConnection;

class ImportUserCommand extends Command {

    private const PRIVILEGE_TYPE = 'opencaseuser';
    private const FOELSOMHED_RAW = '1d81c472-0808-44cc-963d-f5ef0170ae1d';
    private const KLE_RAW = '*';
    private const DEFAULT_ROLE = 'Sagsbehandler';

    public function __construct(
        private UserInfoMapper $userInfoMapper,
        private OrganisationMapper $organisationMapper,
        private IDBConnection $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('opencase:import-user')
            ->setDescription('Create opencase_userinfo and opencase_user_priv_groups rows for a newly imported user')
            ->addArgument('user_id', InputArgument::REQUIRED, 'Nextcloud username')
            ->addArgument('display_name', InputArgument::REQUIRED, 'Display name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $userId = $input->getArgument('user_id');
        $displayName = $input->getArgument('display_name');
        $email = $userId . '@nextcloud.local';
        $userUuid = CaseService::generateUuid();

        $this->userInfoMapper->upsert(
            $userUuid,
            $userId,
            $displayName,
            $email,
            '',
            '',
            $userId
        );

        $qb = $this->db->getQueryBuilder();
        $qb->delete('opencase_user_priv_groups')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();

        $qb = $this->db->getQueryBuilder();
        $qb->insert('opencase_user_priv_groups')
            ->values([
                'user_id'        => $qb->createNamedParameter($userId),
                'privilege_type' => $qb->createNamedParameter(self::PRIVILEGE_TYPE),
                'foelsomhed_raw' => $qb->createNamedParameter(self::FOELSOMHED_RAW),
                'kle_raw'        => $qb->createNamedParameter(self::KLE_RAW),
                'orgenhed_raw'   => $qb->createNamedParameter(null),
                'updated_at'     => $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s')),
            ]);
        $qb->executeStatement();

        $organisations = $this->organisationMapper->findAllActive();
        if (empty($organisations)) {
            $output->writeln('<error>No organisations found in opencase_org; skipping opencase_userorgs assignment</error>');
            return Command::FAILURE;
        }
        $org = $organisations[array_rand($organisations)];

        $qb = $this->db->getQueryBuilder();
        $qb->delete('opencase_userorgs')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();

        $qb = $this->db->getQueryBuilder();
        $qb->insert('opencase_userorgs')
            ->values([
                'user_uuid' => $qb->createNamedParameter($userUuid),
                'org_uuid'  => $qb->createNamedParameter($org->getOrgUuid()),
                'role'      => $qb->createNamedParameter(self::DEFAULT_ROLE),
                'user_id'   => $qb->createNamedParameter($userId),
            ]);
        $qb->executeStatement();

        $output->writeln('<info>Imported opencase user data for ' . $userId . ' (org: ' . $org->getOrgName() . ')</info>');
        return Command::SUCCESS;
    }
}
