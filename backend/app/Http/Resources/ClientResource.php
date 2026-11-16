<?php

namespace App\Http\Resources;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Client */
class ClientResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'client_type' => $this->client_type->value,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'reference_number' => $this->reference_number,
            'matters_count' => $this->whenCounted('matters'),
            'active_matters_count' => $this->whenHas('active_matters_count', fn () => (int) $this->active_matters_count),
            'closed_matters_count' => $this->whenHas('closed_matters_count', fn () => (int) $this->closed_matters_count),
            'archived_matters_count' => $this->whenHas('archived_matters_count', fn () => (int) $this->archived_matters_count),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
