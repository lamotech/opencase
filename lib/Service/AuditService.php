<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\AuditLogEntry;
use OCA\OpenCase\Db\AuditLogMapper;
use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\ContactTypeMapper;

/**
 * Records auditable events on cases and documents to opencase_audit_log.
 *
 * Each log method is fire-and-forget: errors are silently swallowed so
 * that a logging failure never aborts a business operation.
 *
 * document_id = NULL  → case-level event
 * document_id = value → document-level event
 */
class AuditService {

    /**
     * Changes to a case's documents and files that count as activity on the
     * case: they bump the case's updated_at, which processes waiting for
     * inactivity look at and which makes the SAPA job re-send the case.
     * (File contents saved through Office are handled by FileUpdatedListener.)
     */
    private const CASE_ACTIVITY_EVENTS = [
        'document_created', 'document_metadata_changed', 'document_status_changed',
        'document_deleted', 'document_restored', 'document_moved_to_case',
        'document_relation_added', 'document_relation_deleted',
        'document_note_created', 'document_note_updated', 'document_note_deleted',
        'file_uploaded', 'file_renamed', 'file_deleted', 'file_restored',
        'file_version_restored', 'file_edited_via_share',
    ];

    public function __construct(
        private AuditLogMapper $mapper,
        private HistoryService $historyService,
        private CaseMapper $caseMapper,
    ) {
    }

    // ---------------------------------------------------------------
    // Case-level events
    // ---------------------------------------------------------------

    public function logCaseCreated(int $caseId, string $userId): void {
        $this->write($caseId, null, null, $userId, 'case_created', []);
    }

    public function logCaseViewed(int $caseId, string $userId): void {
        $this->write($caseId, null, null, $userId, 'case_viewed', []);
        $this->historyService->recordCaseAccess($caseId, $userId);
    }

    /**
     * @param array<string, array{from: mixed, to: mixed}> $changes
     */
    public function logCaseMetadataChanged(int $caseId, string $userId, array $changes): void {
        if (empty($changes)) {
            return;
        }
        $this->write($caseId, null, null, $userId, 'case_metadata_changed', $changes);
    }

    public function logCaseStatusChanged(int $caseId, string $userId, int $fromStatusId, int $toStatusId): void {
        $this->write($caseId, null, null, $userId, 'case_status_changed', [
            'from_status_id' => $fromStatusId,
            'to_status_id'   => $toStatusId,
        ]);
    }

    // ---------------------------------------------------------------
    // Document-level events
    // ---------------------------------------------------------------

    public function logDocumentCreated(int $caseId, string $userId, int $documentId, string $documentNumber, string $title): void {
        $this->write($caseId, $documentId, null, $userId, 'document_created', [
            'document_number' => $documentNumber,
            'title'           => $title,
        ]);
    }

    public function logDocumentDeleted(int $caseId, int $documentId, string $userId, ?string $documentNumber, string $title, bool $soft): void {
        $this->write($caseId, $documentId, null, $userId, 'document_deleted', [
            'document_number' => $documentNumber,
            'title'           => $title,
            'soft'            => $soft,
        ]);
    }

    public function logDocumentRestored(int $caseId, int $documentId, string $userId, ?string $documentNumber, string $title): void {
        $this->write($caseId, $documentId, null, $userId, 'document_restored', [
            'document_number' => $documentNumber,
            'title'           => $title,
        ]);
    }

    public function logDocumentMovedToCase(
        int    $targetCaseId,
        int    $documentId,
        string $userId,
        string $fromCaseNumber,
        string $toCaseNumber,
        string $newDocumentNumber,
    ): void {
        $this->write($targetCaseId, $documentId, null, $userId, 'document_moved_to_case', [
            'from_case_number'    => $fromCaseNumber,
            'to_case_number'      => $toCaseNumber,
            'new_document_number' => $newDocumentNumber,
        ]);
    }

    public function logDocumentViewed(int $caseId, int $documentId, string $userId): void {
        $this->write($caseId, $documentId, null, $userId, 'document_viewed', []);
        $this->historyService->recordDocumentAccess($documentId, $userId);
    }

    /**
     * @param array<string, array{from: mixed, to: mixed}> $changes
     */
    public function logDocumentMetadataChanged(int $caseId, int $documentId, string $userId, array $changes): void {
        if (empty($changes)) {
            return;
        }
        $this->write($caseId, $documentId, null, $userId, 'document_metadata_changed', $changes);
    }

