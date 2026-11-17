<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentCategory;
use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\DocumentLifecycleRequest;
use App\Http\Requests\Documents\IndexDocumentsRequest;
use App\Http\Requests\Documents\StoreDocumentRequest;
use App\Http\Requests\Documents\UpdateDocumentRequest;
use App\Http\Resources\DocumentCollection;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\Matter;
use App\Services\Documents\DocumentService;
use App\Support\LegalDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Document library: workspace-wide list (GET /api/documents) and the matter-scoped resource
 * (/api/matters/{matter}/documents/{document}). Search runs on metadata in PostgreSQL — the
 * contents of PDFs are not indexed.
 */
class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function library(IndexDocumentsRequest $request): DocumentCollection
    {
        return $this->list($request, Document::query());
    }

    public function index(IndexDocumentsRequest $request, Matter $matter): DocumentCollection
    {
        return $this->list($request, $matter->documents()->getQuery());
    }

    public function store(StoreDocumentRequest $request, Matter $matter): JsonResponse
    {
        $document = $this->documents->create($matter, $request->file('file'), $request->safe()->except('file'), $request->user());

        return (new DocumentResource(self::loadDetail($document)))->response()->setStatusCode(201);
    }

    public function show(Matter $matter, Document $document): DocumentResource
    {
        Gate::authorize('view', $document);

        return new DocumentResource(self::loadDetail($document));
    }

    public function update(UpdateDocumentRequest $request, Matter $matter, Document $document): DocumentResource
    {
        $document = $this->documents->update($matter, $document, $request->validated(), $request->user());

        return new DocumentResource(self::loadDetail($document));
    }

    public function archive(DocumentLifecycleRequest $request, Matter $matter, Document $document): DocumentResource
    {
        $document = $this->documents->archive($matter, $document, $request->user(), $request->validated('lock_version'));

        return new DocumentResource(self::loadDetail($document));
    }

    public function restore(DocumentLifecycleRequest $request, Matter $matter, Document $document): DocumentResource
    {
        $document = $this->documents->restore($matter, $document, $request->user(), $request->validated('lock_version'));

        return new DocumentResource(self::loadDetail($document));
    }

    public static function loadDetail(Document $document): Document
    {
        return Document::query()
            ->with(['currentVersion', 'uploader:id,name', 'matter:id,matter_number,title,status,client_id', 'matter.client:id,name', 'activeSigningRequest'])
            ->findOrFail($document->getKey());
    }

    /** @param Builder<Document> $query */
    private function list(IndexDocumentsRequest $request, Builder $query): DocumentCollection
    {
        $filters = $request->validated();
        $sort = $filters['sort'] ?? 'updated_at';
        $direction = $filters['direction'] ?? (in_array($sort, ['title', 'matter_number', 'category'], true) ? 'asc' : 'desc');
        $status = $filters['status'] ?? DocumentStatus::Active->value;

        $query->with(['currentVersion', 'matter:id,matter_number,title,status,client_id', 'matter.client:id,name'])
            ->when($status !== 'all', fn (Builder $q) => $q->where('documents.status', $status))
            ->when($filters['category'] ?? null, fn (Builder $q, string $category) => $q->where('documents.category', $category))
            ->when($filters['matter_id'] ?? null, fn (Builder $q, int|string $id) => $q->where('documents.matter_id', (int) $id))
            ->when($filters['client_id'] ?? null, fn (Builder $q, int|string $id) => $q->whereHas('matter', fn (Builder $m) => $m->where('client_id', (int) $id)))
            ->when($filters['uploaded_from'] ?? null, fn (Builder $q, string $date) => $q->where('documents.created_at', '>=', LegalDate::startOfDay($date)))
            ->when($filters['uploaded_to'] ?? null, fn (Builder $q, string $date) => $q->where('documents.created_at', '<=', LegalDate::endOfDay($date)))
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $category = collect(DocumentCategory::cases())
                    ->filter(fn ($c) => str_contains(mb_strtolower($c->label()), mb_strtolower($search)))
                    ->map->value->values()->all();
                $q->where(function (Builder $q) use ($term, $category) {
                    $q->whereLike('documents.title', $term)
                        ->orWhereExists(fn ($v) => $v->selectRaw('1')->from('document_versions')
                            ->whereColumn('document_versions.document_id', 'documents.id')
                            ->whereLike('document_versions.original_filename', $term))
                        ->orWhereHas('matter', fn (Builder $m) => $m->whereLike('matter_number', $term)
                            ->orWhereLike('title', $term)
                            ->orWhereHas('client', fn (Builder $c) => $c->whereLike('name', $term)));
                    if ($category !== []) {
                        $q->orWhereIn('documents.category', $category);
                    }
                });
            });

        if ($sort === 'matter_number') {
            $query->select('documents.*')
                ->join('matters', 'matters.id', '=', 'documents.matter_id')
                ->orderBy('matters.matter_number', $direction);
        } else {
            $query->orderBy('documents.'.$sort, $direction);
        }
        $query->orderByDesc('documents.id');

        $perPage = (int) ($filters['per_page'] ?? config('lexora.pagination.default_per_page'));

        return new DocumentCollection($query->paginate($perPage)->withQueryString());
    }
}
