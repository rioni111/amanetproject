<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Documents inherit access from their matter: whoever may view the matter may view (preview,
 * download) its documents; whoever may update it may upload and edit. Business rules (archived
 * matter/document is read-only) are enforced by DocumentService with 409.
 */
class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Document $document): bool
    {
        return $user->isAdmin() && Gate::forUser($user)->allows('view', $document->matter);
    }

    public function create(User $user, Matter $matter): bool
    {
        return $user->isAdmin() && Gate::forUser($user)->allows('update', $matter);
    }

    public function update(User $user, Document $document): bool
    {
        return $user->isAdmin() && Gate::forUser($user)->allows('update', $document->matter);
    }
}
