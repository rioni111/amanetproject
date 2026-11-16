<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notes\IndexNotesRequest;
use App\Http\Requests\Notes\NoteRequest;
use App\Http\Resources\MatterNoteCollection;
use App\Http\Resources\MatterNoteResource;
use App\Models\Matter;
use App\Models\MatterNote;
use App\Services\MatterNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** Confidential internal notes, administrator-only, nested and scoped to their matter. */
class MatterNoteController extends Controller
{
    public function __construct(private readonly MatterNoteService $notes) {}

    public function index(IndexNotesRequest $request, Matter $matter): MatterNoteCollection
    {
        $query = $matter->notes()->with('author:id,name')->latest('created_at')->orderByDesc('id');

        return new MatterNoteCollection($query->paginate((int) ($request->validated('per_page') ?? 20))->withQueryString());
    }

    public function store(NoteRequest $request, Matter $matter): JsonResponse
    {
        $note = $this->notes->create($matter, $request->validated('body'), $request->user());

        return (new MatterNoteResource($note->load('author:id,name')))->response()->setStatusCode(201);
    }

    public function update(NoteRequest $request, Matter $matter, MatterNote $note): MatterNoteResource
    {
        $note = $this->notes->update($matter, $note, $request->validated('body'), $request->user(), $request->validated('lock_version'));

        return new MatterNoteResource($note->load('author:id,name'));
    }

    public function destroy(Matter $matter, MatterNote $note): Response
    {
        Gate::authorize('update', $matter);
        $this->notes->delete($matter, $note, request()->user());

        return response()->noContent();
    }
}
