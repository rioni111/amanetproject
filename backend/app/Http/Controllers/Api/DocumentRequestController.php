<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentRequestStatus;
use App\Enums\MatterStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentRequests\DocumentRequestActionRequest;
use App\Http\Requests\DocumentRequests\IndexDocumentRequestsRequest;
use App\Http\Requests\DocumentRequests\StoreDocumentRequestRequest;
use App\Http\Requests\DocumentRequests\UpdateDocumentRequestRequest;
use App\Http\Resources\DocumentRequestCollection;
use App\Http\Resources\DocumentRequestResource;
use App\Http\Resources\SecureAccessLinkResource;
use App\Models\DocumentRequest;
use App\Models\Matter;
use App\Services\Requests\DocumentRequestService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Client document requests: workspace-wide list (GET /api/document-requests) and the matter-scoped
 * resource (/api/matters/{matter}/document-requests/{documentRequest}). Filtering is server-side.
 */
class DocumentRequestController extends Controller
{
    public function __construct(private readonly DocumentRequestService $requests) {}

    public function library(IndexDocumentRequestsRequest $request): DocumentRequestCollection
    {
        return $this->list($request, DocumentRequest::query());
    }

    public function index(IndexDocumentRequestsRequest $request, Matter $matter): DocumentRequestCollection
    {
        return $this->list($request, $matter->documentRequests()->getQuery());
    }

    public function store(StoreDocumentRequestRequest $request, Matter $matter): JsonResponse
    {
        $data = $request->validated();
        [$documentRequest, $issued] = $this->requests->create($matter, $data, $request->user(),
            (bool) ($data['issue_link'] ?? false), $data['link_expires_in_days'] ?? null);

        return response()->json([
            'data' => new DocumentRequestResource(self::loadDetail($documentRequest)),
            'link' => $issued?->toResponse(),
        ], 201)->header('Cache-Control', 'no-store, private');
    }

    public function show(Matter $matter, DocumentRequest $documentRequest): JsonResponse
    {
        Gate::authorize('view', $documentRequest);

        $detail = self::loadDetail($documentRequest, withHistory: true);

        return response()->json([
            'data' => new DocumentRequestResource($detail),
            'links' => SecureAccessLinkResource::collection($detail->getRelation('links')),
        ]);
    }

    public function update(UpdateDocumentRequestRequest $request, Matter $matter, DocumentRequest $documentRequest): DocumentRequestResource
    {
        $documentRequest = $this->requests->update($matter, $documentRequest, $request->validated(), $request->user());

        return new DocumentRequestResource(self::loadDetail($documentRequest));
    }

    public function cancel(DocumentRequestActionRequest $request, Matter $matter, DocumentRequest $documentRequest): DocumentRequestResource
    {
        $documentRequest = $this->requests->cancel($matter, $documentRequest, $request->user(), $request->validated('lock_version'));

        return new DocumentRequestResource(self::loadDetail($documentRequest));
    }

    public static function loadDetail(DocumentRequest $request, bool $withHistory = false): DocumentRequest
    {
        return DocumentRequest::query()
            ->with([
                'matter:id,matter_number,title,status,client_id',
                'client:id,name,email', // Phase 6: shown as the e-mail recipient (administrators only)
                'document:id,title,status,latest_version_number',
                'latestSubmission.version',
                'usableLink.creator:id,name',
                'approvedVersion',
                'creator:id,name',
                'reviewer:id,name',
                ...($withHistory ? ['links' => fn ($q) => $q->with('creator:id,name')->orderByDesc('id')->limit(50)] : []),
            ])
            ->findOrFail($request->getKey());
    }

    /** @param Builder<DocumentRequest> $query */
    private function list(IndexDocumentRequestsRequest $request, Builder $query): DocumentRequestCollection
    {
        $filters = $request->validated();
        $sort = $filters['sort'] ?? 'updated_at';
        $direction = $filters['direction'] ?? (in_array($sort, ['title', 'matter_number', 'due_at'], true) ? 'asc' : 'desc');

        // "Outstanding" views (open states, groups, overdue) leave out requests of archived (read-only)
        // matters, exactly like the dashboard counters, so a figure and the list behind it always agree.
        $status = $filters['status'] ?? null;
        $outstandingView = (bool) ($filters['overdue'] ?? false)
            || ($status !== null && ! in_array($status, [DocumentRequestStatus::Approved->value, DocumentRequestStatus::Cancelled->value], true));

        $query->select('document_requests.*')
            ->with(['matter:id,matter_number,title,status,client_id', 'client:id,name', 'latestSubmission.version', 'usableLink'])
            ->when($outstandingView, fn (Builder $q) => $q->whereHas('matter', fn (Builder $m) => $m->where('status', '<>', MatterStatus::Archived->value)))
            ->when($status, fn (Builder $q, string $status) => match ($status) {
                'open' => $q->whereIn('document_requests.status', DocumentRequestStatus::openValues()),
                'awaiting_client' => $q->whereIn('document_requests.status', DocumentRequestStatus::awaitingClientValues()),
                'awaiting_review' => $q->whereIn('document_requests.status', DocumentRequestStatus::awaitingReviewValues()),
                default => $q->where('document_requests.status', $status),
            })
            ->when((bool) ($filters['overdue'] ?? false), fn (Builder $q) => $q->overdue(Carbon::now()))
            ->when($filters['matter_id'] ?? null, fn (Builder $q, int|string $id) => $q->where('document_requests.matter_id', (int) $id))
            ->when($filters['client_id'] ?? null, fn (Builder $q, int|string $id) => $q->where('document_requests.client_id', (int) $id))
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $term = '%'.addcslashes($search, '%_\\').'%'; // escape LIKE wildcards
                $q->where(function (Builder $q) use ($term) {
                    $q->whereLike('document_requests.title', $term)
                        ->orWhereHas('matter', fn (Builder $m) => $m->whereLike('matter_number', $term)->orWhereLike('title', $term))
                        ->orWhereHas('client', fn (Builder $c) => $c->whereLike('name', $term));
                });
            });

        match ($sort) {
            'matter_number' => $query->join('matters', 'matters.id', '=', 'document_requests.matter_id')->orderBy('matters.matter_number', $direction),
            'due_at', 'last_submitted_at' => $query->orderByRaw("document_requests.{$sort} ".($direction === 'asc' ? 'ASC' : 'DESC').' NULLS LAST'),
            default => $query->orderBy('document_requests.'.$sort, $direction),
        };
        $query->orderByDesc('document_requests.id');

        $perPage = (int) ($filters['per_page'] ?? config('lexora.pagination.default_per_page'));

        return new DocumentRequestCollection($query->paginate($perPage)->withQueryString());
    }
}
