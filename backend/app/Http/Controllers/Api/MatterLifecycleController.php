<?php

namespace App\Http\Controllers\Api;

use App\Enums\MatterStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Matters\ChangeMatterStatusRequest;
use App\Http\Requests\Matters\MatterLifecycleRequest;
use App\Http\Resources\MatterResource;
use App\Models\Matter;
use App\Services\MatterService;

/**
 * Controlled lifecycle actions (see MatterStatus::allowedTransitions for the policy).
 * Invalid transitions → 422 with errors.status; stale lock_version → 409; archived matter → 409.
 */
class MatterLifecycleController extends Controller
{
    public function __construct(private readonly MatterService $matters) {}

    public function status(ChangeMatterStatusRequest $request, Matter $matter): MatterResource
    {
        $matter = $this->matters->transition(
            $matter,
            MatterStatus::from($request->validated('status')),
            $request->user(),
            $request->validated('lock_version'),
        );

        return new MatterResource(MatterController::loadDetail($matter));
    }

    public function archive(MatterLifecycleRequest $request, Matter $matter): MatterResource
    {
        $matter = $this->matters->archive($matter, $request->user(), $request->validated('lock_version'));

        return new MatterResource(MatterController::loadDetail($matter));
    }

    public function restore(MatterLifecycleRequest $request, Matter $matter): MatterResource
    {
        $matter = $this->matters->restore($matter, $request->user(), $request->validated('lock_version'));

        return new MatterResource(MatterController::loadDetail($matter));
    }
}
