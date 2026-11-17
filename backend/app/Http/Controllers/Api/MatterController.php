<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentRequestStatus;
use App\Enums\MatterStatus;
use App\Enums\SigningStatus;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Matters\IndexMattersRequest;
use App\Http\Requests\Matters\StoreMatterRequest;
use App\Http\Requests\Matters\UpdateMatterRequest;
use App\Http\Resources\MatterCollection;
use App\Http\Resources\MatterResource;
use App\Models\Matter;
use App\Services\MatterService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Matters: listing (search, filters, sorting, pagination), creation, detail and editing.
 * Status changes, archive and restore live in MatterLifecycleController.
 */
class MatterController extends Controller
{
    public function __construct(private readonly MatterService $matters) {}

    public function index(IndexMattersRequest $request): MatterCollection
    {
        $filters = $request->validated();
        $sort = $filters['sort'] ?? 'updated_at';
        $direction = $filters['direction'] ?? ($sort === 'due_at' || $sort === 'matter_number' || $sort === 'title' ? 'asc' : 'desc');
        $now = Carbon::now();

        $query = Matter::query()
            ->with('client:id,name,client_type')
            ->withCount(['tasks as open_tasks_count' => fn (Builder $q) => $q->whereIn('status', TaskStatus::openValues())])
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $term = '%'.addcslashes($search, '%_\\').'%'; // escape LIKE wildcards
                $q->where(function (Builder $q) use ($term) {
                    $q->whereLike('title', $term)
                        ->orWhereLike('matter_number', $term)
                        ->orWhereLike('matter_type', $term)
                        ->orWhereHas('client', fn (Builder $c) => $c->whereLike('name', $term)->orWhereLike('reference_number', $term));
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $status === 'active'
                ? $q->whereIn('status', MatterStatus::activeValues())
                : $q->where('status', $status))
            ->when($filters['priority'] ?? null, fn (Builder $q, string $priority) => $q->where('priority', $priority))
            ->when($filters['client_id'] ?? null, fn (Builder $q, int|string $clientId) => $q->where('client_id', (int) $clientId))
            ->when($filters['matter_type'] ?? null, fn (Builder $q, string $type) => $q->where('matter_type', $type))
            ->when($filters['deadline'] ?? null, fn (Builder $q, string $deadline) => match ($deadline) {
                'overdue' => $q->overdue($now),
                'upcoming' => $q->dueWithin(max(1, (int) config('lexora.dashboard.upcoming_window_days', 14)), $now),
                'none' => $q->whereNull('due_at'),
            });

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

    public function store(StoreMatterRequest $request): JsonResponse
    {
        $matter = $this->matters->create($request->validated(), $request->user());

        return (new MatterResource($this->loadDetail($matter)))->response()->setStatusCode(201);
    }

    public function show(Matter $matter): MatterResource
    {
        Gate::authorize('view', $matter);

        return new MatterResource($this->loadDetail($matter));
    }

    public function update(UpdateMatterRequest $request, Matter $matter): MatterResource
    {
        $matter = $this->matters->update($matter, $request->validated(), $request->user());

        return new MatterResource($this->loadDetail($matter));
    }

    /** Detail payload: client (with reference) plus task and note counters. */
    public static function loadDetail(Matter $matter): Matter
    {
        $now = Carbon::now();

        return Matter::query()
            ->with('client:id,name,client_type,reference_number')
            ->withCount([
                'tasks',
                'notes',
                'documents as documents_count' => fn (Builder $q) => $q->where('status', 'active'),
                'tasks as open_tasks_count' => fn (Builder $q) => $q->whereIn('status', TaskStatus::openValues()),
                'tasks as completed_tasks_count' => fn (Builder $q) => $q->where('status', TaskStatus::Completed->value),
                'tasks as overdue_tasks_count' => fn (Builder $q) => $q->whereIn('status', TaskStatus::openValues())->where('due_at', '<', $now),
                // Phase 4: outstanding client document requirements of this matter.
                'documentRequests as requests_open_count' => fn (Builder $q) => $q->whereIn('status', DocumentRequestStatus::openValues()),
                'documentRequests as requests_pending_count' => fn (Builder $q) => $q->where('status', DocumentRequestStatus::Pending->value),
                'documentRequests as requests_awaiting_review_count' => fn (Builder $q) => $q->whereIn('status', DocumentRequestStatus::awaitingReviewValues()),
                'documentRequests as requests_correction_count' => fn (Builder $q) => $q->where('status', DocumentRequestStatus::CorrectionRequested->value),
                'documentRequests as requests_approved_count' => fn (Builder $q) => $q->where('status', DocumentRequestStatus::Approved->value),
                'documentRequests as requests_overdue_count' => fn (Builder $q) => $q->overdue($now),
                // Phase 5: signing requests of this matter.
                'signingRequests as signatures_active_count' => fn (Builder $q) => $q->whereIn('status', SigningStatus::activeValues()),
                'signingRequests as signatures_sent_count' => fn (Builder $q) => $q->where('status', SigningStatus::Sent->value),
                'signingRequests as signatures_viewed_count' => fn (Builder $q) => $q->where('status', SigningStatus::Viewed->value),
                'signingRequests as signatures_started_count' => fn (Builder $q) => $q->where('status', SigningStatus::Started->value),
                'signingRequests as signatures_signed_count' => fn (Builder $q) => $q->where('status', SigningStatus::Signed->value),
                'signingRequests as signatures_total_count',
            ])
            ->findOrFail($matter->getKey());
    }
}
