<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\AccessProfileMapper;
use OCA\OpenCase\Db\CaseEntity;
use OCA\OpenCase\Db\CaseStatusMapper;
use OCA\OpenCase\Db\ClassificationFacetRepository;
use OCA\OpenCase\Db\ClassificationSubjectMapper;
use OCA\OpenCase\Db\DeletionDeadlineMapper;
use OCA\OpenCase\Db\DisposalCodeMapper;

/**
 * Disposal (kassation) rules for cases.
 *
 *   - A case's disposal code is derived from its classification subject
 *     (via the access profile) and its facet: the strictest code set on
 *     either wins, in the order B > K10 > K5, falling back to K.
 *   - Closing a case stamps closed_date and computes disposal_date from
 *     the disposal code's disposal_years (null for B = never disposed of).
 *   - Closing a case also picks the deletion deadline (slettefrist) with the
 *     fewest deletion_days among those set on its subject and facet, and
 *     computes deletion_date as closed_date + deletion_days.
 *
 * Callers apply these to the entity before persisting it; nothing here
 * writes to the database.
 */
class DisposalService {

    public const CODE_B   = 1;
    public const CODE_K   = 2;
    public const CODE_K10 = 3;
    public const CODE_K5  = 4;

    /** Codes that override the default K, highest precedence first. */
    private const PRECEDENCE = [self::CODE_B, self::CODE_K10, self::CODE_K5];

    public function __construct(
        private AccessProfileMapper $accessProfileMapper,
        private ClassificationSubjectMapper $classificationSubjectMapper,
        private ClassificationFacetRepository $classificationFacetRepository,
        private DisposalCodeMapper $disposalCodeMapper,
        private DeletionDeadlineMapper $deletionDeadlineMapper,
        private CaseStatusMapper $caseStatusMapper,
    ) {
    }

    /**
     * Set the case's disposal code from its current classification subject
     * and facet. Call whenever either may have changed (create, reclassify).
     */
    public function applyDisposalCode(CaseEntity $case): void {
        $candidates = $this->classificationValues($case, 'disposalcode_id');

        $disposalcodeId = self::CODE_K;
        foreach (self::PRECEDENCE as $code) {
            if (in_array($code, $candidates, true)) {
                $disposalcodeId = $code;
                break;
            }
        }

        if ($disposalcodeId !== $case->getDisposalcodeId()) {
            $case->setDisposalcodeId($disposalcodeId);
        }
    }

    /**
     * Update closed_date / disposal_date / deletion deadline for a status
     * transition.
     *
     * Open → closed stamps closed_date and computes disposal_date and the
     * deletion deadline. Closed → open clears them all, so a reopened case is
     * never picked up for disposal or deletion.
     * Closed → closed (e.g. closed → archived) leaves them untouched.
     */
    public function applyStatusChange(CaseEntity $case, int $oldStatusId, int $newStatusId): void {
        $wasClosed = $this->caseStatusMapper->isClosed($oldStatusId);
        $isClosed  = $this->caseStatusMapper->isClosed($newStatusId);

        if ($isClosed && !$wasClosed) {
            // Cases created before disposal codes existed have none yet
            if ($case->getDisposalcodeId() === null) {
                $this->applyDisposalCode($case);
            }
            $now = new \DateTime();
            $case->setClosedDate($now);
            $case->setDisposalDate($this->computeDisposalDate($case->getDisposalcodeId(), $now));
            $this->applyDeletionDeadline($case, $now);
        } elseif (!$isClosed && $wasClosed) {
            $case->setClosedDate(null);
            $case->setDisposalDate(null);
            $case->setDeletionDeadlineId(null);
            $case->setDeletionDate(null);
        }
    }

    /**
     * Set the case's deletion deadline to the shortest one (fewest
     * deletion_days) found on its classification subject and facet, and
     * deletion_date to closed date + deletion_days. Neither set → both null.
     */
    private function applyDeletionDeadline(CaseEntity $case, \DateTime $closedDate): void {
        $shortest = null;
        foreach (array_unique(array_filter($this->classificationValues($case, 'deletion_deadline_id'))) as $deadlineId) {
            $deadline = $this->deletionDeadlineMapper->findById($deadlineId);
            if ($deadline === null || $deadline->getDeletionDays() === null) {
                continue;
            }
            if ($shortest === null || $deadline->getDeletionDays() < $shortest->getDeletionDays()) {
                $shortest = $deadline;
            }
        }

        $case->setDeletionDeadlineId($shortest?->getId());
        $case->setDeletionDate($shortest !== null
            ? (clone $closedDate)->modify("+{$shortest->getDeletionDays()} days")
            : null);
    }

    /**
     * The given lookup id (disposalcode_id / deletion_deadline_id) as set on
     * the case's classification subject (via the access profile) and facet.
     * Entries are null where the subject/facet is missing or has none set.
     *
     * @return list<int|null>
     */
    private function classificationValues(CaseEntity $case, string $column): array {
        $values = [];

        $profile = $case->getAccessProfileId() ? $this->accessProfileMapper->findById($case->getAccessProfileId()) : null;
        if ($profile !== null && $profile->getClassSubjectUuid()) {
            $subject = $this->classificationSubjectMapper->findByUuid($profile->getClassSubjectUuid());
            $values[] = match ($column) {
                'disposalcode_id'      => $subject?->getDisposalcodeId(),
                'deletion_deadline_id' => $subject?->getDeletionDeadlineId(),
            };
        }

        $facetUuid = $case->getClassificationFacetUuid();
        if ($facetUuid) {
            $value = $this->classificationFacetRepository->find($facetUuid)[$column] ?? null;
            $values[] = $value !== null ? (int)$value : null;
        }

        return $values;
    }

    private function computeDisposalDate(?int $disposalcodeId, \DateTime $closedDate): ?\DateTime {
        if ($disposalcodeId === null || $disposalcodeId === self::CODE_B) {
            return null;
        }
        $years = $this->disposalCodeMapper->findById($disposalcodeId)?->getDisposalYears();
        if ($years === null) {
            return null;
        }
        return (clone $closedDate)->modify("+{$years} years");
    }
}
