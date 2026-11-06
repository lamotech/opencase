<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\ClassificationPlan;
use OCA\OpenCase\Db\ClassificationPlanMapper;
use OCA\OpenCase\Db\ClassificationPlanRepository;
use OCA\OpenCase\Db\DeletionDeadlineMapper;

/**
 * The classification plan cases are classified by — the classification_plan
 * config: 'KLE' (the KLE numbers, the default) or 'Emneplan' (the customer's
 * own plan in opencase_class_plan) — and the manual maintenance of the
 * Emneplan.
 *
 * With Emneplan the case's item is stored in opencase_cases.class_plan_uuid.
 * With FK Adgang the KLE number is still part of the access profile, so it
 * stays required there regardless of this setting.
 */
class ClassificationPlanService {

    public const CONFIG_KEY = 'classification_plan';
    public const PLAN_KLE = 'KLE';
    public const PLAN_EMNEPLAN = 'Emneplan';
    public const PLANS = [self::PLAN_KLE, self::PLAN_EMNEPLAN];
    /** Emneplan item of new inbox cases (Konfiguration → Indkommende dokumenter). */
    public const INBOX_CONFIG_KEY = 'incoming_class_plan';

    public function __construct(
        private Configuration $configuration,
        private ClassificationPlanMapper $mapper,
        private ClassificationPlanRepository $repository,
        private DeletionDeadlineMapper $deletionDeadlineMapper,
    ) {}

    public function activePlan(): string {
        $value = $this->configuration->getConfigValue(self::CONFIG_KEY, self::PLAN_KLE);
        return in_array($value, self::PLANS, true) ? $value : self::PLAN_KLE;
    }

    public function isEmneplan(): bool {
        return $this->activePlan() === self::PLAN_EMNEPLAN;
    }

