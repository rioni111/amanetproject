<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DashboardSummaryResource;
use App\Services\DashboardService;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function __invoke(DashboardService $dashboard): DashboardSummaryResource
    {
        Gate::authorize('view-dashboard');

        return new DashboardSummaryResource($dashboard->summary());
    }
}
