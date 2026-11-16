<?php

namespace App\Http\Resources;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact client representation embedded in matter payloads.
 *
 * @mixin Client
 */
class ClientSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'client_type' => $this->client_type->value,
            'reference_number' => $this->whenHas('reference_number'),
        ];
    }
}
