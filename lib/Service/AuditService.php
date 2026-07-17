<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\AuditLogEntry;
use OCA\OpenCase\Db\AuditLogMapper;

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

    public function __construct(
        private AuditLogMapper $mapper,
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

    public function logContactAdded(int $caseId, int $documentId, string $userId, string $roleName, string $name, string $cvr): void {
        $this->write($caseId, $documentId, null, $userId, 'document_contact_added', [
            'role' => $roleName,
            'name' => $name,
            'cvr'  => $cvr,
        ]);
    }

    public function logContactDeleted(int $caseId, int $documentId, string $userId, string $roleName, string $name, string $cvr): void {
        $this->write($caseId, $documentId, null, $userId, 'document_contact_deleted', [
            'role' => $roleName,
            'name' => $name,
            'cvr'  => $cvr,
        ]);
    }

    // ---------------------------------------------------------------
    // Case participant events
    // ---------------------------------------------------------------

    public function logParticipantAdded(int $caseId, string $userId, string $roleName, string $name, string $cvr): void {
        $this->write($caseId, null, null, $userId, 'case_participant_added', [
            'role' => $roleName,
            'name' => $name,
            'cvr'  => $cvr,
        ]);
    }

    public function logParticipantDeleted(int $caseId, string $userId, string $roleName, string $name, string $cvr): void {
        $this->write($caseId, null, null, $userId, 'case_participant_deleted', [
            'role' => $roleName,
            'name' => $name,
            'cvr'  => $cvr,
        ]);
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
        } catch (\Throwable) {
            // Never let audit logging fail a business operation
        }
    }
}
