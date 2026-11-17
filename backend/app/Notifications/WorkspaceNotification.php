<?php

namespace App\Notifications;

use App\Enums\NotificationKind;
use Illuminate\Notifications\Notification;

/**
 * An administrator in-app notification (database channel only; it is never e-mailed and never queued, so it
 * is written inside the same transaction as the event it reports).
 *
 * Data: kind, title, body, href (an internal workspace path — never a client link), matter number/title and
 * the related ids. It never contains a secure link, token, credential, internal note or document content.
 */
class WorkspaceNotification extends Notification
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly NotificationKind $kind,
        public readonly string $body,
        public readonly string $href,
        public readonly array $context = [],
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->kind->value;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind->value,
            'title' => $this->kind->title(),
            'body' => $this->body,
            'href' => $this->href,
            ...$this->context,
        ];
    }
}
