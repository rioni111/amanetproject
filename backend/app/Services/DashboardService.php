<?php

namespace App\Services;

use App\Enums\MatterStatus;
use App\Enums\TaskStatus;
use App\Models\Client;
use App\Models\Matter;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Aggregates the administrator dashboard from the database.
 *
 * Definitions (documented in docs/architecture.md):
 *  - Active matter:      status ∈ {open, in_progress, on_hold}
 *  - Upcoming deadline:  active matter with due_at in [now, end of day(now + window)]
 *  - Overdue matter:     active matter with due_at < now
 *  - Open task:          status ∈ {todo, in_progress}
 */
class DashboardService
{
    /**
     * @return array{
     *   stats: array<string, int>,
     *   status_breakdown: list<array{status: string, label: string, count: int}>,
     *   recent_matters: Collection<int, Matter>,
     *   upcoming_deadlines: Collection<int, Matter>,
     *   upcoming_window_days: int,
     *   generated_at: Carbon
     * }
     */
    public function summary(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $window = max(1, (int) config('lexora.dashboard.upcoming_window_days', 14));

        $statusCounts = Matter::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $activeMatters = collect(MatterStatus::activeValues())
            ->sum(fn (string $status) => (int) ($statusCounts[$status] ?? 0));

        $taskCounts = Task::query()
            ->selectRaw('count(*) filter (where status in (?, ?)) as open_tasks', TaskStatus::openValues())
            ->selectRaw('count(*) filter (where status in (?, ?) and due_at < ?) as overdue_tasks', [...TaskStatus::openValues(), $now])
            ->first();

        return [
            'stats' => [
                'total_clients' => Client::query()->count(),
                'active_matters' => $activeMatters,
                'total_matters' => (int) $statusCounts->sum(),
                'upcoming_deadlines' => Matter::query()->dueWithin($window, $now)->count(),
                'overdue_matters' => Matter::query()->overdue($now)->count(),
                'open_tasks' => (int) $taskCounts->open_tasks,
                'overdue_tasks' => (int) $taskCounts->overdue_tasks,
            ],
            'status_breakdown' => array_map(fn (MatterStatus $status) => [
                'status' => $status->value,
                'label' => $status->label(),
                'count' => (int) ($statusCounts[$status->value] ?? 0),
            ], MatterStatus::cases()),
            'recent_matters' => Matter::query()
                ->with('client:id,name,client_type')
                ->latest('updated_at')
                ->orderByDesc('id')
                ->limit((int) config('lexora.dashboard.recent_matters_limit', 6))
                ->get(),
            'upcoming_deadlines' => Matter::query()
                ->with('client:id,name,client_type')
                ->dueWithin($window, $now)
                ->orderBy('due_at')
                ->limit((int) config('lexora.dashboard.upcoming_deadlines_limit', 6))
                ->get(),
            'upcoming_window_days' => $window,
            'generated_at' => $now,
        ];
    }
}
