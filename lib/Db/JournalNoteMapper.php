<?php

declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserSession;

/**
 * Mapper for the opencase_journalnotes table. Inserting, updating or
 * deleting a note also bumps its case's updated_at (CaseMapper::touch()).
 *
 * @extends QBMapper<JournalNote>
 */
class JournalNoteMapper extends QBMapper {

    public function __construct(
        IDBConnection $db,
        private IUserSession $userSession,
        private CaseMapper $caseMapper,
    ) {
        parent::__construct($db, 'opencase_journalnotes', JournalNote::class);
    }

    /**
     * @param JournalNote $entity
     * @return JournalNote
     */
    public function insert(Entity $entity): Entity {
        if ($entity->getUpdatedBy() === null || $entity->getUpdatedBy() === '') {
            $entity->setUpdatedBy($entity->getCreatedBy());
        }
        $entity = parent::insert($entity);
        $this->caseMapper->touch($entity->getCaseId());
        return $entity;
    }

    /**
     * Stamps updated_by with the logged-in user. Without a user session
     * (background jobs, occ) the previous value is kept, falling back to
     * created_by if it is empty.
     *
     * @param JournalNote $entity
     * @return JournalNote
     */
    public function update(Entity $entity): Entity {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid !== null && $uid !== '') {
            $entity->setUpdatedBy($uid);
        } elseif ($entity->getUpdatedBy() === null || $entity->getUpdatedBy() === '') {
            $entity->setUpdatedBy($entity->getCreatedBy());
        }
        $entity = parent::update($entity);
        $this->caseMapper->touch($entity->getCaseId());
        return $entity;
    }

    /**
     * @param JournalNote $entity
     * @return JournalNote
     */
    public function delete(Entity $entity): Entity {
        $entity = parent::delete($entity);
        $this->caseMapper->touch($entity->getCaseId());
        return $entity;
    }

    public function findById(int $id): ?JournalNote {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * @return JournalNote[]
     */
    public function findByCase(int $caseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'DESC');

        return $this->findEntities($qb);
    }
}