    public function logDocumentStatusChanged(int $caseId, int $documentId, string $userId, int $fromStatusId, int $toStatusId): void {
        $this->write($caseId, $documentId, null, $userId, 'document_status_changed', [
            'from_status_id' => $fromStatusId,
            'to_status_id'   => $toStatusId,
        ]);
    }

    public function logFileUploaded(int $caseId, int $documentId, int $fileId, string $userId, string $filename): void {
        $this->write($caseId, $documentId, $fileId, $userId, 'file_uploaded', [
            'filename' => $filename,
        ]);
    }

    public function logFileViewed(int $caseId, int $documentId, int $fileId, string $userId, string $filename): void {
        $this->write($caseId, $documentId, $fileId, $userId, 'file_viewed', [
            'filename' => $filename,
        ]);
    }

    public function logFileEdited(int $caseId, int $documentId, int $fileId, string $userId, string $filename): void {
        $this->write($caseId, $documentId, $fileId, $userId, 'file_edited', [
            'filename' => $filename,
        ]);
    }

    public function logFileDownloaded(int $caseId, int $documentId, int $fileId, string $userId, string $filename): void {
        $this->write($caseId, $documentId, $fileId, $userId, 'file_downloaded', [
            'filename' => $filename,
        ]);
    }

    public function logFileDeleted(int $caseId, int $documentId, int $fileId, string $userId, string $filename, bool $soft): void {
        $this->write($caseId, $documentId, $fileId, $userId, 'file_deleted', [
            'filename' => $filename,
            'soft'     => $soft,
        ]);
    }

    public function logFileRestored(int $caseId, int $documentId, int $fileId, string $userId, string $filename): void {
        $this->write($caseId, $documentId, $fileId, $userId, 'file_restored', [
            'filename' => $filename,
        ]);
    }

    public function logFileRenamed(int $caseId, int $documentId, int $fileId, string $userId, string $from, string $to): void {
        $this->write($caseId, $documentId, $fileId, $userId, 'file_renamed', [
            'filename'      => $to,
            'from_filename' => $from,
        ]);
    }

    public function logFileVersionViewed(int $caseId, int $documentId, int $fileId, string $userId, string $filename, int $versionTimestamp): void {
        $this->write($caseId, $documentId, $fileId, $userId, 'file_version_viewed', [
            'filename'          => $filename,
            'version_timestamp' => $versionTimestamp,
        ]);
    }

    public function logFileVersionDownloaded(int $caseId, int $documentId, int $fileId, string $userId, string $filename, int $versionTimestamp): void {
        $this->write($caseId, $documentId, $fileId, $userId, 'file_version_downloaded', [
            'filename'          => $filename,
            'version_timestamp' => $versionTimestamp,
        ]);
    }

    public function logFileVersionRestored(int $caseId, int $documentId, int $fileId, string $userId, string $filename, int $versionTimestamp): void {
        $this->write($caseId, $documentId, $fileId, $userId, 'file_version_restored', [
            'filename'          => $filename,
            'version_timestamp' => $versionTimestamp,
        ]);
    }

    // ---------------------------------------------------------------
    // Document contact events
    // ---------------------------------------------------------------

    public function logContactAdded(int $caseId, int $documentId, string $userId, string $roleName, string $name, string $cprCvr): void {
        $this->write($caseId, $documentId, null, $userId, 'document_contact_added', $this->partyDetails($roleName, $name, $cprCvr));
    }

    public function logContactDeleted(int $caseId, int $documentId, string $userId, string $roleName, string $name, string $cprCvr): void {
        $this->write($caseId, $documentId, null, $userId, 'document_contact_deleted', $this->partyDetails($roleName, $name, $cprCvr));
    }

    // ---------------------------------------------------------------
    // Document relation events
    // ---------------------------------------------------------------

    public function logDocumentRelationAdded(int $caseId, int $documentId, string $userId, string $typeName, int $relatedDocumentId, ?string $relatedDocumentNumber, string $relatedTitle): void {
        $this->write($caseId, $documentId, null, $userId, 'document_relation_added', [
            'type'                    => $typeName,
            'related_document_id'     => $relatedDocumentId,
            'related_document_number' => $relatedDocumentNumber,
            'related_title'           => $relatedTitle,
        ]);
    }

