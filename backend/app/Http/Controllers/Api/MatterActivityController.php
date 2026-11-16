<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Matters\IndexActivityRequest;
use App\Http\Resources\ActivityEventCollection;
use App\Models\Matter;

/** Read-only, newest-first, paginated matter history. */
class MatterActivityController extends Controller
{
    public function __invoke(IndexActivityRequest $request, Matter $matter): ActivityEventCollection
    {
        $events = $matter->activityEvents()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate((int) ($request->validated('per_page') ?? 20))
            ->withQueryString();

        return new ActivityEventCollection($events);
    }
}
