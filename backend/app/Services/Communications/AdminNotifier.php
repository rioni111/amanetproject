<?php

namespace App\Services\Communications;

use App\Enums\NotificationKind;
use App\Enums\UserRole;
use App\Models\Matter;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Creates administrator notifications. Every administrator gets their own record (own read state). Callers
 * invoke this inside the transaction of the event, so a rolled-back event never leaves a notification.
 */
class AdminNotifier
{
    /** @param array<string, mixed> $context ids only (request / signing request / delivery) */
    public function notify(NotificationKind $kind, ?Matter $matter, string $body, string $href, array $context = []): void
    {
        $admins = User::query()->where('role', UserRole::Admin->value)->get();
        if ($admins->isEmpty()) {
            return;
        }
        Notification::send($admins, new WorkspaceNotification($kind, $body, $href, [
            'matter_id' => $matter?->id,
            'matter_number' => $matter?->matter_number,
            'matter_title' => $matter?->title,
            ...$context,
        ]));
    }
}
