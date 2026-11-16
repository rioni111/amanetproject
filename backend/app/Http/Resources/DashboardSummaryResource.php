<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps the array produced by DashboardService::summary().
 */
class DashboardSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'stats' => $this->resource['stats'],
            'status_breakdown' => $this->resource['status_breakdown'],
            'upcoming_window_days' => $this->resource['upcoming_window_days'],
            'recent_matters' => MatterResource::collection($this->resource['recent_matters']),
            'upcoming_deadlines' => MatterResource::collection($this->resource['upcoming_deadlines']),
            'generated_at' => $this->resource['generated_at']->toIso8601String(),
        ];
    }
}