    public function logDocumentRelationDeleted(int $caseId, int $documentId, string $userId, string $typeName, int $relatedDocumentId, ?string $relatedDocumentNumber, string $relatedTitle): void {
        $this->write($caseId, $documentId, null, $userId, 'document_relation_deleted', [
            'type'                    => $typeName,
            'related_document_id'     => $relatedDocumentId,
            'related_document_number' => $relatedDocumentNumber,
            'related_title'           => $relatedTitle,
        ]);
    }

    // ---------------------------------------------------------------
    // Case participant events
    // ---------------------------------------------------------------

    public function logParticipantAdded(int $caseId, string $userId, string $roleName, string $name, string $cprCvr): void {
        $this->write($caseId, null, null, $userId, 'case_participant_added', $this->partyDetails($roleName, $name, $cprCvr));
    }

    public function logParticipantDeleted(int $caseId, string $userId, string $roleName, string $name, string $cprCvr): void {
        $this->write($caseId, null, null, $userId, 'case_participant_deleted', $this->partyDetails($roleName, $name, $cprCvr));
    }

    /**
     * Details payload shared by the participant and document-contact events.
     *
     * A citizen's number is recorded under `cpr` and a company's under `cvr`,
     * so consumers can tell the two apart — the UI masks the last four digits
     * of a CPR, which must not happen to a public CVR. Entries written before
     * this split store both kinds under `cvr`.
     */
    private function partyDetails(string $roleName, string $name, string $cprCvr): array {
        $key = ContactTypeMapper::deriveFromCprCvr($cprCvr) === ContactTypeMapper::CITIZEN ? 'cpr' : 'cvr';

        return [
            'role' => $roleName,
            'name' => $name,
            $key   => $cprCvr,
        ];
    }

    // ---------------------------------------------------------------
    // Access grant events
    // ---------------------------------------------------------------

    public function logAccessGranted(int $caseId, string $byUserId, string $grantedUserId, bool $canWrite, ?\DateTime $expiresAt): void {
        $this->write($caseId, null, null, $byUserId, 'case_access_granted', [
            'granted_user' => $grantedUserId,
            'can_write'    => $canWrite,
            'expires_at'   => $expiresAt?->format('c'),
        ]);
    }

    public function logAccessRevoked(int $caseId, string $byUserId, string $revokedUserId): void {
        $this->write($caseId, null, null, $byUserId, 'case_access_revoked', [
            'revoked_user' => $revokedUserId,
        ]);
    }

    // ---------------------------------------------------------------
    // Caseworker events
    // ---------------------------------------------------------------

    public function logCaseworkerAdded(int $caseId, string $byUserId, string $addedUserId, string $displayName): void {
        $this->write($caseId, null, null, $byUserId, 'case_caseworker_added', [
            'added_user'   => $addedUserId,
            'display_name' => $displayName,
        ]);
    }

    public function logCaseworkerRemoved(int $caseId, string $byUserId, string $removedUserId, string $displayName): void {
        $this->write($caseId, null, null, $byUserId, 'case_caseworker_removed', [
            'removed_user' => $removedUserId,
            'display_name' => $displayName,
        ]);
    }

    // ---------------------------------------------------------------
    // Estate events
    // ---------------------------------------------------------------

    public function logEstateAdded(int $caseId, string $userId, string $roleName, string $type, ?int $bfenummer): void {
        $this->write($caseId, null, null, $userId, 'case_estate_added', [
            'role'      => $roleName,
            'type'      => $type,
            'bfenummer' => $bfenummer,
        ]);
    }

    // ---------------------------------------------------------------
    // File-share events
    // ---------------------------------------------------------------

    public function logFileShared(
        int $caseId,
        int $documentId,
        int $fileId,
        string $byUserId,
        string $sharedWithUserId,
        bool $canWrite,
        string $filename,
        ?\DateTime $expiresAt,
    ): void {
        $this->write($caseId, $documentId, $fileId, $byUserId, 'file_shared', [
            'filename'       => $filename,
            'shared_with'    => $sharedWithUserId,
            'can_write'      => $canWrite,
            'expires_at'     => $expiresAt?->format('c'),
        ]);
    }

    public function logFileShareRevoked(
        int $caseId,
        int $documentId,
        int $fileId,
        string $byUserId,
        string $revokedFromUserId,
        string $filename,
    ): void {
        $this->write($caseId, $documentId, $fileId, $byUserId, 'file_share_revoked', [
            'filename'         => $filename,
            'revoked_from'     => $revokedFromUserId,
        ]);
    }

