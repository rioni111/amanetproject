<?php

namespace App\Models;

use Illuminate\Notifications\DatabaseNotification;

/**
 * Laravel's database notification with microsecond timestamps, so notifications created within the same
 * second still list newest first (the notifications table uses timestamptz(6)).
 */
class WorkspaceNotificationRecord extends DatabaseNotification
{
    protected $dateFormat = 'Y-m-d H:i:s.uP';
}
