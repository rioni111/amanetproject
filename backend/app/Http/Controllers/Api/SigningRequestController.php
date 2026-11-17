<?php

namespace App\Http\Controllers\Api;

use App\Enums\SigningStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Signing\AdminSignatureRequest;
use App\Http\Requests\Signing\IndexSigningRequestsRequest;
use App\Http\Requests\Signing\SigningActionRequest;
use App\Http\Requests\Signing\StoreSigningRequestRequest;
use App\Http\Resources\SecureAccessLinkResource;
use App\Http\Resources\SignatureRecordResource;
use App\Http\Resources\SigningRequestCollection;
use App\Http\Resources\SigningRequestResource;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Matter;
use App\Models\SecureAccessLink;
use App\Models\SigningRequest;
use App\Services\Requests\IssuedLink;
use App\Services\Signing\SigningRequestService;
use App\Services\Signing\SigningService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Signing requests (administrators). Matter-scoped: /api/matters/{matter}/signing-requests/{signingRequest}
 * (a request of another matter, or a link of another request, is a 404). Workspace list: /api/signing-requests.
 */
class SigningRequestController extends Controller
{
    public function __construct(
        private readonly SigningRequestService $requests,
        private readonly SigningService $signing,
    ) {}

    public function library(IndexSigningRequestsRequest $request): SigningRequestCollection
    {
        return $this->list($request, SigningRequest::query());
    }

    public function index(IndexSigningRequestsRequest $request, Matter $matter): SigningRequestCollection
    {
        return $this->list($request, $matter->signingRequests()->getQuery());
    }

    public function store(StoreSigningRequestRequest $request, Matter $matter): JsonResponse
    {
        $data = $request->validated();
        [$signingRequest, $issued] = $this->requests->create($matter, $data, $request->user(), (bool) ($data['issue_link'] ?? false), $data['link_expires_in_days'] ?? null);

        return $this->withLink($signingRequest, $issued, 201);
    }

    public function show(Matter $matter, SigningRequest $signingRequest): JsonResponse
    {
        Gate::authorize('view', $matter);
        $detail = self::loadDetail($signingRequest, true);

        return response()->json([
            'data' => new SigningRequestResource($detail),
            'links' => SecureAccessLinkResource::collection($detail->getRelation('links')),
            'evidence' => $detail->signatureRecord ? new SignatureRecordResource($detail->signatureRecord) : null,
        ]);
    }

    public function evidence(Matter $matter, SigningRequest $signingRequest): SignatureRecordResource
    {
        Gate::authorize('view', $matter);
        $record = $signingRequest->signatureRecord()->with(['sourceVersion', 'signedVersion'])->first()
            ?? throw new NotFoundHttpException('This request has not been signed yet.');

        return new SignatureRecordResource($record);
    }

    public function issueLink(SigningActionRequest $request, Matter $matter, SigningRequest $signingRequest): JsonResponse
    {
        $issued = $this->requests->issueLink($matter, $signingRequest, $request->user(), $request->validated('expires_in_days'), $request->validated('lock_version'));

        return $this->withLink($signingRequest, $issued, 201);
    }

    public function rotateLink(SigningActionRequest $request, Matter $matter, SigningRequest $signingRequest): JsonResponse
    {
        $issued = $this->requests->rotateLink($matter, $signingRequest, $request->user(), $request->validated('expires_in_days'), $request->validated('lock_version'));

        return $this->withLink($signingRequest, $issued, 201);
    }

    public function revokeLink(SigningActionRequest $request, Matter $matter, SigningRequest $signingRequest, SecureAccessLink $link): JsonResponse
    {
        $link = $this->requests->revokeLink($matter, $signingRequest, $link, $request->user());

        return response()->json(['data' => new SigningRequestResource(self::loadDetail($signingRequest)), 'revoked_link' => new SecureAccessLinkResource($link)]);
    }

    public function markShared(SigningActionRequest $request, Matter $matter, SigningRequest $signingRequest): SigningRequestResource
    {
        $this->requests->markShared($matter, $signingRequest, $request->user(), $request->validated('lock_version'));

        return new SigningRequestResource(self::loadDetail($signingRequest));
    }