    /**
     * Log a file edit made by a share recipient (no case access).
     * Called by the NodeWrittenListener when an OpenCase file is saved
     * via Collabora/WebDAV by a user who reached the file through a share.
     */
    public function logFileEditedViaShare(
        int $caseId,
        int $documentId,
        int $fileId,
        string $userId,
        string $filename,
    ): void {
        $this->write($caseId, $documentId, $fileId, $userId, 'file_edited', [
            'filename' => $filename,
            'via'      => 'share',
        ]);
    }

    // ---------------------------------------------------------------
    // Journal note events
    // ---------------------------------------------------------------

    public function logJournalNoteCreated(int $caseId, string $userId, int $noteId, string $title): void {
        $this->write($caseId, null, null, $userId, 'journal_note_created', [
            'note_id' => $noteId,
            'title'   => $title,
        ]);
    }

    public function logJournalNoteUpdated(int $caseId, string $userId, int $noteId, string $title): void {
        $this->write($caseId, null, null, $userId, 'journal_note_updated', [
            'note_id' => $noteId,
            'title'   => $title,
        ]);
    }

    public function logJournalNoteDeleted(int $caseId, string $userId, int $noteId, string $title): void {
        $this->write($caseId, null, null, $userId, 'journal_note_deleted', [
            'note_id' => $noteId,
            'title'   => $title,
        ]);
    }

    public function logJournalNoteLocked(int $caseId, string $userId, int $noteId, string $title): void {
        $this->write($caseId, null, null, $userId, 'journal_note_locked', [
            'note_id' => $noteId,
            'title'   => $title,
        ]);
    }

    // ---------------------------------------------------------------
    // Document note events
    // ---------------------------------------------------------------

    public function logDocumentNoteCreated(int $caseId, int $documentId, string $userId, int $noteId, string $title): void {
        $this->write($caseId, $documentId, null, $userId, 'document_note_created', [
            'note_id' => $noteId,
            'title'   => $title,
        ]);
    }

    public function logDocumentNoteUpdated(int $caseId, int $documentId, string $userId, int $noteId, string $title): void {
        $this->write($caseId, $documentId, null, $userId, 'document_note_updated', [
            'note_id' => $noteId,
            'title'   => $title,
        ]);
    }

    public function logDocumentNoteDeleted(int $caseId, int $documentId, string $userId, int $noteId, string $title): void {
        $this->write($caseId, $documentId, null, $userId, 'document_note_deleted', [
            'note_id' => $noteId,
            'title'   => $title,
        ]);
    }

    // ---------------------------------------------------------------
    // Workflow events
    // ---------------------------------------------------------------

    /**
     * @param string[] $userIds
     */
    public function logWorkflowCreated(
        int $caseId,
        int $documentId,
        int $workflowId,
        string $type,
        ?\DateTime $deadline,
        array $userIds,
        string $userId,
    ): void {
        $this->write($caseId, $documentId, null, $userId, 'workflow_created', [
            'workflow_id' => $workflowId,
            'type'        => $type,
            'deadline'    => $deadline?->format('c'),
            'users'       => $userIds,
        ]);
    }

    public function logWorkflowStepActed(
        int $caseId,
        int $documentId,
        int $workflowId,
        int $stepId,
        string $userId,
        string $action,
        ?string $comment,
    ): void {
        $this->write($caseId, $documentId, null, $userId, 'workflow_step_' . $action, [
            'workflow_id' => $workflowId,
            'step_id'     => $stepId,
            'comment'     => $comment,
        ]);
    }

    public function logWorkflowCompleted(int $caseId, int $documentId, int $workflowId): void {
        $this->write($caseId, $documentId, null, 'system', 'workflow_completed', [
            'workflow_id' => $workflowId,
        ]);
    }

    public function logWorkflowRejected(
        int $caseId,
        int $documentId,
        int $workflowId,
        string $userId,
        ?string $comment,
    ): void {
        $this->write($caseId, $documentId, null, $userId, 'workflow_rejected', [
            'workflow_id' => $workflowId,
            'comment'     => $comment,
        ]);
    }

    public function logWorkflowCancelled(int $caseId, int $documentId, int $workflowId, string $userId): void {
        $this->write($caseId, $documentId, null, $userId, 'workflow_cancelled', [
            'workflow_id' => $workflowId,
        ]);
    }

