<?php
declare(strict_types=1);

namespace OCA\OpenCase\Db;

use OCP\IDBConnection;

/**
 * Log of calls to the SagDokumentIndeks (SAPA) service, in opencase_casedocindex_log.
 */
class CaseDocIndexLogRepository {
	public const STATUS_CREATED = 'created';
	public const STATUS_UPDATED = 'updated';
	public const STATUS_DELETED = 'deleted';
	public const STATUS_FAILED = 'failed';

	public function __construct(
		private IDBConnection $db,
	) {}

	public function insert(string $caseUuid, string $createdBy, string $status, ?string $errMessage = null, ?string $documentUuid = null): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('opencase_casedocindex_log')
			->values([
				'case_uuid' => $qb->createNamedParameter($caseUuid),
				'document_uuid' => $qb->createNamedParameter($documentUuid),
				'created_at' => $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s')),
				'created_by' => $qb->createNamedParameter($createdBy),
				'err_message' => $qb->createNamedParameter($errMessage),
				'status' => $qb->createNamedParameter($status),
			])->executeStatement();
	}
}
