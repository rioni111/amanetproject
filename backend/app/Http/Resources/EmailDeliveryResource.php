<?php

namespace App\Http\Resources;

use App\Models\EmailDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Administrator view of one e-mail. Never includes the action link, token or message body — they are not
 * stored. "accepted" means accepted by the SMTP server, not "delivered to the inbox".
 *
 * @mixin EmailDelivery
 */
class EmailDeliveryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'matter_id' => $this->matter_id,
            'document_request_id' => $this->document_request_id,
            'signing_request_id' => $this->signing_request_id,
            'communication_type' => $this->communication_type->value,
            'communication_label' => $this->communication_type->label(),
            'initiated_by' => $this->initiated_by->value,
            'reminder_slot' => $this->reminder_slot,
            'recipient_email' => $this->recipient_email,
            'recipient_name' => $this->recipient_name,
            'subject' => $this->subject,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'attempt_count' => $this->attempt_count,
            'last_attempt_at' => $this->last_attempt_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'failure_category' => $this->failure_category?->value,
            'failure_message' => $this->failure_category?->label(),
            'provider_message_id' => $this->provider_message_id,
            'link' => $this->whenLoaded('link', fn () => $this->link ? ['id' => $this->link->id, 'state' => $this->link->state()->value, 'expires_at' => $this->link->expires_at->toIso8601String()] : null),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? ['id' => $this->creator->id, 'name' => $this->creator->name] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
