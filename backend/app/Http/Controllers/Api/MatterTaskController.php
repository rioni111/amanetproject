<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\IndexTasksRequest;
use App\Http\Requests\Tasks\StoreTaskRequest;
use App\Http\Requests\Tasks\UpdateTaskRequest;
use App\Http\Resources\TaskCollection;
use App\Http\Resources\TaskResource;
use App\Models\Matter;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Tasks nested under their matter. Routes use scoped bindings, so a task id that belongs to another
 * matter resolves to 404 — tasks can never be read or changed through an unrelated matter.
 */
class MatterTaskController extends Controller
{
    public function __construct(private readonly TaskService $tasks) {}

    public function index(IndexTasksRequest $request, Matter $matter): TaskCollection
    {
        $filters = $request->validated();

        $query = $matter->tasks()
            ->with('creator:id,name')
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $status === 'open'
                ? $q->whereIn('status', TaskStatus::openValues())
                : $q->where('status', $status))
            // Open work first (soonest deadline first), completed tasks last (most recent first).
            ->orderByRaw("CASE WHEN status = 'completed' THEN 1 ELSE 0 END")
            ->orderByRaw('due_at ASC NULLS LAST')
            ->orderByDesc('completed_at')
            ->orderBy('id');

        return new TaskCollection($query->paginate((int) ($filters['per_page'] ?? 50))->withQueryString());
    }

    public function store(StoreTaskRequest $request, Matter $matter): JsonResponse
    {
        $task = $this->tasks->create($matter, $request->validated(), $request->user());

        return (new TaskResource($task->load('creator:id,name')))->response()->setStatusCode(201);
    }

    public function update(UpdateTaskRequest $request, Matter $matter, Task $task): TaskResource
    {
        $task = $this->tasks->update($matter, $task, $request->validated(), $request->user());

        return new TaskResource($task->load('creator:id,name'));
    }

    public function destroy(Matter $matter, Task $task): Response
    {
        Gate::authorize('update', $matter);
        $this->tasks->delete($matter, $task, request()->user());

        return response()->noContent();
    }
}
