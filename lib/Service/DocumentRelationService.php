<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\Document;
use OCA\OpenCase\Db\DocumentMapper;
use OCA\OpenCase\Db\DocumentRelation;
use OCA\OpenCase\Db\DocumentRelationMapper;
use OCA\OpenCase\Db\DocumentRelationTypeMapper;

/**
 * Creating and removing document relations. Shared by the internal REST API
 * (DocumentRelationController) and the public data API
 * (PublicDocumentApiController). Callers do the write-access check on the
 * document itself; this service checks read access on the related ones.
 */
class DocumentRelationService {

    /** opencase_documentrelationtype id for "Bilag" */
    public const TYPE_ATTACHMENT = 1;

    public function __construct(
        private DocumentRelationMapper $relationMapper,
        private DocumentRelationTypeMapper $typeMapper,
        private DocumentMapper $documentMapper,
        private PermissionService $permissionService,
        private AuditService $auditService,
    ) {}

    /** @return array<int, string> id → name, falling back to English */
    public function typeNames(string $language): array {
        $names = $this->typeMapper->getNameMap($language);
        return empty($names) ? $this->typeMapper->getNameMap('en') : $names;
    }

    /**
     * Ids of the document's attachments (documents it points to with type
     * Bilag) that the user can read, in the order they were added.
     *
     * @return int[]
     */
    public function readableAttachmentIds(int $documentId, string $userId): array {
        return array_values(array_filter(
            $this->relationMapper->findTargetDocumentIds($documentId, self::TYPE_ATTACHMENT),
            fn (int $id) => $this->permissionService->userHasReadAccessToDocument($userId, $id),
        ));
    }

    /**
     * Relate $documentIds to $document with $document as the from-side.
     * Every target is validated before anything is inserted; pairs that
     * already have a relation of the same type are skipped.
     *
     * @param int[] $documentIds
     * @return DocumentRelation[] the relations created
     * @throws \InvalidArgumentException unknown type or no targets
     * @throws NotFoundException a target doesn't exist or isn't readable
     */
    public function addRelations(Document $document, array $documentIds, int $typeId, string $userId, string $language): array {
        $documentId = $document->getId();
        $typeNames  = $this->typeNames($language);
        if (!isset($typeNames[$typeId])) {
            throw new \InvalidArgumentException('Unknown relation type');
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $documentIds),
            fn ($id) => $id > 0 && $id !== $documentId,
        )));
        if (empty($ids)) {
            throw new \InvalidArgumentException('At least one related document is required');
        }

        $related = array_flip($this->relationMapper->findRelatedDocumentIds($documentId, $typeId));
        $targets = [];
        foreach ($ids as $otherId) {
            if (isset($related[$otherId])) {
                continue;
            }
            $other = $this->documentMapper->findById($otherId);
            if ($other === null || !$this->permissionService->userHasReadAccessToDocument($userId, $otherId)) {
                throw new NotFoundException("Document {$otherId} not found");
            }
            $targets[$otherId] = $other;
        }

        $created = [];
        foreach ($targets as $otherId => $other) {
            $relation = new DocumentRelation();
            $relation->setDocumentFromId($documentId);
            $relation->setDocumentToId($otherId);
            $relation->setDocumentrelationtypeId($typeId);
            $relation->setCreatedAt(new \DateTime());
            $relation->setCreatedBy($userId);
            $created[] = $this->relationMapper->insert($relation);

            $this->auditService->logDocumentRelationAdded(
                $document->getCaseId(), $documentId, $userId, $typeNames[$typeId],
                $otherId, $other->getDocumentNumber(), $other->getTitle()
            );
        }

        return $created;
    }

    /**
     * Remove a relation that has $document on either side.
     *
     * @throws NotFoundException the relation doesn't exist or doesn't involve $document
     */
    public function deleteRelation(Document $document, int $relationId, string $userId, string $language): void {
        $documentId = $document->getId();
        $relation   = $this->relationMapper->findById($relationId);
        if ($relation === null
            || ($relation->getDocumentFromId() !== $documentId && $relation->getDocumentToId() !== $documentId)) {
            throw new NotFoundException('Relation not found');
        }

        $this->relationMapper->delete($relation);

        $otherId = $relation->getDocumentFromId() === $documentId ? $relation->getDocumentToId() : $relation->getDocumentFromId();
        $other   = $this->documentMapper->findById($otherId);
        $this->auditService->logDocumentRelationDeleted(
            $document->getCaseId(), $documentId, $userId,
            $this->typeNames($language)[$relation->getDocumentrelationtypeId()] ?? '',
            $otherId, $other?->getDocumentNumber(), $other?->getTitle() ?? ''
        );
    }
}
