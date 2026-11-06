<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\AccessProfile;
use OCA\OpenCase\Service\AccessProfile\AccessProfileTypeResolver;

/**
 * The customer type — the customer_type config, chosen under Version on the
 * admin settings page:
 *
 *   'municipality' (Kommune) — Serviceplatformen integration; FK Adgang or
 *                  Grupper; always the KLE classification plan.
 *   'other'        (Anden offentlig myndighed) — no Serviceplatformen; always
 *                  Grupper and an Emneplan; organisations from the CSV import.
 *
 * Choosing a type sets accessprofile_type and classification_plan to its
 * defaults; with 'other' FK Adgang and KLE cannot be chosen.
 */
class CustomerTypeService {

    public const CONFIG_KEY = 'customer_type';
    public const TYPE_MUNICIPALITY = 'municipality';
    public const TYPE_OTHER = 'other';
    public const TYPES = [self::TYPE_MUNICIPALITY, self::TYPE_OTHER];

    public function __construct(
        private Configuration $configuration,
    ) {}

    /**
     * The configured type. Before one has been chosen: 'other' for an
     * installation already set up with Grupper and an Emneplan, else
     * 'municipality'.
     */
    public function activeType(): string {
        $value = $this->configuration->getConfigValue(self::CONFIG_KEY, '');
        if (in_array($value, self::TYPES, true)) {
            return $value;
        }
        $groupMode = $this->configuration->getConfigValue(AccessProfileTypeResolver::CONFIG_KEY, AccessProfile::TYPE_FK) === AccessProfile::TYPE_GROUP;
        $emneplan  = $this->configuration->getConfigValue(ClassificationPlanService::CONFIG_KEY, ClassificationPlanService::PLAN_KLE) === ClassificationPlanService::PLAN_EMNEPLAN;
        return $groupMode && $emneplan ? self::TYPE_OTHER : self::TYPE_MUNICIPALITY;
    }

    /** Whether a type has been chosen (else activeType() is derived). */
    public function isChosen(): bool {
        return in_array($this->configuration->getConfigValue(self::CONFIG_KEY, ''), self::TYPES, true);
    }

    public function isMunicipality(): bool {
        return $this->activeType() === self::TYPE_MUNICIPALITY;
    }

    /**
     * Choose the customer type and set the access profile type and
     * classification plan to its defaults.
     *
     * @return array<string, string> the config values set
     * @throws \InvalidArgumentException for an unknown type
     */
    public function setType(string $type): array {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Invalid customer type: {$type}");
        }
        $values = $type === self::TYPE_MUNICIPALITY
            ? [AccessProfileTypeResolver::CONFIG_KEY => AccessProfile::TYPE_FK, ClassificationPlanService::CONFIG_KEY => ClassificationPlanService::PLAN_KLE]
            : [AccessProfileTypeResolver::CONFIG_KEY => AccessProfile::TYPE_GROUP, ClassificationPlanService::CONFIG_KEY => ClassificationPlanService::PLAN_EMNEPLAN];
        $values = [self::CONFIG_KEY => $type] + $values;
        foreach ($values as $key => $value) {
            $this->configuration->setConfigValue($key, $value);
        }
        return $values;
    }

    /**
     * Why $value cannot be saved for $key with the current customer type, or
     * null when it can: with 'other' only Grupper and an Emneplan, with
     * 'municipality' only KLE.
     */
    public function rejectionFor(string $key, string $value): ?string {
        $municipality = $this->isMunicipality();
        if ($key === AccessProfileTypeResolver::CONFIG_KEY && !$municipality && $value !== AccessProfile::TYPE_GROUP) {
            return 'FK Adgang kan kun vælges af kommuner';
        }
        if ($key === ClassificationPlanService::CONFIG_KEY) {
            if (!$municipality && $value !== ClassificationPlanService::PLAN_EMNEPLAN) {
                return 'KLE kan kun vælges af kommuner';
            }
            if ($municipality && $value !== ClassificationPlanService::PLAN_KLE) {
                return 'Kommuner klassificerer efter KLE';
            }
        }
        return null;
    }
}