    // ---------------------------------------------------------------
    // Process events (process module)
    // ---------------------------------------------------------------

    public function logProcessStarted(int $caseId, string $userId, int $caseProcessId, string $processName, int $versionNo): void {
        $this->write($caseId, null, null, $userId, 'process_started', [
            'case_process_id' => $caseProcessId,
            'process_name'    => $processName,
            'version_no'      => $versionNo,
        ]);
    }

    public function logProcessTaskClaimed(int $caseId, string $userId, int $taskId, string $taskName): void {
        $this->write($caseId, null, null, $userId, 'process_task_claimed', [
            'task_id'   => $taskId,
            'task_name' => $taskName,
        ]);
    }

    /**
     * @param array<string, string> $fields form field label => value
     */
    public function logProcessTaskCompleted(int $caseId, string $userId, int $taskId, string $taskName, ?string $outcome, ?string $comment, array $fields = []): void {
        $this->write($caseId, null, null, $userId, 'process_task_completed', [
            'task_id'   => $taskId,
            'task_name' => $taskName,
            'outcome'   => $outcome,
            'comment'   => $comment,
            'fields'    => $fields,
        ]);
    }

    public function logProcessMigrated(int $caseId, string $userId, int $caseProcessId, string $processName, int $fromVersionNo, int $toVersionNo): void {
        $this->write($caseId, null, null, $userId, 'process_migrated', [
            'case_process_id' => $caseProcessId,
            'process_name'    => $processName,
            'from_version_no' => $fromVersionNo,
            'to_version_no'   => $toVersionNo,
        ]);
    }

    public function logProcessStepExecuted(int $caseId, string $actedAs, int $caseProcessId, string $stepName, string $topic): void {
        $this->write($caseId, null, null, $actedAs, 'process_step_executed', [
            'case_process_id' => $caseProcessId,
            'step_name'       => $stepName,
            'topic'           => $topic,
        ]);
    }

    public function logProcessCompleted(int $caseId, int $caseProcessId, string $processName): void {
        $this->write($caseId, null, null, 'system', 'process_completed', [
            'case_process_id' => $caseProcessId,
            'process_name'    => $processName,
        ]);
    }

    public function logProcessCancelled(int $caseId, string $userId, int $caseProcessId, string $processName, string $reason): void {
        $this->write($caseId, null, null, $userId, 'process_cancelled', [
            'case_process_id' => $caseProcessId,
            'process_name'    => $processName,
            'reason'          => $reason,
        ]);
    }

    // ---------------------------------------------------------------
    // Queries
    // ---------------------------------------------------------------

    /**
     * Return case-level entries + document_created events, newest first.
     *
     * @return AuditLogEntry[]
     */
    public function findByCase(int $caseId, int $limit = 25, int $offset = 0): array {
        return $this->mapper->findByCase($caseId, $limit, $offset);
    }

    public function countByCase(int $caseId): int {
        return $this->mapper->countByCase($caseId);
    }

    /**
     * Return all audit entries for a document, newest first.
     *
     * @return AuditLogEntry[]
     */
    public function findByDocument(int $documentId, int $limit = 25, int $offset = 0): array {
        return $this->mapper->findByDocument($documentId, $limit, $offset);
    }

    public function countByDocument(int $documentId): int {
        return $this->mapper->countByDocument($documentId);
    }

    /**
     * Return all audit entries for a file, newest first.
     *
     * @return AuditLogEntry[]
     */
    public function findByFile(int $fileId, int $limit = 25, int $offset = 0): array {
        return $this->mapper->findByFile($fileId, $limit, $offset);
    }

    public function countByFile(int $fileId): int {
        return $this->mapper->countByFile($fileId);
    }

    // ---------------------------------------------------------------
    // Digital Post events
    // ---------------------------------------------------------------

    /**
     * A Digital Post shipment was created and queued for sending; the
     * dispatch job logs digital_post_sent / _failed per receiver later.
     *
     * @param string[] $receiverNames
     * @param string[] $fileNames
     */
    public function logDigitalPostQueued(int $caseId, int $documentId, string $userId, int $shipmentId, array $receiverNames, array $fileNames): void {
        $this->write($caseId, $documentId, null, $userId, 'digital_post_queued', [
            'shipment_id' => $shipmentId,
            'receivers'   => $receiverNames,
            'files'       => $fileNames,
        ]);
    }

