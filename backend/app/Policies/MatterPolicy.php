<?php

namespace App\Policies;

use App\Models\Matter;
use App\Models\User;

/**
 * Only firm administrators may read or change matters (and, through "update", their tasks and
 * internal notes). Business rules such as "archived matters are read-only" live in the services
 * and answer 409; authorisation answers 403.
 */
class MatterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Matter $matter): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Matter $matter): bool
    {
        return $user->isAdmin();
    }

    public function archive(User $user, Matter $matter): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, Matter $matter): bool
    {
        return $user->isAdmin();
    }
}
