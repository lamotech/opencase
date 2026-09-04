<?php

declare(strict_types=1);

namespace OCA\OpenCase\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use OCA\OpenCase\Db\UserInfoMapper;
use OCA\OpenCase\Service\UserSyncService;

class UpdateUserCommand extends Command {

    public function __construct(
        private UserSyncService $userSyncService,
        private UserInfoMapper $userInfoMapper,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('opencase:update-user')
            ->setDescription('Fetch user data from Serviceplatformen and upsert into opencase_userinfo')
            ->addArgument('uuid', InputArgument::REQUIRED, 'User UUID identifier');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $uuid = $input->getArgument('uuid');
        $output->writeln('<info>Updating user: ' . $uuid . '</info>');

        $userInfo = $this->userInfoMapper->findByUuid($uuid);
        $userId = $userInfo?->getUserId();
        if ($userId === null) {
            $output->writeln('<error>No opencase_userinfo record found for uuid ' . $uuid . '</error>');
            return Command::FAILURE;
        }

        try {
            $this->userSyncService->updateUser($uuid, $userId);
            $output->writeln('<info>User updated successfully.</info>');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>Update failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
