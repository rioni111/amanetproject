<?php

namespace App\Services;

use App\Enums\DocumentRequestStatus;
use App\Enums\EmailDeliveryStatus;
use App\Enums\MatterStatus;
use App\Enums\SigningStatus;
use App\Enums\TaskStatus;
use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\EmailDelivery;
use App\Models\Matter;
use App\Models\SigningRequest;
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

        // Client document requests on non-archived matters, one row per request (no double counting):
        // each request is in exactly one status; "overdue" is a subset of those waiting for the client.
        $requestCounts = DocumentRequest::query()
            ->join('matters', 'matters.id', '=', 'document_requests.matter_id')
            ->where('matters.status', '<>', MatterStatus::Archived->value)
            ->selectRaw('count(*) filter (where document_requests.status in (?, ?, ?, ?)) as outstanding', DocumentRequestStatus::openValues())
            ->selectRaw('count(*) filter (where document_requests.status = ?) as pending', [DocumentRequestStatus::Pending->value])
            ->selectRaw('count(*) filter (where document_requests.status in (?, ?)) as awaiting_review', DocumentRequestStatus::awaitingReviewValues())
            ->selectRaw('count(*) filter (where document_requests.status = ?) as corrections', [DocumentRequestStatus::CorrectionRequested->value])
            ->selectRaw('count(*) filter (where document_requests.status in (?, ?) and document_requests.due_at < ?) as overdue', [...DocumentRequestStatus::awaitingClientValues(), $now])
            ->selectRaw('count(*) filter (where document_requests.status = ?) as approved', [DocumentRequestStatus::Approved->value])
            ->toBase()
            ->first();

        // Signing requests (Phase 5) on non-archived matters: open (awaiting a signature) and completed.
        $signingCounts = SigningRequest::query()
            ->join('matters', 'matters.id', '=', 'signing_requests.matter_id')
            ->where('matters.status', '<>', MatterStatus::Archived->value)
            ->selectRaw('count(*) filter (where signing_requests.status in (?, ?, ?, ?)) as active', SigningStatus::activeValues())
            ->selectRaw('count(*) filter (where signing_requests.status = ?) as signed', [SigningStatus::Signed->value])
            ->toBase()
            ->first();

        // E-mail communication (Phase 6), one aggregate query: in progress now, and outcomes in the last 30 days.
        $emailCounts = EmailDelivery::query()
            ->selectRaw('count(*) filter (where status = ?) as sending', [EmailDeliveryStatus::Sending->value])
            ->selectRaw('count(*) filter (where status = ? and accepted_at >= ?) as accepted', [EmailDeliveryStatus::Accepted->value, $now->copy()->subDays(30)])
            ->selectRaw('count(*) filter (where status = ? and failed_at >= ?) as failed', [EmailDeliveryStatus::Failed->value, $now->copy()->subDays(30)])
            ->toBase()
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
                'requests_outstanding' => (int) $requestCounts->outstanding,
                'requests_pending' => (int) $requestCounts->pending,
                'requests_awaiting_review' => (int) $requestCounts->awaiting_review,
                'requests_correction' => (int) $requestCounts->corrections,
                'requests_overdue' => (int) $requestCounts->overdue,
                'requests_approved' => (int) $requestCounts->approved,
                'signatures_pending' => (int) $signingCounts->active,
                'signatures_signed' => (int) $signingCounts->signed,
                'emails_sending' => (int) $emailCounts->sending,
                'emails_accepted_30d' => (int) $emailCounts->accepted,
                'emails_failed_30d' => (int) $emailCounts->failed,
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
