<?php

namespace App\Enums;

/**
 * Who performed an action recorded in the activity history.
 * "client" actors arrive in Phase 4/5 through secure, token-based links.
 */
enum ActorType: string
{
    use HasValues;

    case Admin = 'admin';
    case Client = 'client';
    case System = 'system';
}