    public function logDigitalPostSent(
        int $caseId,
        int $documentId,
        string $userId,
        string $receiverName,
        string $cprCvr,
        string $messageUUID,
        string $transmissionId,
    ): void {
        $this->write($caseId, $documentId, null, $userId, 'digital_post_sent', [
            'receiver_name'  => $receiverName,
            'cpr_cvr'        => $cprCvr,
            'message_uuid'   => $messageUUID,
            'transmission_id'=> $transmissionId,
        ]);
    }

    public function logDigitalPostFailed(
        int $caseId,
        int $documentId,
        string $userId,
        string $receiverName,
        string $cprCvr,
        string $error,
    ): void {
        $this->write($caseId, $documentId, null, $userId, 'digital_post_failed', [
            'receiver_name' => $receiverName,
            'cpr_cvr'       => $cprCvr,
            'error'         => $error,
        ]);
    }

    // ---------------------------------------------------------------
    // Email events
    // ---------------------------------------------------------------

    /**
     * @param string[] $to
     * @param string[] $cc
     * @param string[] $bcc
     */
    public function logDocumentSentByEmail(
        int $caseId,
        int $documentId,
        string $userId,
        string $fromAddress,
        array $to,
        array $cc,
        array $bcc,
        string $subject,
        array $attachmentFilenames,
    ): void {
        $this->write($caseId, $documentId, null, $userId, 'document_sent_by_email', [
            'from'         => $fromAddress,
            'to'           => $to,
            'cc'           => $cc,
            'bcc'          => $bcc,
            'subject'      => $subject,
            'attachments'  => $attachmentFilenames,
        ]);
    }

    public function logDocumentSendByEmailFailed(int $caseId, int $documentId, string $userId, string $error, string $variant = 'files'): void {
        $this->write($caseId, $documentId, null, $userId, 'document_send_by_email_failed', [
            'error'   => $error,
            'variant' => $variant,
        ]);
    }

    /**
     * A link to the document in OpenCase was sent by email (no files attached).
     *
     * @param string[] $to
     * @param string[] $cc
     * @param string[] $bcc
     */
    public function logDocumentLinkSentByEmail(
        int $caseId,
        int $documentId,
        string $userId,
        string $fromAddress,
        array $to,
        array $cc,
        array $bcc,
        string $subject,
        string $link,
    ): void {
        $this->write($caseId, $documentId, null, $userId, 'document_link_sent_by_email', [
            'from'    => $fromAddress,
            'to'      => $to,
            'cc'      => $cc,
            'bcc'     => $bcc,
            'subject' => $subject,
            'link'    => $link,
        ]);
    }

    /**
     * The document's files were sent by email, converted to PDF.
     *
     * @param string[] $to
     * @param string[] $cc
     * @param string[] $bcc
     * @param string[] $pdfFilenames
     */
    public function logDocumentPdfSentByEmail(
        int $caseId,
        int $documentId,
        string $userId,
        string $fromAddress,
        array $to,
        array $cc,
        array $bcc,
        string $subject,
        array $pdfFilenames,
    ): void {
        $this->write($caseId, $documentId, null, $userId, 'document_pdf_sent_by_email', [
            'from'        => $fromAddress,
            'to'          => $to,
            'cc'          => $cc,
            'bcc'         => $bcc,
            'subject'     => $subject,
            'attachments' => $pdfFilenames,
        ]);
    }

    // ---------------------------------------------------------------
    // Distribution receipt events
    // ---------------------------------------------------------------

    public function logDistributionReceiptSent(int $caseId, int $documentId, string $userId): void {
        $this->write($caseId, $documentId, null, $userId, 'distribution_receipt_sent', []);
    }

    public function logDistributionReceiptFailed(int $caseId, int $documentId, string $userId, string $error): void {
        $this->write($caseId, $documentId, null, $userId, 'distribution_receipt_failed', [
            'error' => $error,
        ]);
    }

    public function logJournalNoteReceiptSent(int $caseId, string $userId, int $noteId, string $title): void {
        $this->write($caseId, null, null, $userId, 'journal_note_receipt_sent', [
            'note_id' => $noteId,
            'title'   => $title,
        ]);
    }

