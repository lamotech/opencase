<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\UserInfoMapper;
use OCA\OpenCase\Db\UserOrgMapper;
use OCA\OpenCase\Service\Serviceplatformen\UserClient;

class UserSyncService {

    private const ROLE_EMAIL    = '5d13e891-162a-456b-abf2-fd9b864df96d';
    private const ROLE_PHONE    = '5ef6be2d-59f4-4652-a680-585929924ba9';
    private const ROLE_LOCATION = 'ad04ac80-e24a-45a5-9dd9-8537a916ac74';

    public function __construct(
        private UserClient $userClient,
        private UserInfoMapper $userInfoMapper,
        private UserOrgMapper $userOrgMapper,
    ) {}

    public function updateUser(string $UserUUIDIdentifikator): void {
        $user = $this->userClient->fetchUser($UserUUIDIdentifikator);

        $email = '';
        $phone = '';
        $location = '';

        foreach ($user->addresses as $address) {
            if ($address->roleUuid === self::ROLE_EMAIL) {
                $email = $address->text;
            } elseif ($address->roleUuid === self::ROLE_PHONE) {
                $phone = $address->text;
            } elseif ($address->roleUuid === self::ROLE_LOCATION) {
                $location = $address->text;
            }
        }

        $userId = 'opencase_' . $user->uuid;

        $this->userInfoMapper->upsert(
            $user->uuid,
            $user->username,
            $user->person->name,
            $email,
            $phone,
            $location,
            $userId,
        );

        $this->userOrgMapper->syncForUser($user->uuid, $user->organisations);
    }
}
