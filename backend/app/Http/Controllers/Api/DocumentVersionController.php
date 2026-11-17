<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreDocumentVersionRequest;
use App\Http\Resources\DocumentVersionCollection;
use App\Http\Resources\DocumentVersionResource;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Matter;
use App\Services\Documents\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** Version history (newest first) and new-version uploads. Versions are never edited or deleted. */
class DocumentVersionController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function index(Matter $matter, Document $document): DocumentVersionCollection
    {
        Gate::authorize('view', $document);

        $versions = $document->versions()->with('document:id,current_version_id')
            ->orderByDesc('version_number')
            ->paginate(100);

        return new DocumentVersionCollection($versions);
    }

    public function store(StoreDocumentVersionRequest $request, Matter $matter, Document $document): JsonResponse
    {
        $version = $this->documents->addVersion($matter, $document, $request->file('file'), $request->user(), $request->validated('lock_version'));

        return (new DocumentVersionResource($version->load('document:id,current_version_id')))->response()->setStatusCode(201);
    }

    public function show(Matter $matter, Document $document, DocumentVersion $version): DocumentVersionResource
    {
        Gate::authorize('view', $document);

        return new DocumentVersionResource($version->load('document:id,current_version_id'));
    }
}
