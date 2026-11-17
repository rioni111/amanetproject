<?php

namespace App\Http\Resources;

use App\Models\SecureAccessLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Link status for administrators. Neither the token nor its hash is ever included: a link's URL is
 * only returned once, by the action that created it (see IssuedLink).
 *
 * @mixin SecureAccessLink
 */
class SecureAccessLinkResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purpose' => $this->purpose->value,
            'state' => $this->state()->value,
            'expires_at' => $this->expires_at->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revocation_reason' => $this->revocation_reason?->value,
            'revocation_label' => $this->revocation_reason?->label(),
            'consumed_at' => $this->consumed_at?->toIso8601String(),
            'first_opened_at' => $this->first_opened_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? ['id' => $this->creator->id, 'name' => $this->creator->name] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
