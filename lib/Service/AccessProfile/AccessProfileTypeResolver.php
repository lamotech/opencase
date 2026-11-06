<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\AccessProfile;

use OCA\OpenCase\Db\AccessProfile;
use OCA\OpenCase\Service\Configuration;

/**
 * Picks the AccessProfileType implementation.
 *
 *   active()     — the type new profiles are created with, from the
 *                  'accessprofile_type' config ('fk' = FK Adgang, 'group' =
 *                  Grupper). Use when creating or changing a case's profile.
 *   forProfile() — the type of an existing profile. Profiles of both types can
 *                  coexist (e.g. after the config was changed), so anything
 *                  acting on an existing profile must use this.
 *   resolveInboxProfile() — the access profile for an organisation's inbox case.
 */
class AccessProfileTypeResolver {

    public const CONFIG_KEY = 'accessprofile_type';

    /** Group whose members get access to inbox cases in group mode. */
    public const INBOX_GROUP_CONFIG_KEY = 'incoming_group';

    public function __construct(
        private Configuration $configuration,
        private FkAccessProfileType $fkType,
        private GroupAccessProfileType $groupType,
    ) {}

    public function active(): AccessProfileType {
        return $this->byKey($this->configuration->getConfigValue(self::CONFIG_KEY, AccessProfile::TYPE_FK) ?? AccessProfile::TYPE_FK);
    }

    public function activeKey(): string {
        return $this->active()->key();
    }

    public function isGroupMode(): bool {
        return $this->activeKey() === AccessProfile::TYPE_GROUP;
    }

    /**
     * Resolve the access profile for an organisation's inbox case.
     *
     * FK mode: the profile for the org plus the inbox KLE/sensitivity, which
     * $fkInput returns (see FkAccessProfileType::resolveProfile). It is only
     * called in FK mode, so callers can look up and validate the configured
     * KLE/sensitivity there. Group mode: the profile for the 'incoming_group'
     * config.
     *
     * @param callable(): array<string, mixed> $fkInput
     * @throws \RuntimeException in group mode when no inbox group is configured
     */
    public function resolveInboxProfile(string $orgUuid, callable $fkInput): AccessProfile {
        $type = $this->active();
        if ($type->key() === AccessProfile::TYPE_GROUP) {
            $groupId = (string)$this->configuration->getConfigValue(self::INBOX_GROUP_CONFIG_KEY, '');
            if ($groupId === '') {
                throw new \RuntimeException('No inbox group configured (' . self::INBOX_GROUP_CONFIG_KEY . ')');
            }
            return $this->groupType->register($groupId);
        }
        return $type->resolveProfile(['org_uuid' => $orgUuid] + $fkInput());
    }

    public function forProfile(AccessProfile $profile): AccessProfileType {
        return $this->byKey($profile->getProfileType());
    }

    private function byKey(string $key): AccessProfileType {
        return match ($key) {
            AccessProfile::TYPE_FK    => $this->fkType,
            AccessProfile::TYPE_GROUP => $this->groupType,
            default                   => throw new \RuntimeException("Unsupported access profile type: {$key}"),
        };
    }
}
