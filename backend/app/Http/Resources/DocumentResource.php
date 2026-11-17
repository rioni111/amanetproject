<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Document */
class DocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'matter_id' => $this->matter_id,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'status' => $this->status->value,
            'origin' => $this->origin->value,
            'versions_count' => $this->latest_version_number,
            'current_version' => new DocumentVersionResource($this->whenLoaded('currentVersion')),
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader ? ['id' => $this->uploader->id, 'name' => $this->uploader->name] : null),
            'matter' => $this->whenLoaded('matter', fn () => [
                'id' => $this->matter->id,
                'matter_number' => $this->matter->matter_number,
                'title' => $this->matter->title,
                'status' => $this->matter->status->value,
                'client' => $this->matter->relationLoaded('client') && $this->matter->client
                    ? ['id' => $this->matter->client->id, 'name' => $this->matter->client->name]
                    : null,
            ]),
            'active_signing_request' => $this->whenLoaded('activeSigningRequest', fn () => $this->activeSigningRequest ? [
                'id' => $this->activeSigningRequest->id, 'status' => $this->activeSigningRequest->status->value,
                'signer_type' => $this->activeSigningRequest->signer_type->value, 'signer_name' => $this->activeSigningRequest->signer_name,
            ] : null),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'lock_version' => $this->lock_version,
        ];
    }
}