    /**
     * The Emneplan item for an inbox case: the given one (from a separation
     * sheet), else the configured incoming_class_plan. Null with KLE, or when
     * neither is set or the item is no longer active.
     */
    public function inboxClassPlanUuid(?string $uuid = null): ?string {
        if (!$this->isEmneplan()) {
            return null;
        }
        foreach ([$uuid, $this->configuration->getConfigValue(self::INBOX_CONFIG_KEY, '')] as $candidate) {
            if ($candidate !== null && $candidate !== '' && $this->mapper->findByUuid($candidate)?->getActive() === 1) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Check that a case may be given the item: it must exist and be active.
     * An empty value clears the classification.
     *
     * @throws \InvalidArgumentException
     */
    public function validateForCase(?string $uuid): ?string {
        if ($uuid === null || $uuid === '') {
            return null;
        }
        $item = $this->mapper->findByUuid($uuid);
        if ($item === null || $item->getActive() !== 1) {
            throw new \InvalidArgumentException("Emneplan item not found: {$uuid}");
        }
        return $uuid;
    }

    /**
     * validateForCase() for a value being saved over $currentUuid: what is
     * already there stays valid when the item has since been deactivated.
     *
     * @throws \InvalidArgumentException
     */
    public function validateUnlessCurrent(?string $uuid, ?string $currentUuid): ?string {
        return $uuid === $currentUuid ? $uuid : $this->validateForCase($uuid);
    }

    /**
     * Resolve an Emneplan item given by uuid or by code (the uuid wins when
     * both are given) to its uuid, for callers that accept either — the
     * public API, bulk updates. Null when neither is given. $currentUuid is
     * the item the case already has, which may stay also when deactivated.
     *
     * @throws \InvalidArgumentException when no active item matches
     */
    public function resolveUuid(?string $uuid, ?string $code, ?string $currentUuid = null): ?string {
        if ($uuid !== null && $uuid !== '') {
            return $this->validateUnlessCurrent($uuid, $currentUuid);
        }
        if ($code === null || trim($code) === '') {
            return null;
        }
        $row = $this->repository->findByCode(trim($code));
        if ($row === null || ((int)$row['active'] !== 1 && $row['uuid'] !== $currentUuid)) {
            throw new \InvalidArgumentException("Emneplan item not found: {$code}");
        }
        return $row['uuid'];
    }

    /**
     * @throws \InvalidArgumentException on missing code/title or a duplicate code
     */
    public function create(string $code, string $title, string $description, bool $active, ?int $deletionDeadlineId = null): ClassificationPlan {
        [$code, $title] = $this->validate($code, $title, null);
        $deletionDeadlineId = $this->validateDeletionDeadline($deletionDeadlineId);

        $uuid = bin2hex(random_bytes(16));
        $this->repository->insert($uuid, $code, $title, trim($description), null, $deletionDeadlineId);
        if (!$active) {
            $this->repository->setActive($uuid, false);
        }
        return $this->mapper->findByUuid($uuid);
    }

    /**
     * @throws NotFoundException
     * @throws \InvalidArgumentException on missing code/title or a duplicate code
     */
    public function update(string $uuid, string $code, string $title, string $description, bool $active, ?int $deletionDeadlineId = null): ClassificationPlan {
        $existing = $this->repository->find($uuid);
        if ($existing === null) {
            throw new NotFoundException("Emneplan item not found: {$uuid}");
        }
        [$code, $title] = $this->validate($code, $title, $uuid);

        $this->repository->update(
            $uuid, $code, $title, trim($description),
            $existing['disposalcode_id'] !== null ? (int)$existing['disposalcode_id'] : null,
            $this->validateDeletionDeadline($deletionDeadlineId),
        );
        $this->repository->setActive($uuid, $active);
        return $this->mapper->findByUuid($uuid);
    }

    /**
     * Delete an item that nothing uses. Items in use can only be deactivated:
     * cases keep their classification, and scopes keep matching those cases
     * (a phrase scoped to a deleted item would silently stop being offered).
     *
     * @throws NotFoundException
     * @throws \RuntimeException when the item is in use, with a message for the user
     */
    public function delete(string $uuid): void {
        if ($this->repository->find($uuid) === null) {
            throw new NotFoundException("Emneplan item not found: {$uuid}");
        }
        $uses = $this->usesOf($uuid);
        if ($uses !== []) {
            throw new \RuntimeException('Emnet bruges af ' . implode(', ', $uses) . '. Deaktivér det i stedet.');
        }
        $this->repository->delete($uuid);
    }

    /**
     * What refers to the item, for the user.
     *
     * @return string[]
     */
    private function usesOf(string $uuid): array {
        $scopes = $this->repository->countScopes($uuid);
        $uses = [];
        foreach ([
            '%d sag(er)'          => $this->repository->countCases($uuid),
            '%d frase(r)'         => $scopes['phrases'],
            '%d skabelon(er)'     => $scopes['templates'],
            '%d proces(ser)'      => $scopes['processes'],
            '%d tillægsfelt(er)'  => $scopes['custom_fields'],
            '%d separationsark'   => $this->repository->countSeparationSheets($uuid),
        ] as $label => $count) {
            if ($count > 0) {
                $uses[] = sprintf($label, $count);
            }
        }
        if ($this->configuration->getConfigValue(self::INBOX_CONFIG_KEY, '') === $uuid) {
            $uses[] = 'indstillingen for indkommende dokumenter';
        }
        return $uses;
    }

    /**
     * @throws \InvalidArgumentException when the deletion deadline doesn't exist
     */
    private function validateDeletionDeadline(?int $id): ?int {
        if ($id === null || $id === 0) {
            return null;
        }
        if ($this->deletionDeadlineMapper->findById($id) === null) {
            throw new \InvalidArgumentException("Deletion deadline not found: {$id}");
        }
        return $id;
    }

    /**
     * Resolve a deletion deadline (slettefrist) given in an Emneplan CSV file
     * — by id, code (e.g. P1825D) or name (e.g. "5 år"), ignoring case — to
     * its id. Null for an empty value.
     *
     * @throws \InvalidArgumentException when nothing matches
     */
    public function resolveDeletionDeadline(string $value): ?int {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $wanted = mb_strtolower($value);
        foreach ($this->deletionDeadlineMapper->findAll() as $deadline) {
            if ((string)$deadline->getId() === $value
                || mb_strtolower($deadline->getCode()) === $wanted
                || mb_strtolower($deadline->getName()) === $wanted) {
                return $deadline->getId();
            }
        }
        throw new \InvalidArgumentException("Slettefrist not found: {$value}");
    }

    /**
     * @return array{0: string, 1: string} trimmed code and title
     */
    private function validate(string $code, string $title, ?string $uuid): array {
        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException('Title is required');
        }
        // Plans that identify a topic by its name have no separate code
        $code = trim($code) ?: $title;
        $sameCode = $this->repository->findByCode($code);
        if ($sameCode !== null && $sameCode['uuid'] !== $uuid) {
            throw new \InvalidArgumentException("Code already exists: {$code}");
        }
        return [$code, $title];
    }

    public static function serialize(ClassificationPlan $item): array {
        return [
            'uuid'        => $item->getUuid(),
            'code'        => $item->getCode(),
            'title'       => $item->getTitle(),
            'description' => $item->getDescription() ?? '',
            'active'      => $item->getActive() === 1,
            'deletion_deadline_id' => $item->getDeletionDeadlineId(),
        ];
    }
}
