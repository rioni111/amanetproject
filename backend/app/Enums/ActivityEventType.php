<?php

namespace App\Enums;

/**
 * Catalogue of activity event types. Matter, task, note and client events are emitted from
 * Phase 2 onwards (always inside the transaction of the change they record); the document,
 * request and signing cases document the events later phases will append.
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

    // Phase 3–4: documents and client requests
    case DocumentUploaded = 'document.uploaded';
    case DocumentVersionAdded = 'document.version_added';
    case DocumentRequested = 'document.requested';
    case DocumentReviewed = 'document.reviewed';
    case ClientResponded = 'client.responded';

    // Phase 5–6: signing
    case SigningRequested = 'signing.requested';
    case SigningViewed = 'signing.viewed';
    case SigningStarted = 'signing.started';
    case SigningCompleted = 'signing.completed';
    case LinkRevoked = 'link.revoked';
}
