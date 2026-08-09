<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\UserInfoMapper;
use OCA\OpenCase\Db\UserOrgMapper;
use OCA\OpenCase\Service\Serviceplatformen\UserClient;
use OCP\Accounts\IAccountManager;
use OCP\IPhoneNumberUtil;
use OCP\IUserManager;

class UserSyncService {

    private const ROLE_EMAIL    = '5d13e891-162a-456b-abf2-fd9b864df96d';
    private const ROLE_PHONE    = '5ef6be2d-59f4-4652-a680-585929924ba9';
    private const ROLE_LOCATION = 'ad04ac80-e24a-45a5-9dd9-8537a916ac74';

    public function __construct(
        private UserClient $userClient,
        private UserInfoMapper $userInfoMapper,
        private UserOrgMapper $userOrgMapper,
        private IUserManager $userManager,
        private IAccountManager $accountManager,
        private IPhoneNumberUtil $phoneNumberUtil,
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

        $this->updateNextcloudAccount($userId, $email, $phone, $location);
    }

    /**
     * Mirror email/phone/address onto the Nextcloud account — but only for
     * fields that actually have a value. An empty value from Serviceplatformen
     * must never overwrite what's already set in Nextcloud.
     */
    private function updateNextcloudAccount(string $userId, string $email, string $phone, string $location): void {
        $ncUser = $this->userManager->get($userId);
        if ($ncUser === null) {
            return;
        }

        if ($email !== '') {
            $ncUser->setEMailAddress($email);
        }

        // Nextcloud requires phone numbers in E.164 format (e.g. +4512345678)
        // and rejects anything else with an InvalidArgumentException when
        // the account is saved — Serviceplatformen returns plain Danish
        // local numbers (e.g. "12345678"), so normalize first and just skip
        // the phone if it can't be parsed, rather than letting the whole
        // account update (including the address) fail.
        $normalizedPhone = $phone !== '' ? $this->phoneNumberUtil->convertToStandardFormat($phone, 'DK') : null;

        if ($normalizedPhone === null && $location === '') {
            return;
        }

        $account = $this->accountManager->getAccount($ncUser);

        if ($normalizedPhone !== null) {
            $account->getProperty(IAccountManager::PROPERTY_PHONE)->setValue($normalizedPhone);
        }
        if ($location !== '') {
            $account->getProperty(IAccountManager::PROPERTY_ADDRESS)->setValue($location);
        }

        $this->accountManager->updateAccount($account);
    }
}
