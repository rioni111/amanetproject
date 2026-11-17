<?php

namespace App\Http\Resources;

use App\Enums\SigningStatus;
use App\Models\SigningRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A signing request for administrators. Never contains tokens, token hashes, URLs or storage paths.
 * `allowed_actions` drives the UI; the server re-validates every action.
 *
 * @mixin SigningRequest
 */
class SigningRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $usable = $this->relationLoaded('usableLink') ? $this->usableLink : null;
        $archived = $this->relationLoaded('matter') && $this->matter->isArchived();
        $active = $this->status->isActive() && ! $archived;
        $client = $this->isClientSigner();

        return [
            'id' => $this->id,
            'matter_id' => $this->matter_id,
            'document_id' => $this->document_id,
            'signer_type' => $this->signer_type->value,
            'signer_type_label' => $this->signer_type->label(),
            'signer_name' => $this->signer_name,
            'signer_email' => $this->signer_email,
            'message' => $this->message,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_active' => $this->status->isActive(),
            'delivery_channel' => $this->delivery_channel?->value,
            'delivery_status' => $this->delivery_status->value,
            'delivery_status_label' => $this->delivery_status->label(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'viewed_at' => $this->viewed_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'signed_at' => $this->signed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason?->value,
            'cancellation_label' => $this->cancellation_reason?->label(),
            'matter' => $this->whenLoaded('matter', fn () => [
                'id' => $this->matter->id, 'matter_number' => $this->matter->matter_number,
                'title' => $this->matter->title, 'status' => $this->matter->status->value,
            ]),
            'client' => $this->whenLoaded('client', fn () => $this->client ? ['id' => $this->client->id, 'name' => $this->client->name] : null),
            'document' => $this->whenLoaded('document', fn () => [
                'id' => $this->document->id, 'title' => $this->document->title, 'status' => $this->document->status->value,
                'current_version_id' => $this->document->current_version_id,
            ]),
            'source_version' => new DocumentVersionResource($this->whenLoaded('sourceVersion')),
            'signed_version' => $this->when($this->relationLoaded('signedVersion'), fn () => $this->signedVersion ? new DocumentVersionResource($this->signedVersion) : null),
            'active_link' => $this->when($this->relationLoaded('usableLink'), fn () => $usable ? new SecureAccessLinkResource($usable) : null),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? ['id' => $this->creator->id, 'name' => $this->creator->name] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'lock_version' => $this->lock_version,
            'allowed_actions' => [
                'issue_link' => $active && $client && ($usable === null || ! $usable->isUsable()),
                'rotate_link' => $active && $client,
                'revoke_link' => $client && $usable !== null,
                'mark_shared' => $active && $client && $this->status === SigningStatus::Ready && $usable !== null && $usable->isUsable(),
                'cancel' => $active,
                'admin_sign' => $active && ! $client && $this->signer_user_id === $request->user()?->id,
            ],
        ];
    }
}
