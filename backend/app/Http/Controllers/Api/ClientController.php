<?php

namespace App\Http\Controllers\Api;

use App\Enums\MatterStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\IndexClientsRequest;
use App\Http\Requests\Clients\StoreClientRequest;
use App\Http\Requests\Clients\UpdateClientRequest;
use App\Http\Resources\ClientCollection;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Services\ClientService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Clients: listing, creation, detail, editing and (retention-safe) deletion.
 * A client's matters are listed through GET /api/matters?client_id=…
 */
class ClientController extends Controller
{
    public function __construct(private readonly ClientService $clients) {}

    public function index(IndexClientsRequest $request): ClientCollection
    {
        $filters = $request->validated();
        $sort = $filters['sort'] ?? 'name';
        $direction = $filters['direction'] ?? ($sort === 'name' ? 'asc' : 'desc');

        $query = Client::query()
            ->withCount([
                'matters',
                'matters as active_matters_count' => fn (Builder $q) => $q->whereIn('status', MatterStatus::activeValues()),
            ])
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $term = '%'.addcslashes($search, '%_\\').'%'; // escape LIKE wildcards
                $q->where(function (Builder $q) use ($term) {
                    $q->whereLike('name', $term)
                        ->orWhereLike('email', $term)
                        ->orWhereLike('reference_number', $term)
                        ->orWhereLike('phone', $term);
                });
            })
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('client_type', $type))
            ->orderBy($sort, $direction)
            ->orderBy('id');

        $perPage = (int) ($filters['per_page'] ?? config('lexora.pagination.default_per_page'));

        return new ClientCollection($query->paginate($perPage)->withQueryString());
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        $client = $this->clients->create($request->validated(), $request->user());

        return (new ClientResource(self::loadDetail($client)))->response()->setStatusCode(201);
    }

    public function show(Client $client): ClientResource
    {
        Gate::authorize('view', $client);

        return new ClientResource(self::loadDetail($client));
    }

    public function update(UpdateClientRequest $request, Client $client): ClientResource
    {
        $client = $this->clients->update($client, $request->validated(), $request->user());

        return new ClientResource(self::loadDetail($client));
    }

    public function destroy(Client $client): Response
    {
        Gate::authorize('delete', $client);
        $this->clients->delete($client, request()->user());

        return response()->noContent();
    }

    public static function loadDetail(Client $client): Client
    {
        return Client::query()
            ->withCount([
                'matters',
                'matters as active_matters_count' => fn (Builder $q) => $q->whereIn('status', MatterStatus::activeValues()),
                'matters as closed_matters_count' => fn (Builder $q) => $q->where('status', MatterStatus::Closed->value),
                'matters as archived_matters_count' => fn (Builder $q) => $q->where('status', MatterStatus::Archived->value),
            ])
            ->findOrFail($client->getKey());
    }
}
