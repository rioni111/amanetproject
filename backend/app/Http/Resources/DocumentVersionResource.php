<?php

namespace App\Http\Resources;

use App\Models\DocumentVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Version metadata. The storage disk and path are internal and never part of any response.
 *
 * @mixin DocumentVersion
 */
class DocumentVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_id' => $this->document_id,
            'version_number' => $this->version_number,
            'label' => $this->label(),
            'kind' => $this->kind->value,
            'origin' => $this->origin->value,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'sha256_checksum' => $this->sha256_checksum,
            'uploaded_by' => $this->uploaded_by_name !== null ? ['id' => $this->uploaded_by, 'name' => $this->uploaded_by_name] : null,
            'is_current' => $this->when($this->relationLoaded('document'), fn () => $this->document->current_version_id === $this->id),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
