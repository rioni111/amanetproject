<?php

namespace App\Enums;

/**
 * Catalogue of activity event types. Matter, task, note and client events are emitted from
 * Phase 2, document events from Phase 3 and request/link/review events from Phase 4 — always inside
 * the transaction of the change they record. The signing cases document what Phase 5 will append.
 *
 * The column is a plain string (no CHECK constraint), so adding cases needs no migration.
 */
enum ActivityEventType: string
{
    use HasValues;

    // Phase 1–2: matter management
    case MatterCreated = 'matter.created';
    case MatterUpdated = 'matter.updated';
    case MatterStatusChanged = 'matter.status_changed';
    case MatterPriorityChanged = 'matter.priority_changed';
    case MatterArchived = 'matter.archived';
    case MatterRestored = 'matter.restored';
    case TaskCreated = 'task.created';
    case TaskUpdated = 'task.updated';
    case TaskCompleted = 'task.completed';
    case TaskReopened = 'task.reopened';
    case TaskDeleted = 'task.deleted';
    case NoteAdded = 'note.added';
    case NoteUpdated = 'note.updated';
    case NoteDeleted = 'note.deleted';

    // Phase 2: client records (not linked to a matter: matter_id is null)
    case ClientCreated = 'client.created';
    case ClientUpdated = 'client.updated';
    case ClientDeleted = 'client.deleted';

    // Phase 3: document library (download = audited file access; inline previews are not logged)
    case DocumentUploaded = 'document.uploaded';
    case DocumentVersionAdded = 'document.version_added';
    case DocumentUpdated = 'document.updated';
    case DocumentArchived = 'document.archived';
    case DocumentRestored = 'document.restored';
    case DocumentDownloaded = 'document.downloaded';

    // Phase 4: client document requests, secure upload links, submissions and review.
    // Metadata never contains tokens, token hashes, storage paths, client messages or correction text.
    case DocumentRequested = 'document.requested';            // request created
    case DocumentRequestUpdated = 'document_request.updated';
    case DocumentRequestCancelled = 'document_request.cancelled';
    case LinkCreated = 'link.created';                        // upload link issued
    case LinkRotated = 'link.rotated';                        // replacement issued, previous one revoked
    case LinkRevoked = 'link.revoked';                        // revoked by an admin or by archiving the matter
    case DocumentSubmitted = 'document.submitted';            // first client upload (actor: client)
    case DocumentResubmitted = 'document.resubmitted';        // corrected client upload (actor: client)
    case DocumentReviewStarted = 'document.review_started';
    case DocumentApproved = 'document.approved';
    case CorrectionRequested = 'document.correction_requested';
    case DocumentReviewed = 'document.reviewed';              // reserved (catalogue)
    case ClientResponded = 'client.responded';                // reserved (catalogue)

    // Phase 5: electronic signing. Metadata never contains tokens, signature strokes, PDF content or paths.
    case SigningRequested = 'signing.requested';
    case SigningLinkCreated = 'signing.link_created';
    case SigningLinkRotated = 'signing.link_rotated';
    case SigningLinkRevoked = 'signing.link_revoked';
    case SigningInvitationShared = 'signing.invitation_shared';  // confirmed by the administrator (manual channel)
    case SigningViewed = 'signing.viewed';                       // actor: client
    case SigningStarted = 'signing.started';                     // actor: client
    case SigningCompleted = 'signing.completed';                 // signature record committed
    case SigningCancelled = 'signing.cancelled';
    case DocumentSigned = 'document.signed';                     // the signed PDF version was added

    // Phase 6: e-mail communication. Recorded when the outcome is KNOWN (never when merely attempted).
    // Metadata: delivery id, communication type, trigger, request ids, recipient snapshot, failure category —
    // never the action link, its token or token hash, SMTP credentials or the message body.
    case EmailAccepted = 'email.accepted';                       // the SMTP server accepted the message
    case EmailFailed = 'email.failed';                           // the attempt failed (safe category recorded)
}