    public function cancel(SigningActionRequest $request, Matter $matter, SigningRequest $signingRequest): SigningRequestResource
    {
        $this->requests->cancel($matter, $signingRequest, $request->user(), $request->validated('lock_version'));

        return new SigningRequestResource(self::loadDetail($signingRequest));
    }

    public function adminSign(AdminSignatureRequest $request, Matter $matter, SigningRequest $signingRequest): SigningRequestResource
    {
        $this->signing->signAsAdmin($matter, $signingRequest, $request->signatureInput(), $request->user(),
            $request->validated('lock_version'), $request->ip(), $request->userAgent());

        return new SigningRequestResource(self::loadDetail($signingRequest));
    }

    /** "Sign PDF" on a document: create the administrator's request and sign it in one step. */
    public function signDocument(AdminSignatureRequest $request, Matter $matter, Document $document): JsonResponse
    {
        $version = DocumentVersion::query()->where('document_id', $document->id)->findOrFail($request->validated('document_version_id'));
        $signed = $this->signing->signNowAsAdmin($matter, $document, $version, $request->signatureInput(), $request->user(), $request->ip(), $request->userAgent());

        return (new SigningRequestResource(self::loadDetail($signed)))->response()->setStatusCode(201);
    }

    /** Can this version be signed now? Returns the displayed page sizes for placement, or the reason why not. */
    public function eligibility(Matter $matter, Document $document, DocumentVersion $version): JsonResponse
    {
        Gate::authorize('view', $matter);
        $pages = $this->requests->eligibility($matter, $document, $version);

        return response()->json(['data' => ['eligible' => true, 'page_count' => count($pages), 'pages' => $pages]]);
    }

    public static function loadDetail(SigningRequest $request, bool $withHistory = false): SigningRequest
    {
        return SigningRequest::query()
            ->with([
                'matter:id,matter_number,title,status,client_id',
                'client:id,name',
                'document:id,title,status,current_version_id',
                'sourceVersion',
                'signedVersion',
                'usableLink',
                'creator:id,name',
                ...($withHistory ? [
                    'signatureRecord.sourceVersion', 'signatureRecord.signedVersion',
                    'links' => fn ($q) => $q->with('creator:id,name')->orderByDesc('id')->limit(50),
                ] : []),
            ])
            ->findOrFail($request->getKey());
    }

    private function withLink(SigningRequest $signingRequest, ?IssuedLink $issued, int $status): JsonResponse
    {
        return response()->json([
            'data' => new SigningRequestResource(self::loadDetail($signingRequest)),
            'link' => $issued?->toResponse(),
        ], $status)->header('Cache-Control', 'no-store, private');
    }

    /** @param Builder<SigningRequest> $query */
    private function list(IndexSigningRequestsRequest $request, Builder $query): SigningRequestCollection
    {
        $filters = $request->validated();
        $sort = $filters['sort'] ?? 'updated_at';
        $direction = $filters['direction'] ?? ($sort === 'matter_number' ? 'asc' : 'desc');

        $query->select('signing_requests.*')
            ->with(['matter:id,matter_number,title,status,client_id', 'client:id,name', 'document:id,title,status,current_version_id', 'sourceVersion', 'usableLink'])
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $status === 'active'
                ? $q->whereIn('signing_requests.status', SigningStatus::activeValues())
                : $q->where('signing_requests.status', $status))
            ->when($filters['signer_type'] ?? null, fn (Builder $q, string $type) => $q->where('signing_requests.signer_type', $type))
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn (Builder $q) => $q->whereLike('signing_requests.signer_name', $term)
                    ->orWhereHas('document', fn (Builder $d) => $d->whereLike('title', $term))
                    ->orWhereHas('matter', fn (Builder $m) => $m->whereLike('matter_number', $term)->orWhereLike('title', $term)));
            });

        match ($sort) {
            'matter_number' => $query->join('matters', 'matters.id', '=', 'signing_requests.matter_id')->orderBy('matters.matter_number', $direction),
            'signed_at' => $query->orderByRaw('signing_requests.signed_at '.($direction === 'asc' ? 'ASC' : 'DESC').' NULLS LAST'),
            default => $query->orderBy('signing_requests.'.$sort, $direction),
        };
        $query->orderByDesc('signing_requests.id');

        return new SigningRequestCollection($query->paginate((int) ($filters['per_page'] ?? config('lexora.pagination.default_per_page')))->withQueryString());
    }
}
