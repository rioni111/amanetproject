<?php

namespace App\Policies;

use App\Models\Matter;
use App\Models\User;

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
}
