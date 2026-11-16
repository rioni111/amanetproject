<?php

namespace App\Http\Resources;

use App\Models\MatterNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Internal note: administrator-only endpoints. The body is plain text; the frontend renders it as
 * text (never as HTML). Never embed this resource in client-facing payloads.
 *
 * @mixin MatterNote
 */
class MatterNoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'matter_id' => $this->matter_id,
            'body' => $this->body,
            'author' => $this->whenLoaded('author', fn () => $this->author ? ['id' => $this->author->id, 'name' => $this->author->name] : null),
            'is_edited' => $this->lock_version > 1,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'lock_version' => $this->lock_version,
        ];
    }
}
