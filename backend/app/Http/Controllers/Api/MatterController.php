<?php

namespace App\Http\Controllers\Api;

use App\Enums\MatterStatus;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Matters\IndexMattersRequest;
use App\Http\Resources\MatterCollection;
use App\Models\Matter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 1: read-only listing. Create/update/archive arrive in Phase 2.
 */
class MatterController extends Controller
{
    public function index(IndexMattersRequest $request): MatterCollection
    {
        $filters = $request->validated();
        $sort = $filters['sort'] ?? 'updated_at';
        $direction = $filters['direction'] ?? ($sort === 'due_at' || $sort === 'matter_number' || $sort === 'title' ? 'asc' : 'desc');

        $query = Matter::query()
            ->with('client:id,name,client_type')
            ->withCount(['tasks as open_tasks_count' => fn (Builder $q) => $q->whereIn('status', TaskStatus::openValues())])
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $term = '%'.addcslashes($search, '%_\\').'%'; // escape LIKE wildcards
                $q->where(function (Builder $q) use ($term) {
                    $q->whereLike('title', $term)
                        ->orWhereLike('matter_number', $term)
                        ->orWhereLike('matter_type', $term)
                        ->orWhereHas('client', fn (Builder $c) => $c->whereLike('name', $term));
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $status === 'active'
                ? $q->whereIn('status', MatterStatus::activeValues())
                : $q->where('status', $status))
            ->when($filters['priority'] ?? null, fn (Builder $q, string $priority) => $q->where('priority', $priority))
            ->when($filters['client_id'] ?? null, fn (Builder $q, int|string $clientId) => $q->where('client_id', (int) $clientId));

        match ($sort) {
            'priority' => $query->orderByRaw(
                "CASE priority WHEN 'urgent' THEN 4 WHEN 'high' THEN 3 WHEN 'medium' THEN 2 ELSE 1 END ".($direction === 'asc' ? 'ASC' : 'DESC')
            ),
            'due_at' => $query->orderByRaw('due_at '.($direction === 'asc' ? 'ASC' : 'DESC').' NULLS LAST'),
            default => $query->orderBy($sort, $direction),
        };
        $query->orderByDesc('id');

        $perPage = (int) ($filters['per_page'] ?? config('lexora.pagination.default_per_page'));

        return new MatterCollection($query->paginate($perPage)->withQueryString());
    }
}