    public function logJournalNoteReceiptFailed(int $caseId, string $userId, int $noteId, string $title, string $error): void {
        $this->write($caseId, null, null, $userId, 'journal_note_receipt_failed', [
            'note_id' => $noteId,
            'title'   => $title,
            'error'   => $error,
        ]);
    }

    // ---------------------------------------------------------------
    // Access request events
    // ---------------------------------------------------------------

    public function logAccessRequestCreated(int $caseId, string $userId, int $requestId, string $type): void {
        $this->write($caseId, null, null, $userId, 'access_request_created', [
            'access_request_id' => $requestId,
            'type'              => $type,
        ]);
    }

    public function logAccessRequestStatusChanged(int $caseId, string $userId, int $requestId, string $from, string $to): void {
        $this->write($caseId, null, null, $userId, 'access_request_status_changed', [
            'access_request_id' => $requestId,
            'from'              => $from,
            'to'                => $to,
        ]);
    }

    public function logAccessRequestUpdated(int $caseId, string $userId, int $requestId): void {
        $this->write($caseId, null, null, $userId, 'access_request_updated', [
            'access_request_id' => $requestId,
        ]);
    }

    public function logAccessRequestDecision(int $caseId, string $userId, int $requestId, string $decision): void {
        $this->write($caseId, null, null, $userId, 'access_request_decision', [
            'access_request_id' => $requestId,
            'decision'          => $decision,
        ]);
    }

    public function logAccessRequestSent(int $caseId, string $userId, int $requestId, string $method): void {
        $this->write($caseId, null, null, $userId, 'access_request_sent', [
            'access_request_id' => $requestId,
            'delivery_method'   => $method,
        ]);
    }

    /**
     * @param array<string, int> $categories Masked areas per category (cpr, email, ...)
     */
    public function logAccessRequestMasked(int $caseId, string $userId, int $requestId, int $fileId, string $fileName, int $areaCount, array $categories): void {
        $this->write($caseId, null, $fileId, $userId, 'access_request_masked', [
            'access_request_id' => $requestId,
            'file_name'         => $fileName,
            'area_count'        => $areaCount,
            'categories'        => $categories,
        ]);
    }

    // Reminder events
    // ---------------------------------------------------------------

    public function logReminderCreated(int $caseId, string $userId, int $reminderId, string $title, ?string $responsibleUserId): void {
        $this->write($caseId, null, null, $userId, 'reminder_created', [
            'reminder_id'         => $reminderId,
            'title'               => $title,
            'responsible_user_id' => $responsibleUserId,
        ]);
    }

    public function logReminderUpdated(int $caseId, string $userId, int $reminderId, string $title, array $changes): void {
        $this->write($caseId, null, null, $userId, 'reminder_updated', [
            'reminder_id' => $reminderId,
            'title'       => $title,
            'changes'     => $changes,
        ]);
    }

    public function logReminderDeleted(int $caseId, string $userId, int $reminderId, string $title): void {
        $this->write($caseId, null, null, $userId, 'reminder_deleted', [
            'reminder_id' => $reminderId,
            'title'       => $title,
        ]);
    }

    // ---------------------------------------------------------------
    // CPR events
    // ---------------------------------------------------------------

    public function logCprEventReceivedOnCase(int $caseId, string $userId, string $description, string $name): void {
        $this->write($caseId, null, null, $userId, 'cpr_event_received', [
            'description' => $description,
            'name'        => $name,
        ]);
    }

    public function logCprEventReceivedOnDocument(int $caseId, int $documentId, string $userId, string $description, string $name): void {
        $this->write($caseId, $documentId, null, $userId, 'cpr_event_received', [
            'description' => $description,
            'name'        => $name,
        ]);
    }

    // ---------------------------------------------------------------

    private function write(int $caseId, ?int $documentId, ?int $fileId, string $userId, string $eventType, array $details): void {
        try {
            $entry = new AuditLogEntry();
            $entry->setCaseId($caseId);
            $entry->setDocumentId($documentId);
            $entry->setFileId($fileId);
            $entry->setUserId($userId);
            $entry->setEventType($eventType);
            $entry->setDetails(json_encode($details));
            $entry->setCreatedAt(new \DateTime());
            $this->mapper->insert($entry);
            if (in_array($eventType, self::CASE_ACTIVITY_EVENTS, true)) {
                $this->caseMapper->touch($caseId);
            }
        } catch (\Throwable) {
            // Never let audit logging fail a business operation
        }
    }
}
