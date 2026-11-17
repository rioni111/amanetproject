<?php

namespace App\Policies;

use App\Models\DocumentRequest;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Document requests inherit access from their matter (administrators only). Lifecycle rules
 * (closed requests, archived matters, invalid transitions) are enforced by the services with 409/422.
 * External clients never pass through policies: they are authorised by their link (see
 * PublicUploadLinkResolver) and can reach nothing but the public upload endpoints.
 */
class DocumentRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, DocumentRequest $request): bool
    {
        return $user->isAdmin() && Gate::forUser($user)->allows('view', $request->matter);
    }

    public function create(User $user, Matter $matter): bool
    {
        return $user->isAdmin() && Gate::forUser($user)->allows('update', $matter);
    }

    public function update(User $user, DocumentRequest $request): bool
    {
        return $user->isAdmin() && Gate::forUser($user)->allows('update', $request->matter);
    }
}
