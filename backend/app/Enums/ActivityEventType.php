<?php

namespace App\Enums;

/**
 * Catalogue of activity event types. Only the matter/task/note events are
 * emitted in Phase 1 (by the demo seeder); the remaining cases document the
 * events later phases will append.
 */
enum ActivityEventType: string
{
    use HasValues;

    // Phase 1–2: matter management
    case MatterCreated = 'matter.created';
    case MatterUpdated = 'matter.updated';
    case MatterStatusChanged = 'matter.status_changed';
    case TaskCreated = 'task.created';
    case TaskCompleted = 'task.completed';
    case NoteAdded = 'note.added';

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
