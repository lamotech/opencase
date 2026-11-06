<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\AccessProfile;

use OCA\OpenCase\Db\AccessProfile;

/**
 * One kind of access profile (see AccessProfile::TYPE_*).
 *
 * A type knows how to turn caller input into an access profile row and how
 * to populate opencase_user_access for it. Everything that only checks
 * access (CaseMapper joins, PermissionService, Elasticsearch, storage) works
 * on profile ids and is type-agnostic.
 *
 * Use AccessProfileTypeResolver to get the active type (from the
 * 'accessprofile_type' config) or the type of an existing profile.
 */
interface AccessProfileType {

    /**
     * The profile_type value this type stores, e.g. AccessProfile::TYPE_FK.
     */
    public function key(): string;

    /**
     * The access input fields a caller must supply to resolveProfile() when
     * creating a case (in addition to the case's organisation), e.g. for
     * request validation.
     *
     * @return string[]
     */
    public function requiredInputFields(): array;

    /**
     * Find or create the access profile described by $input, and grant it to
     * the users entitled to it. Idempotent.
     *
     * @param array<string, mixed> $input Type-specific; see the implementation.
     * @throws \InvalidArgumentException when required input is missing
     */
    public function resolveProfile(array $input): AccessProfile;

    /**
     * Grant the profile to every user entitled to it. Idempotent — existing
     * grants are left as they are.
     */
    public function grantUsers(AccessProfile $profile): void;
}
